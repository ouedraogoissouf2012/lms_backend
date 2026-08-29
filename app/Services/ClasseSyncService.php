<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Classe;
use App\Services\Sync\Classes\ClasseStudentsSynchronizer;
use App\Services\Sync\Classes\KlassciClassesFetcher;
use Psr\Log\LoggerInterface;

/**
 * ClasseSyncService
 *
 * Orchestrateur : synchronise les classes et étudiants depuis Klassci vers la
 * base locale. Nécessaire pour que le système de notifications puisse
 * fonctionner.
 *
 * ## Split SRP (§1.1)
 *
 * Le fichier d'origine (427 lignes, 5 méthodes) a été éclaté en 3 services :
 *   - {@see KlassciClassesFetcher}     -> fetch HTTP selon rôle
 *   - {@see ClasseStudentsSynchronizer} -> sync étudiants + pivot inscription
 *   - {@see self}                       -> orchestration + sync de la classe elle-même
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte les 2 services métier et `LoggerInterface` (PSR-3).
 * Pas de Facade `Log::` dans le code métier.
 *
 * ## API publique préservée
 *
 * Les signatures publiques sont identiques à la version pré-split :
 *   - {@see syncUserClasses()}
 *   - {@see syncClasseById()}
 *
 * Les callers existants (notamment `App\Jobs\SyncKlassciSeances`) continuent
 * de fonctionner sans modification.
 */
final class ClasseSyncService
{
    public function __construct(
        private readonly KlassciClassesFetcher $classesFetcher,
        private readonly ClasseStudentsSynchronizer $studentsSynchronizer,
        private readonly LoggerInterface $logger,
        private readonly TenantManager $tenantManager,
    ) {}

    /**
     * Synchroniser toutes les classes d'un utilisateur.
     *
     * @param  string  $klassciToken  Token Klassci de l'utilisateur
     * @param  string  $userRole  Role de l'utilisateur (etudiant, enseignant, coordinateur)
     * @return array{
     *     classes_created: int,
     *     classes_updated: int,
     *     students_synced: int,
     *     enrollments_created: int,
     *     errors: array<int, string>
     * } Statistiques de synchronisation
     */
    public function syncUserClasses(string $klassciToken, string $userRole): array
    {
        try {
            $stats = [
                'classes_created' => 0,
                'classes_updated' => 0,
                'students_synced' => 0,
                'enrollments_created' => 0,
                'errors' => [],
            ];

            // Récupérer les classes selon le rôle
            $classesData = $this->classesFetcher->fetch($klassciToken, $userRole);

            foreach ($classesData as $klasseData) {
                try {
                    // Synchroniser la classe
                    $result = $this->syncSingleClasse($klasseData);

                    if ($result['created']) {
                        $stats['classes_created']++;
                    } else {
                        $stats['classes_updated']++;
                    }

                    // Synchroniser les étudiants de cette classe
                    if (isset($klasseData['etudiants']) && is_array($klasseData['etudiants'])) {
                        $enrollmentResult = $this->studentsSynchronizer->sync(
                            $result['classe'],
                            $klasseData['etudiants'],
                            $klassciToken
                        );

                        $stats['students_synced'] += $enrollmentResult['students_synced'];
                        $stats['enrollments_created'] += $enrollmentResult['enrollments_created'];
                    }
                } catch (\Exception $e) {
                    $classeName = $klasseData['nom'] ?? $klasseData['libelle'] ?? $klasseData['id'] ?? 'Inconnue';
                    $this->logger->error('Erreur sync classe', [
                        'classe_id' => $klasseData['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors'][] = "Classe {$classeName}: {$e->getMessage()}";
                }
            }

            $this->logger->info('Synchronisation classes terminée', $stats);

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('Erreur synchronisation classes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Synchroniser une classe spécifique par son ID Klassci.
     * Utile quand on active une visio et qu'on veut s'assurer que la classe existe.
     */
    public function syncClasseById(int $klassciClasseId, string $klassciToken): ?Classe
    {
        try {
            // Récupérer les détails de la classe depuis Klassci via le fetcher.
            // On délègue l'appel HTTP au service approprié (DI strict — pas
            // d'appel direct à KlassciProxyService ici).
            $responseData = $this->classesFetcher->fetchClasseDetails($klassciToken, $klassciClasseId);

            if (! $responseData) {
                $this->logger->warning('Classe non trouvée dans Klassci', [
                    'klassci_classe_id' => $klassciClasseId,
                ]);

                return null;
            }

            // L'API retourne {data: {classe: {...}, etudiants: [...]}}
            $klasseData = $responseData['classe'] ?? $responseData;
            $etudiantsData = $responseData['etudiants'] ?? [];

            if (! isset($klasseData['id'])) {
                $this->logger->warning('Données de classe invalides', [
                    'klassci_classe_id' => $klassciClasseId,
                    'response' => $responseData,
                ]);

                return null;
            }

            // Synchroniser la classe
            $result = $this->syncSingleClasse($klasseData);

            // Synchroniser les étudiants si disponibles
            if (! empty($etudiantsData) && is_array($etudiantsData)) {
                $this->studentsSynchronizer->sync(
                    $result['classe'],
                    $etudiantsData,
                    $klassciToken
                );
            }

            return $result['classe'];
        } catch (\Exception $e) {
            $this->logger->error('Erreur sync classe par ID', [
                'klassci_classe_id' => $klassciClasseId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Synchroniser une classe (upsert local depuis les données Klassci).
     *
     * Extrait de l'ancien `syncClasse()` (protected). Méthode privée car n'est
     * appelée que depuis l'orchestrateur lui-même.
     *
     * @param  array<string, mixed>  $klasseData  Données de la classe depuis Klassci
     * @return array{classe: Classe, created: bool}
     */
    private function syncSingleClasse(array $klasseData): array
    {
        $klassciId = $klasseData['id'];

        // L'API Klassci peut retourner "name" ou "nom" ou "libelle"
        $libelle = $klasseData['libelle']
            ?? $klasseData['name']
            ?? $klasseData['nom']
            ?? 'Classe sans nom';

        // Filière ID (si c'est un objet, extraire l'ID)
        $filiereId = null;
        if (isset($klasseData['filiere'])) {
            $filiereId = is_array($klasseData['filiere'])
                ? ($klasseData['filiere']['id'] ?? null)
                : $klasseData['filiere'];
        }

        // Niveau ID (si c'est un objet, extraire l'ID)
        $niveauId = null;
        if (isset($klasseData['niveau'])) {
            $niveauId = is_array($klasseData['niveau'])
                ? ($klasseData['niveau']['id'] ?? null)
                : $klasseData['niveau'];
        }

        $institutionId = $this->tenantManager->getResolved();
        $classe = Classe::updateOrCreate(
            ['klassci_id' => $klassciId, 'institution_id' => $institutionId],
            [
                'libelle' => $libelle,
                'code' => $klasseData['code'] ?? null,
                'description' => $klasseData['description'] ?? null,
                'effectif' => $klasseData['places_totales'] ?? $klasseData['places'] ?? $klasseData['effectif'] ?? 0,
                'filiere_id' => $filiereId,
                'niveau_id' => $niveauId,
                'annee_universitaire_id' => $klasseData['annee_universitaire_id'] ?? null,
                'klassci_data' => $klasseData, // Stocker toutes les données Klassci
                'last_klassci_sync' => now(),
            ]
        );

        return [
            'classe' => $classe,
            'created' => $classe->wasRecentlyCreated,
        ];
    }
}

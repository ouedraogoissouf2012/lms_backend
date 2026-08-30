<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrateur « détails classe ». Extrait de
 * {@see \App\Http\Controllers\API\LMS\LMSClassesController::classeDetails()}.
 *
 * Agrège classe + étudiants actifs + matières + emploi du temps + évaluations
 * + stats. Blocs secondaires en dégradation gracieuse (échec amont KLASSCI →
 * bloc vide, warning logué).
 */
final class ClasseDetailsQueryService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly ClasseSecondaryDataFetcher $secondaryFetcher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Construit le payload « détails classe » pour un utilisateur authentifié.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function getDetailsForUser(int $classeId, User $user): array
    {
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            return [
                'status' => 401,
                'payload' => [
                    'success' => false,
                    'message' => MissingKlassciTokenException::CLIENT_MESSAGE,
                ],
            ];
        }

        $this->logger->info('Récupération détails classe', [
            'classe_id' => $classeId,
            'user_id' => $user->id,
        ]);

        // 1. Informations de base de la classe (avec relations filière + niveau).
        $classeResult = $this->fetchClasse($classeId, $klassciToken);
        if ($classeResult['status'] !== 200) {
            /** @var array{status: int, payload: array<string, mixed>} $classeResult */
            return $classeResult;
        }
        /** @var array<string, mixed> $classe */
        $classe = $classeResult['classe'];

        // 2. Étudiants actifs (filtre strict).
        $etudiantsActifs = $this->fetchEtudiantsActifs($classeId, $klassciToken);

        // 3. Emploi du temps de la semaine courante (dégradation gracieuse).
        $emploiTemps = $this->secondaryFetcher->fetchEmploiTempsSemaine($classeId, $klassciToken);

        // 4. Évaluations programmées pour la classe (dégradation gracieuse).
        $evaluations = $this->secondaryFetcher->fetchEvaluations($classeId, $klassciToken);

        // 5. Matières disponibles pour la combinaison filière+niveau.
        $matieres = $this->secondaryFetcher->fetchMatieres($classe, $classeId, $klassciToken);

        // 6. Statistiques agrégées.
        $stats = $this->computeStats($classe, $etudiantsActifs, $emploiTemps, $evaluations, $matieres);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => [
                    'classe' => $classe,
                    'etudiants' => $etudiantsActifs,
                    'matieres_disponibles' => $matieres,
                    'emploi_temps_semaine' => $emploiTemps,
                    'evaluations_programmees' => $evaluations,
                    'statistiques' => $stats,
                ],
            ],
        ];
    }

    /**
     * Récupère la classe + relations filière/niveau.
     *
     * Retourne soit un succès `{status: 200, classe: array}`, soit un tuple
     * d'erreur déjà prêt à être renvoyé tel quel ({@see getDetailsForUser()}).
     *
     * @return array{status: int, classe?: array<string, mixed>, payload?: array<string, mixed>}
     */
    private function fetchClasse(int $classeId, string $klassciToken): array
    {
        try {
            $classeResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}?with=filiere,niveau",
                'GET'
            );

            $classe = $classeResponse['data'] ?? null;

            $this->logger->info('Classe récupérée depuis KLASSCI', [
                'classe_id' => $classeId,
                'has_filiere' => isset($classe['filiere']),
                'filiere_id' => $classe['filiere']['id'] ?? 'N/A',
                'has_niveau' => isset($classe['niveau']),
                'niveau_id' => $classe['niveau']['id'] ?? 'N/A',
                'classe_data' => $classe,
            ]);

            if (!$classe) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Classe non trouvée',
                    ],
                ];
            }

            /** @var array<string, mixed> $classe */
            return ['status' => 200, 'classe' => $classe];
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération de la classe',
                ],
            ];
        }
    }

    /**
     * Liste des étudiants filtrée sur `statut === 'actif'` (filtre **strict** :
     * un étudiant sans champ `statut` est exclu — comportement legacy verbatim,
     * différent du filtre permissif de {@see ClasseEtudiantsQueryService}).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchEtudiantsActifs(int $classeId, string $klassciToken): array
    {
        $etudiantsResponse = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "classes/{$classeId}/etudiants",
            'GET'
        );

        /** @var array<int, array<string, mixed>> $etudiants */
        $etudiants = $etudiantsResponse['data'] ?? [];

        return collect($etudiants)
            ->filter(fn (array $etudiant): bool => $this->estEtudiantActif($etudiant, $classeId))
            ->values()
            ->all();
    }

    /**
     * Prédicat « étudiant actif » du filtre strict (issue #257).
     *
     * Le filtre reste strict — seul `statut === 'actif'` est conservé — mais on
     * distingue deux familles d'exclusion :
     *   - intentionnelle : `statut` est une chaîne valide ≠ 'actif' (ex.
     *     'inactif', 'suspendu') → exclusion silencieuse, métier nominal ;
     *   - anormale : `statut` absent / null / vide / non-string (anomalie amont
     *     KLASSCI) → l'étudiant reste exclu MAIS l'exclusion est tracée (warning
     *     structuré id étudiant + id classe), pour qu'un effectif sous-compté
     *     soit diagnosticable au lieu de disparaître sans laisser de trace.
     *
     * @param  array<string, mixed>  $etudiant
     */
    private function estEtudiantActif(array $etudiant, int $classeId): bool
    {
        $statut = $etudiant['statut'] ?? null;

        if (!is_string($statut) || $statut === '') {
            $this->logger->warning('Étudiant exclu de l\'effectif : statut manquant ou malformé', [
                'classe_id' => $classeId,
                'etudiant_id' => $etudiant['id'] ?? null,
                'statut_recu' => is_scalar($statut) ? $statut : gettype($statut),
            ]);

            return false;
        }

        return $statut === 'actif';
    }

    /**
     * Agrégation des compteurs et du taux de remplissage.
     *
     * @param  array<string, mixed>  $classe
     * @param  array<int, array<string, mixed>>  $etudiantsActifs
     * @param  array<int, array<string, mixed>>  $emploiTemps
     * @param  array<int, array<string, mixed>>  $evaluations
     * @param  array<int, array<string, mixed>>  $matieres
     * @return array<string, mixed>
     */
    private function computeStats(
        array $classe,
        array $etudiantsActifs,
        array $emploiTemps,
        array $evaluations,
        array $matieres,
    ): array {
        $capacite = $classe['nombre_places'] ?? null;
        $tauxRemplissage = null;
        if (is_numeric($capacite) && (int) $capacite > 0) {
            $tauxRemplissage = round((count($etudiantsActifs) / (int) $capacite) * 100, 2);
        }

        return [
            'nombre_etudiants' => count($etudiantsActifs),
            'nombre_seances_semaine' => count($emploiTemps),
            'nombre_evaluations_programmees' => count($evaluations),
            'nombre_matieres' => count($matieres),
            'capacite_classe' => $capacite,
            'taux_remplissage' => $tauxRemplissage,
        ];
    }
}

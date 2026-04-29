<?php

namespace App\Services;

use App\Models\Classe;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ClasseSyncService
 *
 * Service pour synchroniser les classes et étudiants depuis Klassci vers la base locale
 * Nécessaire pour que le système de notifications puisse fonctionner
 */
class ClasseSyncService
{
    protected KlassciProxyService $klassciService;

    public function __construct(KlassciProxyService $klassciService)
    {
        $this->klassciService = $klassciService;
    }

    /**
     * Synchroniser toutes les classes d'un utilisateur
     *
     * @param string $klassciToken Token Klassci de l'utilisateur
     * @param string $userRole Role de l'utilisateur (etudiant, enseignant, coordinateur)
     * @return array Statistiques de synchronisation
     */
    public function syncUserClasses(string $klassciToken, string $userRole): array
    {
        try {
            $stats = [
                'classes_created' => 0,
                'classes_updated' => 0,
                'students_synced' => 0,
                'enrollments_created' => 0,
                'errors' => []
            ];

            // Récupérer les classes selon le rôle
            $classesData = $this->fetchClassesFromKlassci($klassciToken, $userRole);

            foreach ($classesData as $klasseData) {
                try {
                    // Synchroniser la classe
                    $result = $this->syncClasse($klasseData);

                    if ($result['created']) {
                        $stats['classes_created']++;
                    } else {
                        $stats['classes_updated']++;
                    }

                    // Synchroniser les étudiants de cette classe
                    if (isset($klasseData['etudiants']) && is_array($klasseData['etudiants'])) {
                        $enrollmentResult = $this->syncClasseStudents(
                            $result['classe'],
                            $klasseData['etudiants'],
                            $klassciToken
                        );

                        $stats['students_synced'] += $enrollmentResult['students_synced'];
                        $stats['enrollments_created'] += $enrollmentResult['enrollments_created'];
                    }

                } catch (\Exception $e) {
                    $classeName = $klasseData['nom'] ?? $klasseData['libelle'] ?? $klasseData['id'] ?? 'Inconnue';
                    Log::error('Erreur sync classe', [
                        'classe_id' => $klasseData['id'] ?? null,
                        'exception_class' => get_class($e)
                    ]);
                    $stats['errors'][] = "Classe {$classeName}: {$e->getMessage()}";
                }
            }

            Log::info('Synchronisation classes terminée', $stats);
            return $stats;

        } catch (\Exception $e) {
            Log::error('Erreur synchronisation classes', [
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Récupérer les classes depuis Klassci selon le rôle
     */
    protected function fetchClassesFromKlassci(string $klassciToken, string $userRole): array
    {
        $classes = [];

        // Essayer d'abord /classes (pour coordinateurs et superAdmin)
        // Si ça échoue, essayer les endpoints spécifiques au rôle
        try {
            $response = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'classes',
                'GET'
            );

            $classes = $response['data'] ?? [];

            // Si on a des classes, récupérer les détails de chaque classe pour avoir les étudiants
            $detailedClasses = [];
            foreach ($classes as $classe) {
                try {
                    $details = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "classes/{$classe['id']}",
                        'GET'
                    );

                    // L'API retourne { "data": { "classe": {...}, "etudiants": [...] }}
                    if (isset($details['data']['classe'])) {
                        $classeDetails = $details['data']['classe'];
                        // Ajouter les étudiants à l'objet classe
                        $classeDetails['etudiants'] = $details['data']['etudiants'] ?? [];
                        $detailedClasses[] = $classeDetails;
                    } else {
                        $detailedClasses[] = $classe;
                    }
                } catch (\Exception $e) {
                    // Si détails non disponibles, garder les infos basiques
                    $detailedClasses[] = $classe;
                }
            }

            return $detailedClasses;

        } catch (\Exception $e) {
            // Si /classes échoue (403, etc.), essayer les autres endpoints
            Log::info('Endpoint /classes non accessible, essai selon rôle', [
                'role' => $userRole,
                'exception_class' => get_class($e)
            ]);
        }

        // Fallback selon le rôle
        if ($userRole === 'etudiant') {
            // Pour un étudiant : récupérer ses classes via /me/student-dashboard
            $response = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/student-dashboard',
                'GET'
            );

            $classes = $response['data']['classes'] ?? [];

        } elseif (in_array($userRole, ['enseignant', 'teacher'])) {
            // Pour un enseignant : récupérer via /me/teacher-dashboard puis détails des matières
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/teacher-dashboard',
                'GET'
            );

            $matieres = $dashboard['data']['matieres'] ?? [];
            $classesMap = [];

            foreach ($matieres as $matiere) {
                // Récupérer les détails de chaque matière pour avoir les classes
                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $classe = $matiereDetails['data']['classe'] ?? null;
                if ($classe && !isset($classesMap[$classe['id']])) {
                    $classesMap[$classe['id']] = $classe;
                }
            }

            $classes = array_values($classesMap);
        }

        return $classes;
    }

    /**
     * Synchroniser une classe
     *
     * @param array $klasseData Données de la classe depuis Klassci
     * @return array ['classe' => Classe, 'created' => bool]
     */
    protected function syncClasse(array $klasseData): array
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

        $classe = Classe::updateOrCreate(
            ['klassci_id' => $klassciId],
            [
                'libelle' => $libelle,
                'code' => $klasseData['code'] ?? null,
                'description' => $klasseData['description'] ?? null,
                'effectif' => $klasseData['places_totales'] ?? $klasseData['places'] ?? $klasseData['effectif'] ?? null,
                'filiere_id' => $filiereId,
                'niveau_id' => $niveauId,
                'annee_universitaire_id' => $klasseData['annee_universitaire_id'] ?? null,
                'klassci_data' => $klasseData, // Stocker toutes les données Klassci
                'last_klassci_sync' => now(),
            ]
        );

        return [
            'classe' => $classe,
            'created' => $classe->wasRecentlyCreated
        ];
    }

    /**
     * Synchroniser les étudiants d'une classe
     *
     * @param Classe $classe Classe locale
     * @param array $etudiantsData Données des étudiants depuis Klassci
     * @param string $klassciToken Token pour requêtes additionnelles
     * @return array Statistiques
     */
    protected function syncClasseStudents(Classe $classe, array $etudiantsData, string $klassciToken): array
    {
        $stats = [
            'students_synced' => 0,
            'enrollments_created' => 0
        ];

        foreach ($etudiantsData as $etudiantData) {
            try {
                // Vérifier si l'étudiant existe localement par klassci_id
                $student = User::where('klassci_id', $etudiantData['id'])->first();

                if (!$student) {
                    // Si pas trouvé par klassci_id, chercher par email
                    // (cas où l'étudiant existe déjà avec un email mais sans klassci_id)
                    $email = $etudiantData['email'] ?? "etudiant{$etudiantData['id']}@temp.local";
                    $student = User::where('email', $email)->where('role', 'etudiant')->first();

                    if ($student) {
                        // Mettre à jour le klassci_id de l'étudiant existant
                        $student->update(['klassci_id' => $etudiantData['id']]);
                        Log::info('Klassci ID ajouté à étudiant existant', [
                            'user_id' => $student->id,
                            'email' => $email,
                            'klassci_id' => $etudiantData['id']
                        ]);
                    } else {
                        // Créer l'étudiant local
                        // L'API peut retourner "nom_complet" ou "nom"+"prenom" séparés
                        $name = $etudiantData['nom_complet']
                            ?? ($etudiantData['nom'] ?? '') . ' ' . ($etudiantData['prenom'] ?? '')
                            ?? 'Étudiant';
                        $name = trim($name);

                        $student = User::create([
                            'klassci_id' => $etudiantData['id'],
                            'name' => $name,
                            'email' => $email,
                            'role' => 'etudiant',
                            'password' => bcrypt(bin2hex(random_bytes(16))), // Password aléatoire
                        ]);

                        Log::info('Nouvel étudiant créé', [
                            'user_id' => $student->id,
                            'klassci_id' => $etudiantData['id'],
                            'email' => $email
                        ]);
                    }

                    $stats['students_synced']++;
                }

                // Créer ou mettre à jour l'inscription (pivot)
                $institutionId = app(TenantManager::class)->id();

                $exists = DB::table('classe_etudiant')
                    ->where('classe_id', $classe->id)
                    ->where('user_id', $student->id)
                    ->when($institutionId, fn($q) => $q->where('institution_id', $institutionId))
                    ->exists();

                if (!$exists) {
                    DB::table('classe_etudiant')->insert([
                        'classe_id' => $classe->id,
                        'user_id' => $student->id,
                        'institution_id' => $institutionId,
                        'statut' => 'actif',
                        'date_inscription' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $stats['enrollments_created']++;
                } else {
                    // Mettre à jour le statut si nécessaire
                    DB::table('classe_etudiant')
                        ->where('classe_id', $classe->id)
                        ->where('user_id', $student->id)
                        ->when($institutionId, fn($q) => $q->where('institution_id', $institutionId))
                        ->update([
                            'statut' => 'actif',
                            'updated_at' => now()
                        ]);
                }

            } catch (\Exception $e) {
                Log::error('Erreur sync étudiant', [
                    'classe_id' => $classe->id,
                    'etudiant_id' => $etudiantData['id'] ?? null,
                    'exception_class' => get_class($e)
                ]);
            }
        }

        return $stats;
    }

    /**
     * Synchroniser une classe spécifique par son ID Klassci
     * Utile quand on active une visio et qu'on veut s'assurer que la classe existe
     */
    public function syncClasseById(int $klassciClasseId, string $klassciToken): ?Classe
    {
        try {
            // Récupérer les détails de la classe depuis Klassci
            $response = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$klassciClasseId}",
                'GET'
            );

            $responseData = $response['data'] ?? null;

            if (!$responseData) {
                Log::warning('Classe non trouvée dans Klassci', [
                    'klassci_classe_id' => $klassciClasseId
                ]);
                return null;
            }

            // L'API retourne {data: {classe: {...}, etudiants: [...]}}
            $klasseData = $responseData['classe'] ?? $responseData;
            $etudiantsData = $responseData['etudiants'] ?? [];

            if (!isset($klasseData['id'])) {
                Log::warning('Données de classe invalides', [
                    'klassci_classe_id' => $klassciClasseId,
                    'response' => $responseData
                ]);
                return null;
            }

            // Synchroniser la classe
            $result = $this->syncClasse($klasseData);

            // Synchroniser les étudiants si disponibles
            if (!empty($etudiantsData) && is_array($etudiantsData)) {
                $this->syncClasseStudents(
                    $result['classe'],
                    $etudiantsData,
                    $klassciToken
                );
            }

            return $result['classe'];

        } catch (\Exception $e) {
            Log::error('Erreur sync classe par ID', [
                'klassci_classe_id' => $klassciClasseId,
                'exception_class' => get_class($e)
            ]);
            return null;
        }
    }
}

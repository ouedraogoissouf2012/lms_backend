<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\SeanceDetailQueryService;
use DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioParticipantsListService — extrait verbatim de
 * `LMSVisioParticipantController::getVisioParticipants()`.
 *
 * Récupère la liste unifiée des étudiants d'une classe avec leur
 * statut de présence à la visio :
 *  1. Déconnecte automatiquement les participants inactifs (timeout 3 min)
 *  2. Charge les participations réelles (excl. observateurs/coordinateurs)
 *  3. Calcule la durée théorique de la séance via `SeanceDetailQueryService`
 *  4. Récupère la liste complète des étudiants (KLASSCI → BDD fallback)
 *  5. Délègue l'enrichissement par étudiant à `VisioParticipantsEnricher`
 *  6. Trie : présents avant absents, % décroissant, ordre alphabétique
 *  7. Calcule les statistiques agrégées
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class VisioParticipantsListService
{
    /** Durée de séance par défaut (minutes) quand `programmation` est absente. */
    private const DEFAULT_SEANCE_DURATION_MINUTES = 120;

    /** Timeout d'inactivité (minutes) après lequel un participant est marqué disconnected. */
    private const INACTIVITY_TIMEOUT_MINUTES = 3;

    public function __construct(
        private readonly SeanceDetailQueryService $seanceQuery,
        private readonly VisioParticipantsEnricher $enricher,
        private readonly VisioParticipantsStatsBuilder $statsBuilder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function list(int $seanceId, User $user): array
    {
        try {
            $visio = Seance::where('klassci_seance_id', $seanceId)->first();

            if ($visio === null) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            // 0. Déconnecter automatiquement les participants inactifs (timeout 3 minutes)
            // SYSTÈME DE TIMEOUT : Détecte les participants qui n'envoient plus de heartbeat
            // et les marque automatiquement comme déconnectés
            $disconnectedCount = ESBTPAttendance::disconnectInactiveParticipants(
                self::INACTIVITY_TIMEOUT_MINUTES,
            );
            if ($disconnectedCount > 0) {
                $this->logger->info('Participants déconnectés par timeout', [
                    'seance_id' => $seanceId,
                    'count' => $disconnectedCount,
                ]);
            }

            // 1. Récupérer les participants qui ont RÉELLEMENT rejoint (table esbtp_attendance)
            // RÈGLE PARTICIPANT FANTÔME : Exclure les observateurs (coordinateurs)
            // Les observateurs ne sont PAS affichés dans la liste visible des participants
            $actualParticipants = ESBTPAttendance::where('seance_id', $visio->id)
                ->where('is_observer', false) // EXCLUSION DES OBSERVATEURS
                ->with('user:id,name,email,klassci_id')
                ->orderBy('joined_at', 'desc')
                ->get();

            // 2. Calculer la durée théorique de la séance (depuis programmation)
            [$seanceDurationMinutes, $heureDebut, $heureFin] = $this->resolveSeanceTiming($seanceId, $user);

            // 3. Récupérer TOUS les étudiants de la classe (pour comparaison)
            $allClassStudents = $this->resolveAllClassStudents($seanceId, $user, $visio);

            // 4. Créer un index des participants réels par user_id
            $actualParticipantsById = [];
            foreach ($actualParticipants as $attendance) {
                $actualParticipantsById[$attendance->user_id] = $attendance;
            }

            // 5. Construire la liste unifiée avec statut et pourcentage
            $unifiedList = [];
            foreach ($allClassStudents as $student) {
                $unifiedList[] = $this->enricher->enrich(
                    $student,
                    $actualParticipantsById,
                    $seanceDurationMinutes,
                    $heureDebut,
                    $heureFin,
                    $visio->visio_status,
                );
            }

            // 6. Trier la liste : Présents d'abord, puis par pourcentage décroissant, puis absents
            usort($unifiedList, static function (array $a, array $b): int {
                // Présents avant absents
                if ($a['is_present'] !== $b['is_present']) {
                    return $b['is_present'] <=> $a['is_present'];
                }
                // Si tous deux présents, trier par pourcentage décroissant
                if ($a['is_present'] && $b['is_present']) {
                    return $b['percentage'] <=> $a['percentage'];
                }
                // Si tous deux absents, ordre alphabétique
                return strcmp((string) $a['nom'], (string) $b['nom']);
            });

            // 7. Calculer les statistiques globales
            $stats = $this->statsBuilder->build($unifiedList, $seanceDurationMinutes, $visio->visio_status);

            // Récupérer l'info enseignant depuis la table seances
            $teacherInfo = [
                'nom' => $visio->enseignant_nom ?? null,
                'prenom' => $visio->enseignant_prenom ?? null,
            ];

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => [
                        'students' => $unifiedList,
                        'statistics' => $stats,
                        'teacher' => $teacherInfo,
                        'seance' => [
                            'id' => $seanceId,
                            'matiere' => $visio->matiere_nom,
                            'classe_id' => $visio->klassci_classe_id,
                        ],
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération participants', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération des participants',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Calcule la durée théorique + heures début/fin depuis la programmation KLASSCI.
     *
     * @return array{0: int|float, 1: ?string, 2: ?string}
     */
    private function resolveSeanceTiming(int $seanceId, User $user): array
    {
        $seanceDurationMinutes = self::DEFAULT_SEANCE_DURATION_MINUTES;
        $heureDebut = null;
        $heureFin = null;

        try {
            $seanceArray = $this->seanceQuery->getSeanceDetailsArray($seanceId, $user);

            if ($seanceArray !== null && isset($seanceArray['seance']['programmation'])) {
                $prog = $seanceArray['seance']['programmation'];
                $heureDebut = $prog['heure_debut'] ?? null;
                $heureFin = $prog['heure_fin'] ?? null;

                if ($heureDebut && $heureFin) {
                    $debut = new DateTime($heureDebut);
                    $fin = new DateTime($heureFin);
                    $seanceDurationMinutes = ($fin->getTimestamp() - $debut->getTimestamp()) / 60;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Impossible de calculer la durée de la séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);
        }

        return [$seanceDurationMinutes, $heureDebut, $heureFin];
    }

    /**
     * Récupère la liste complète des étudiants : essai KLASSCI puis fallback BDD locale.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveAllClassStudents(int $seanceId, User $user, Seance $visio): array
    {
        $allClassStudents = [];

        try {
            $seanceArray = $this->seanceQuery->getSeanceDetailsArray($seanceId, $user);

            if ($seanceArray !== null && isset($seanceArray['participants']['students'])) {
                $allClassStudents = $seanceArray['participants']['students'];
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Impossible de récupérer la liste complète des étudiants via SeanceDetailQueryService',
                [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage(),
                ],
            );
        }

        // Fallback : Si pas d'étudiants via KLASSCI, chercher dans la BDD locale
        if (empty($allClassStudents) && $visio->klassci_classe_id) {
            $allClassStudents = $this->fetchStudentsFromLocalDb($seanceId, $visio->klassci_classe_id);
        }

        return $allClassStudents;
    }

    /**
     * Fallback local : `user_classes` JOIN `users` filtré par rôle étudiant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchStudentsFromLocalDb(int $seanceId, int|string $klassciClasseId): array
    {
        try {
            $this->logger->info('Fallback: Recherche étudiants via BDD locale', [
                'seance_id' => $seanceId,
                'classe_id' => $klassciClasseId,
            ]);

            $students = UserClass::where('klassci_classe_id', $klassciClasseId)
                ->join('users', 'user_classes.user_id', '=', 'users.id')
                ->where('users.role', 'etudiant')
                ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
                ->get();

            $allClassStudents = $students->map(function ($student): array {
                // Parser le nom complet (format: "NOM Prenom")
                $nameParts = explode(' ', (string) $student->name, 2);
                return [
                    'id' => $student->id,
                    'nom' => $nameParts[0] ?? $student->name,
                    'prenom' => $nameParts[1] ?? '',
                    'email' => $student->email,
                    'klassci_id' => $student->klassci_id,
                ];
            })->toArray();

            $this->logger->info('Fallback BDD: Étudiants trouvés', [
                'count' => count($allClassStudents),
            ]);

            return $allClassStudents;
        } catch (Throwable $e) {
            $this->logger->error('Erreur fallback BDD pour récupération étudiants', [
                'seance_id' => $seanceId,
                'classe_id' => $klassciClasseId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

}

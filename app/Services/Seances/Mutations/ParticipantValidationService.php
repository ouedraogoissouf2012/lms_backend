<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\Seance;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ParticipantValidationService — extrait verbatim de
 * `LMSSeancesMutationController::validateParticipant()`.
 *
 * Refactor du god-controller (split-4, PR #lms-seances-mutation) :
 * la logique de validation d'accès à une visio (vérification visio active,
 * rôle enseignant/coordinateur/admin, ou inscription étudiante via KLASSCI)
 * est désormais isolée dans ce service unitaire.
 *
 * Aucun changement comportemental — la signature de retour reste un tableau
 * `{ status: int, payload: array }` rendu tel quel par le controller.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class ParticipantValidationService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Valide qu'un utilisateur peut rejoindre la séance demandée.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function validate(int $seanceId, int $userIdToValidate): array
    {
        try {
            $userToValidate = User::find($userIdToValidate);

            if (!$userToValidate instanceof User) {
                return $this->fail(404, 'user_not_found');
            }

            $this->logger->info('Validation participant séance', [
                'seance_id' => $seanceId,
                'user_id' => $userIdToValidate,
                'user_role' => $userToValidate->role,
            ]);

            $visioData = Seance::where('klassci_seance_id', $seanceId)->first();

            $this->logger->info('DEBUG validateParticipant - Données visio', [
                'seance_id' => $seanceId,
                'visio_found' => $visioData ? 'oui' : 'non',
                'visio_enabled' => $visioData?->visio_enabled,
                'visio_status' => $visioData?->visio_status,
                'klassci_matiere_id' => $visioData?->klassci_matiere_id,
            ]);

            if (!$visioData || !$visioData->visio_enabled) {
                return $this->fail(403, 'visio_not_enabled', 'Visioconférence non activée pour cette séance');
            }

            if (!in_array($visioData->visio_status, ['active', 'programmee'], true)) {
                return $this->fail(403, 'visio_not_started', 'La visioconférence n\'a pas encore démarré');
            }

            if ($userToValidate->isTeacher() || $userToValidate->isCoordinator() || $userToValidate->isAdmin()) {
                $this->logger->info('DEBUG validateParticipant - Enseignant autorisé', [
                    'user_id' => $userIdToValidate,
                    'role' => $userToValidate->role,
                ]);

                return [
                    'status' => 200,
                    'payload' => [
                        'success' => true,
                        'authorized' => true,
                        'role' => ($userToValidate->isCoordinator() || $userToValidate->isAdmin()) ? 'moderator' : 'teacher',
                        'message' => 'Enseignant ou coordinateur autorisé',
                    ],
                ];
            }

            if ($userToValidate->isStudent()) {
                return $this->validateStudent($seanceId, $userToValidate, $visioData);
            }

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'authorized' => false,
                    'reason' => 'invalid_role',
                    'user_role' => $userToValidate->role,
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur validation participant', [
                'seance_id' => $seanceId,
                'user_id' => $userIdToValidate,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la validation du participant',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Vérifie qu'un étudiant est inscrit dans la classe associée à la séance.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function validateStudent(int $seanceId, User $userToValidate, Seance $visioData): array
    {
        $this->logger->info('DEBUG validateParticipant - Vérification étudiant', [
            'user_id' => $userToValidate->id,
            'user_email' => $userToValidate->email,
            'seance_id' => $seanceId,
            'matiere_id' => $visioData->klassci_matiere_id,
            'classe_id' => $visioData->klassci_classe_id,
        ]);

        try {
            $klassciUrl = env('KLASSCI_API_URL', 'https://presentation.klassci.com/api/lms');
            $classeId = null;

            if ($visioData->klassci_classe_id) {
                $classeId = $visioData->klassci_classe_id;

                $this->logger->info('DEBUG validateParticipant - Utilisation classe_id de la BDD locale', [
                    'classe_id' => $classeId,
                ]);
            } elseif ($visioData->klassci_matiere_id) {
                $matiereId = $visioData->klassci_matiere_id;

                $this->logger->info('DEBUG validateParticipant - Recherche via /matieres', [
                    'matiere_id' => $matiereId,
                ]);

                $httpClient = $this->http->timeout(30);
                if (app()->environment('local')) {
                    $httpClient = $httpClient->withoutVerifying();
                }
                $matiereResponse = $httpClient->get("{$klassciUrl}/matieres/{$matiereId}");

                $this->logger->info('DEBUG validateParticipant - Réponse /matieres', [
                    'status' => $matiereResponse->status(),
                    'success' => $matiereResponse->successful(),
                ]);

                if (!$matiereResponse->successful()) {
                    $this->logger->error('DEBUG validateParticipant - Erreur API matieres', [
                        'status' => $matiereResponse->status(),
                        'body' => $matiereResponse->body(),
                    ]);

                    return $this->fail(500, 'klassci_api_error', 'Erreur lors de la vérification des inscriptions');
                }

                $matiereData = $matiereResponse->json();
                $seancesProgrammees = $matiereData['data']['seances_programmees'] ?? [];

                $this->logger->info('DEBUG validateParticipant - Séances programmées', [
                    'count' => count($seancesProgrammees),
                    'recherche_seance_id' => $seanceId,
                ]);

                $seanceInfo = collect($seancesProgrammees)->firstWhere('id', $seanceId);

                if (!$seanceInfo) {
                    $this->logger->warning('DEBUG validateParticipant - Séance non trouvée dans les programmations', [
                        'seance_id' => $seanceId,
                        'seances_disponibles' => collect($seancesProgrammees)->pluck('id')->toArray(),
                    ]);

                    return $this->fail(403, 'seance_not_found', 'Séance non trouvée dans les programmations');
                }

                $classeId = $seanceInfo['classe_id'] ?? null;

                $this->logger->info('DEBUG validateParticipant - Séance trouvée via /matieres', [
                    'seance_id' => $seanceId,
                    'classe_id' => $classeId,
                ]);
            }

            if (!$classeId) {
                $this->logger->error('DEBUG validateParticipant - Pas de classe_id disponible', [
                    'seance_id' => $seanceId,
                    'has_matiere_id' => $visioData->klassci_matiere_id ? 'oui' : 'non',
                    'has_classe_id' => $visioData->klassci_classe_id ? 'oui' : 'non',
                ]);

                return $this->fail(403, 'no_classe_id', 'Classe non définie pour cette séance');
            }

            $httpClient2 = $this->http->timeout(30);
            if (app()->environment('local')) {
                $httpClient2 = $httpClient2->withoutVerifying();
            }
            $classesResponse = $httpClient2->get("{$klassciUrl}/classes/{$classeId}/etudiants");

            $this->logger->info('DEBUG validateParticipant - Réponse /classes/etudiants', [
                'status' => $classesResponse->status(),
                'success' => $classesResponse->successful(),
            ]);

            if (!$classesResponse->successful()) {
                $this->logger->error('DEBUG validateParticipant - Erreur API classes/etudiants', [
                    'status' => $classesResponse->status(),
                    'body' => $classesResponse->body(),
                ]);

                return $this->fail(500, 'klassci_api_error', 'Erreur lors de la vérification des inscriptions');
            }

            $classesData = $classesResponse->json();
            $enrolledStudents = $classesData['data'] ?? [];

            $this->logger->info('DEBUG validateParticipant - Étudiants inscrits', [
                'count' => count($enrolledStudents),
                'emails' => collect($enrolledStudents)->pluck('email')->toArray(),
            ]);

            $isEnrolled = collect($enrolledStudents)->contains('email', $userToValidate->email);

            $this->logger->info('DEBUG validateParticipant - Résultat vérification inscription', [
                'user_email' => $userToValidate->email,
                'classe_id' => $classeId,
                'is_enrolled' => $isEnrolled,
            ]);

            if ($isEnrolled) {
                return [
                    'status' => 200,
                    'payload' => [
                        'success' => true,
                        'authorized' => true,
                        'role' => 'student',
                        'message' => 'Étudiant inscrit dans la classe - accès autorisé',
                    ],
                ];
            }

            return $this->fail(403, 'not_enrolled', 'Vous n\'êtes pas inscrit dans cette classe');

        } catch (Throwable $e) {
            // §1.2 — Détail technique loggé server-side, message générique au client.
            $this->logger->error('DEBUG validateParticipant - Exception lors de la vérification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->fail(500, 'verification_error', 'Erreur lors de la vérification de l\'inscription.');
        }
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function fail(int $status, string $reason, ?string $message = null): array
    {
        $payload = [
            'success' => false,
            'authorized' => false,
            'reason' => $reason,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return ['status' => $status, 'payload' => $payload];
    }
}

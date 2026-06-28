<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\Seance;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Valide qu'un utilisateur peut rejoindre une visioconférence d'une séance.
 * Vérifie : visio active, rôle enseignant/coordinateur/admin direct, ou
 * inscription étudiante côté KLASSCI.
 *
 * Extrait de `LMSSeancesMutationController::validateParticipant()` (split #180).
 */
final class ParticipantValidationService
{
    /** Timeout HTTP des appels KLASSCI (secondes). */
    private const KLASSCI_HTTP_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
        private readonly Application $app,
    ) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function validate(int $seanceId, int $userIdToValidate): array
    {
        try {
            $userToValidate = User::find($userIdToValidate);

            if (!$userToValidate instanceof User) {
                return $this->fail(404, 'user_not_found');
            }

            $this->logger->info('Participant validation requested', [
                'seance_id' => $seanceId,
                'user_id' => $userIdToValidate,
                'user_role' => $userToValidate->role,
            ]);

            $visioData = Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visioData || !$visioData->visio_enabled) {
                return $this->fail(403, 'visio_not_enabled', 'Visioconférence non activée pour cette séance');
            }

            if (!in_array($visioData->visio_status, ['active', 'programmee'], true)) {
                return $this->fail(403, 'visio_not_started', 'La visioconférence n\'a pas encore démarré');
            }

            if ($userToValidate->isStaff()) {
                return [
                    'status' => 200,
                    'payload' => [
                        'success' => true,
                        'authorized' => true,
                        'role' => $userToValidate->isManager() ? 'moderator' : 'teacher',
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
            $this->logger->error('Participant validation failed', [
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
     * Résout la classe associée à la séance (via locale OU via KLASSCI /matieres)
     * puis vérifie l'inscription étudiante via KLASSCI /classes/{id}/etudiants.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function validateStudent(int $seanceId, User $userToValidate, Seance $visioData): array
    {
        try {
            $klassciUrl = $this->klassciBaseUrl();
            $classeId = $visioData->klassci_classe_id;

            if (!$classeId && $visioData->klassci_matiere_id) {
                $classeId = $this->resolveClasseIdFromMatiere(
                    $klassciUrl,
                    (int) $visioData->klassci_matiere_id,
                    $seanceId,
                );

                if ($classeId === null) {
                    return $this->fail(403, 'seance_not_found', 'Séance non trouvée dans les programmations');
                }
            }

            if (!$classeId) {
                $this->logger->error('Participant validation : no classe_id available', [
                    'seance_id' => $seanceId,
                    'has_matiere_id' => $visioData->klassci_matiere_id !== null,
                    'has_classe_id' => $visioData->klassci_classe_id !== null,
                ]);

                return $this->fail(403, 'no_classe_id', 'Classe non définie pour cette séance');
            }

            $classesResponse = $this->httpClient()
                ->get("{$klassciUrl}/classes/{$classeId}/etudiants");

            if (!$classesResponse->successful()) {
                $this->logger->error('KLASSCI API error on /classes/etudiants', [
                    'seance_id' => $seanceId,
                    'classe_id' => $classeId,
                    'status' => $classesResponse->status(),
                ]);

                return $this->fail(500, 'klassci_api_error', 'Erreur lors de la vérification des inscriptions');
            }

            $enrolledStudents = $classesResponse->json('data') ?? [];
            $isEnrolled = collect($enrolledStudents)->contains('email', $userToValidate->email);

            $this->logger->info('Participant enrollment check', [
                'seance_id' => $seanceId,
                'user_id' => $userToValidate->id,
                'classe_id' => $classeId,
                'enrolled_count' => count($enrolledStudents),
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
            $this->logger->error('Participant enrollment verification failed', [
                'seance_id' => $seanceId,
                'user_id' => $userToValidate->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->fail(500, 'verification_error', 'Erreur lors de la vérification de l\'inscription.');
        }
    }

    /**
     * Résout le classe_id depuis l'API KLASSCI /matieres/{id} (seances_programmees).
     * Retourne null si la séance n'est pas trouvée dans les programmations.
     */
    private function resolveClasseIdFromMatiere(string $klassciUrl, int $matiereId, int $seanceId): ?int
    {
        $matiereResponse = $this->httpClient()
            ->get("{$klassciUrl}/matieres/{$matiereId}");

        if (!$matiereResponse->successful()) {
            $this->logger->error('KLASSCI API error on /matieres', [
                'matiere_id' => $matiereId,
                'status' => $matiereResponse->status(),
            ]);

            return null;
        }

        $seancesProgrammees = $matiereResponse->json('data.seances_programmees') ?? [];
        $seanceInfo = collect($seancesProgrammees)->firstWhere('id', $seanceId);

        if (!$seanceInfo) {
            $this->logger->warning('Seance not found in KLASSCI programmations', [
                'matiere_id' => $matiereId,
                'seance_id' => $seanceId,
            ]);

            return null;
        }

        return isset($seanceInfo['classe_id']) ? (int) $seanceInfo['classe_id'] : null;
    }

    /**
     * Construit un client HTTP préconfiguré (timeout + SSL en local).
     */
    private function httpClient(): PendingRequest
    {
        $client = $this->http->timeout(self::KLASSCI_HTTP_TIMEOUT_SECONDS);

        if ($this->app->environment('local')) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Récupère l'URL de base KLASSCI depuis la config Laravel (jamais `env()`
     * en runtime : invalide sous `config:cache`).
     *
     * @throws RuntimeException Si `services.klassci.url` n'est pas configuré.
     */
    private function klassciBaseUrl(): string
    {
        $url = config('services.klassci.url');

        if (!is_string($url) || $url === '') {
            throw new RuntimeException(
                'KLASSCI API URL non configurée. Définir KLASSCI_API_URL dans .env.'
            );
        }

        return rtrim($url, '/');
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

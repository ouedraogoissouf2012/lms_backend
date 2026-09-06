<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
use App\Services\Visio\VisioActorAuthorization;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Valide qu'un utilisateur peut rejoindre une visio.
 * L'inscription étudiante passe par {@see ClasseAudienceSource} (#712) :
 * plus aucun HTTP KLASSCI sur ce chemin.
 */
final class ParticipantValidationService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly VisioActorAuthorization $authorization,
        private readonly ClasseAudienceSource $audience,
    ) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function validate(int $seanceId, int $userIdToValidate, User $actor): array
    {
        try {
            $institutionId = $actor->institution_id;
            if (! is_int($institutionId)) {
                return $this->fail(403, 'tenant_not_resolved');
            }

            $userToValidate = User::query()
                ->where('institution_id', $institutionId)
                ->find($userIdToValidate);

            if (! $userToValidate instanceof User) {
                return $this->fail(404, 'user_not_found');
            }

            $visioData = $this->findSeance($seanceId, $institutionId);
            if ($visioData === null) {
                return $this->fail(403, 'visio_not_enabled', 'Visioconférence non activée pour cette séance');
            }

            return $this->authorize($visioData, $actor, $userToValidate);
        } catch (Throwable $e) {
            $this->logger->error('Participant validation failed', [
                'seance_id' => $seanceId,
                'user_id' => $userIdToValidate,
                'error' => $e->getMessage(),
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
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function authorize(Seance $visioData, User $actor, User $userToValidate): array
    {
        if (! in_array($visioData->visio_status, ['active', 'programmee'], true)) {
            return $this->fail(403, 'visio_not_started', 'La visioconférence n\'a pas encore démarré');
        }

        if (! $this->authorization->canValidate($visioData, $actor, $userToValidate)) {
            return $this->fail(403, 'actor_not_authorized', 'Vous ne pouvez pas valider cette séance');
        }

        if ($userToValidate->isStaff()) {
            return $this->authorizeStaff($visioData, $userToValidate);
        }

        if ($userToValidate->isStudent()) {
            return $this->authorizeStudent($visioData, $userToValidate);
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
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function authorizeStaff(Seance $visioData, User $userToValidate): array
    {
        if ($userToValidate->isTeacher() && ! $this->authorization->teacherOwns($visioData, $userToValidate)) {
            return $this->fail(403, 'teacher_not_owner', 'Enseignant non propriétaire de la séance');
        }

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

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function authorizeStudent(Seance $visioData, User $userToValidate): array
    {
        if (! is_numeric($visioData->klassci_classe_id)) {
            return $this->fail(403, 'no_classe_id', 'Classe non définie pour cette séance');
        }

        if (! $this->audience->containsStudent($visioData, $userToValidate)) {
            return $this->fail(403, 'not_enrolled', 'Vous n\'êtes pas inscrit dans cette classe');
        }

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

    private function findSeance(int $seanceId, int $institutionId): ?Seance
    {
        $visioData = Seance::query()
            ->where('institution_id', $institutionId)
            ->where(function ($query) use ($seanceId): void {
                $query->whereKey($seanceId)
                    ->orWhere('klassci_seance_id', $seanceId);
            })
            ->first();

        if (! $visioData || ! $visioData->visio_enabled) {
            return null;
        }

        return $visioData;
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

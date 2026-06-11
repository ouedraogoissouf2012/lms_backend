<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioHeartbeatService — extrait verbatim de
 * `LMSVisioParticipantController::heartbeatVisio()`.
 *
 * Ping d'activité d'un participant connecté. Met à jour
 * `last_seen_at` sur l'entrée `esbtp_attendance` active afin que le
 * job de timeout (`ESBTPAttendance::disconnectInactiveParticipants`)
 * ne marque pas l'utilisateur comme déconnecté.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class VisioHeartbeatService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AttendanceLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function heartbeat(int $seanceId, User $user): array
    {
        try {
            // Résolution dual-ID préservée verbatim
            $visio = Seance::find($seanceId);

            if ($visio === null) {
                $visio = Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if ($visio === null) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Visio non trouvée',
                    ],
                ];
            }

            // Trouver la participation active de l'utilisateur
            $attendance = ESBTPAttendance::where('seance_id', $visio->id)
                ->where('user_id', $user->id)
                ->where('status', 'connected')
                ->orderBy('joined_at', 'desc')
                ->first();

            if ($attendance === null) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Aucune participation active trouvée',
                    ],
                ];
            }

            // Mettre à jour le heartbeat
            $this->lifecycle->heartbeat($attendance);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => [
                        'last_seen_at' => $attendance->last_seen_at?->format('Y-m-d H:i:s'),
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur heartbeat visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la mise à jour du heartbeat',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }
}

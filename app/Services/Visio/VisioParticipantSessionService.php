<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioParticipantSessionService — extrait verbatim de
 * `LMSVisioParticipantController::joinVisio()` et `leaveVisio()`.
 *
 * Gère le cycle de vie d'une session participant dans une visioconférence :
 *  - join  : crée/MAJ l'entrée `esbtp_attendance` (status=connected),
 *            marque les coordinateurs comme `is_observer=true` (règle
 *            « participant fantôme » : tracé pour l'audit mais NON
 *            affiché dans la liste visible).
 *  - leave : trouve la participation active et appelle
 *            `markAsDisconnected()` (calcule `duration_minutes`).
 *
 * Ces deux opérations sont les bornes d'état de la même machine
 * (début/fin d'une session participant) — elles vivent ensemble dans
 * un seul service cohésif.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class VisioParticipantSessionService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AttendanceLifecycleService $lifecycle,
    ) {}

    /**
     * Enregistre la participation d'un utilisateur à une visio.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function join(int $seanceId, User $user, Request $request): array
    {
        try {
            $visio = $this->resolveSeance($seanceId);

            if ($visio === null) {
                return $this->notFound('Visio non trouvée');
            }

            if ($visio->visio_status !== 'active') {
                return [
                    'status' => 400,
                    'payload' => [
                        'success' => false,
                        'message' => "La visio n'est pas active",
                    ],
                ];
            }

            // RÈGLE PARTICIPANT FANTÔME
            // Les coordinateurs sont marqués comme "observateurs" (is_observer=true)
            // Ils ne sont PAS affichés dans la liste des participants visible
            // Mais leur présence est tracée pour l'audit
            $isObserver = $user->isCoordinator();

            // Enregistrer la participation pour tous les rôles
            // Étudiants, enseignants et coordinateurs peuvent rejoindre la visio
            $attendance = ESBTPAttendance::updateOrCreate(
                [
                    'seance_id' => $visio->id,
                    'user_id' => $user->id,
                ],
                [
                    'klassci_etudiant_id' => $user->klassci_id,
                    'nom' => $user->name,
                    'prenom' => '',
                    'email' => $user->email,
                    'joined_at' => now(),
                    'last_seen_at' => now(),
                    'status' => 'connected',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'is_validated' => true,
                    'is_observer' => $isObserver,
                ]
            );

            $roleLabel = match ($user->role) {
                'coordinateur' => 'Coordinateur (observateur)',
                'enseignant' => 'Enseignant',
                default => 'Étudiant',
            };

            $this->logger->info("$roleLabel rejoint visio - participation enregistrée", [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'role' => $user->role,
                'is_observer' => $isObserver,
                'attendance_id' => $attendance->id,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Accès à la visio autorisé',
                    'data' => [
                        'visio_room_id' => $visio->visio_room_id,
                        'participants_count' => $visio->current_participants_count,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur join visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la connexion',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Marque la participation active de l'utilisateur comme déconnectée.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function leave(int $seanceId, User $user): array
    {
        try {
            $visio = $this->resolveSeance($seanceId);

            if ($visio === null) {
                return $this->notFound('Visio non trouvée');
            }

            // Trouver la participation active de l'utilisateur
            $attendance = ESBTPAttendance::where('seance_id', $visio->id)
                ->where('user_id', $user->id)
                ->where('status', 'connected')
                ->orderBy('joined_at', 'desc')
                ->first();

            if ($attendance === null) {
                return $this->notFound('Aucune participation active trouvée');
            }

            // Marquer comme déconnecté
            $this->lifecycle->disconnect($attendance);

            $this->logger->info('Participant quitté visio', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'duration_minutes' => $attendance->duration_minutes,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Déconnexion enregistrée',
                    'data' => [
                        'left_at' => $attendance->left_at?->format('Y-m-d H:i:s'),
                        'duration_minutes' => $attendance->duration_minutes,
                        'duration_formatted' => $attendance->formatted_duration,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur leave visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => "Erreur lors de l'enregistrement de la sortie",
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Résolution dual-ID : tente d'abord l'ID local, puis le klassci_seance_id.
     * Conserve le comportement legacy verbatim.
     */
    private function resolveSeance(int $seanceId): ?Seance
    {
        $visio = Seance::withConnectedParticipantsCount()->find($seanceId);

        if ($visio === null) {
            $visio = Seance::where('klassci_seance_id', $seanceId)->withConnectedParticipantsCount()->first();
        }

        return $visio;
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function notFound(string $message): array
    {
        return [
            'status' => 404,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}

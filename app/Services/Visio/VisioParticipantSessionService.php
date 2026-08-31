<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Services\Visio\VisioActorAuthorization;
use App\Services\Visio\VisioAccessTokenIssuer;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\Mutations\ParticipantValidationService;
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
        private readonly ParticipantValidationService $participantValidation,
        private readonly VisioAccessTokenIssuer $tokenIssuer,
        private readonly VisioActorAuthorization $actorAuthorization,
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

            $authorization = $this->participantValidation->validate($visio->id, $user->id, $user);
            if ($authorization['status'] !== 200
                || ($authorization['payload']['authorized'] ?? false) !== true) {
                return $authorization;
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
                    'institution_id' => $visio->institution_id,
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
                    'data' => array_merge([
                        'visio_room_id' => $visio->visio_room_id,
                        'participants_count' => $visio->current_participants_count,
                    ], $this->accessToken($visio, $user)),
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

    /**
     * Taille la cle d'entree de CETTE salle pour CET utilisateur.
     *
     * Appele uniquement apres que l'autorisation a ete etablie : un participant
     * refuse n'atteint jamais ce point, et n'obtient donc aucun jeton.
     *
     * Le statut de moderateur est decide ICI, par le serveur. Le laisser au
     * client permettrait a un eleve de se declarer professeur : il pourrait
     * alors expulser sa classe, couper le micro de l'enseignant et arreter
     * l'enregistrement.
     *
     * Une configuration absente ne fait pas echouer la participation : la
     * presence est deja enregistree, et le client est informe explicitement
     * plutot que renvoye vers une porte close sans motif.
     *
     * @return array{visio_token: string|null, visio_token_available: bool}
     */
    private function accessToken(Seance $visio, User $user): array
    {
        $room = $visio->visio_room_id;

        if (! $this->tokenIssuer->isConfigured() || ! is_string($room) || $room === '') {
            $this->logger->warning('Acces visio sans jeton : configuration Jitsi absente', [
                'seance_id' => $visio->id,
                'configure' => $this->tokenIssuer->isConfigured(),
            ]);

            return ['visio_token' => null, 'visio_token_available' => false];
        }

        return [
            'visio_token' => $this->tokenIssuer->issue(
                $room,
                $user->name,
                (string) $user->email,
                $this->actorAuthorization->canManage($visio, $user),
            ),
            'visio_token_available' => true,
        ];
    }
}

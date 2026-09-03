<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Cycle de vie d'une participation visio (ESBTPAttendance) — ex-méthodes du
 * modèle (H2 audit, §5) : déconnexion (+ calcul durée), heartbeat, timeout
 * automatique des inactifs.
 *
 * DI strict §1.6 : LoggerInterface PSR-3 injecté (remplace la Facade `\Log::`
 * de l'ancien `ESBTPAttendance::disconnectInactiveParticipants`).
 */
final class AttendanceLifecycleService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Marque le participant déconnecté + calcule `duration_minutes`.
     * Pour les timeouts, `$leftAt` doit représenter le dernier signal réel.
     */
    /**
     * L'ARRIVÉE d'un participant — le pendant de {@see self::disconnect()}.
     *
     * ## `joined_at` est l'heure d'arrivée, pas celle du dernier clic (#683)
     *
     * Sur une participation encore `connected`, c'est la MÊME session : la
     * déplacer **raccourcirait la présence mesurée**, silencieusement, dans les
     * rapports remis aux établissements. Un double-clic, un retour arrière ou
     * un second onglet suffisaient à perdre le temps écoulé.
     *
     * Après une sortie (`disconnected`), revenir est une NOUVELLE session :
     * l'heure repart, sinon la présence serait au contraire surévaluée.
     *
     * `last_seen_at` avance dans les deux cas — c'est lui, et lui seul, qui
     * porte le signal d'activité.
     *
     * @see \Tests\Feature\LMS\Visio\JoinVisioPreservesJoinedAtTest
     */
    public function record(Seance $visio, User $user, Request $request, bool $isObserver): ESBTPAttendance
    {
        $identity = [
            'seance_id' => $visio->id,
            'user_id' => $user->id,
            'institution_id' => $visio->institution_id,
        ];

        $existing = ESBTPAttendance::query()->where($identity)->first();
        $sameSession = $existing !== null && $existing->status === 'connected';

        return ESBTPAttendance::updateOrCreate($identity, [
            'klassci_etudiant_id' => $user->klassci_id,
            'nom' => $user->name,
            'prenom' => '',
            'email' => $user->email,
            'joined_at' => $sameSession ? ($existing->joined_at ?? now()) : now(),
            'last_seen_at' => now(),
            'status' => 'connected',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_validated' => true,
            'is_observer' => $isObserver,
        ]);
    }

    public function disconnect(ESBTPAttendance $attendance, ?Carbon $leftAt = null): void
    {
        $leftAt = $this->normalizeLeftAt($attendance, $leftAt ?? now());

        $attendance->status = 'disconnected';
        $attendance->left_at = $leftAt;

        if ($attendance->joined_at) {
            $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($leftAt);
        }

        $attendance->save();
    }

    /**
     * Met à jour le heartbeat (keep-alive).
     */
    public function heartbeat(ESBTPAttendance $attendance): void
    {
        $attendance->last_seen_at = now();
        $attendance->save();
    }

    /**
     * Déconnecte automatiquement les participants inactifs (heartbeat trop
     * ancien, ou jamais reçu avec `joined_at` trop ancien).
     *
     * @return int Nombre de participants déconnectés.
     */
    public function disconnectInactive(int $timeoutMinutes = 3): int
    {
        $timeoutThreshold = now()->subMinutes($timeoutMinutes);

        $inactiveParticipants = ESBTPAttendance::where('status', 'connected')
            ->where(function ($query) use ($timeoutThreshold) {
                $query->where('last_seen_at', '<', $timeoutThreshold)
                    ->orWhere(function ($q) use ($timeoutThreshold) {
                        $q->whereNull('last_seen_at')
                            ->where('joined_at', '<', $timeoutThreshold);
                    });
            })
            ->get();

        $disconnectedCount = 0;

        foreach ($inactiveParticipants as $participant) {
            $this->logger->info('Timeout détecté - Déconnexion automatique', [
                'user_id' => $participant->user_id,
                'seance_id' => $participant->seance_id,
                'last_seen_at' => $participant->last_seen_at?->toDateTimeString(),
                'joined_at' => $participant->joined_at?->toDateTimeString(),
            ]);

            $this->disconnect($participant, $this->lastActivityAt($participant));
            $disconnectedCount++;
        }

        return $disconnectedCount;
    }

    private function normalizeLeftAt(ESBTPAttendance $attendance, Carbon $leftAt): Carbon
    {
        if ($attendance->joined_at !== null && $leftAt->getTimestamp() < $attendance->joined_at->getTimestamp()) {
            return $attendance->joined_at;
        }

        return $leftAt;
    }

    private function lastActivityAt(ESBTPAttendance $attendance): ?Carbon
    {
        return $attendance->last_seen_at ?? $attendance->joined_at;
    }
}

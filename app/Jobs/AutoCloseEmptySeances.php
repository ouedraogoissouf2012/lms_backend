<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Seance;
use App\Services\Seances\AutoClose\HeartbeatHealthChecker;
use App\Services\Seances\AutoClose\Rules\AllParticipantsDisconnectedRule;
use App\Services\Seances\AutoClose\Rules\NoParticipantsRule;
use App\Services\Seances\AutoClose\Rules\TeacherDisconnectionRule;
use App\Services\Seances\AutoClose\SeanceClosingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AutoCloseEmptySeances — job thin de fermeture automatique des séances.
 *
 * Orchestre — sans logique métier — les services dédiés :
 *   1. {@see HeartbeatHealthChecker}            gate de protection
 *   2. {@see TeacherDisconnectionRule}          règle 1 (priorité 1)
 *   3. {@see AllParticipantsDisconnectedRule}   règle 2 (priorité 2)
 *   4. {@see NoParticipantsRule}                règle 3 (priorité 3)
 *   5. {@see SeanceClosingService}              mutation transactionnelle
 *
 * Signature publique préservée : le scheduler dispatch toujours le même
 * job `AutoCloseEmptySeances` sans argument. Les dépendances sont
 * injectées par le container Laravel dans `handle()` au runtime — pattern
 * standard pour les queued jobs (évite la sérialisation des services et
 * respecte la règle §1.6 D : DI strict, jamais `app()`).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Job thin orchestrateur ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (injection via container)
 * @see PRODUCTION_STANDARDS.md §1.6 S — Single Responsibility (orchestration uniquement)
 */
final class AutoCloseEmptySeances implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Nombre max de tentatives — DB-only, retry 3x sur transient. */
    public int $tries = 3;

    /** Timeout par tentative en secondes — beaucoup de séances à scanner. */
    public int $timeout = 300;

    /**
     * Backoff progressif.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * Exécute le job — itère les séances visio actives et applique
     * les règles de fermeture dans l'ordre de priorité.
     *
     * Dépendances injectées par le container Laravel.
     */
    public function handle(
        HeartbeatHealthChecker $heartbeatChecker,
        TeacherDisconnectionRule $teacherRule,
        AllParticipantsDisconnectedRule $allDisconnectedRule,
        NoParticipantsRule $noParticipantsRule,
        SeanceClosingService $closingService,
        LoggerInterface $logger,
    ): void {
        $now = Carbon::now();

        $logger->info('[AutoCloseEmptySeances] Job démarré', [
            'timestamp' => $now->toDateTimeString(),
        ]);

        // Récupérer toutes les séances actives (sans heure de fin programmée)
        $activeSeances = Seance::where('visio_active', true)
            ->whereNotNull('visio_started_at')
            ->whereNull('visio_ended_at')
            ->with('attendances.user') // Eager loading pour éviter N+1
            ->get();

        $logger->info('[AutoCloseEmptySeances] Séances actives trouvées', [
            'count' => $activeSeances->count(),
        ]);

        foreach ($activeSeances as $seance) {
            // Refresh pour éviter race conditions
            $seance->refresh();

            // Double vérification que la séance est toujours active
            if (!$seance->visio_active || $seance->visio_ended_at !== null) {
                $logger->debug('[AutoCloseEmptySeances] Séance déjà fermée', [
                    'seance_id' => $seance->id,
                ]);
                continue;
            }

            // Règle 4 (Protection) : vérifier la santé du système de heartbeat
            // avant d'évaluer toute règle de fermeture.
            if (!$heartbeatChecker->isHealthy($seance, $now)) {
                continue;
            }

            // Règle 1 (Priorité 1) : Enseignant déconnecté
            if ($teacherRule->appliesTo($seance, $now)) {
                $closingService->close($seance, 'teacher_disconnected', $now);
                continue;
            }

            // Règle 2 (Priorité 2) : Tous déconnectés
            if ($allDisconnectedRule->appliesTo($seance, $now)) {
                $closingService->close($seance, 'all_disconnected', $now);
                continue;
            }

            // Règle 3 (Priorité 3) : Aucun participant
            if ($noParticipantsRule->appliesTo($seance, $now)) {
                $closingService->close($seance, 'no_participants', $now);
                continue;
            }
        }

        $logger->info('[AutoCloseEmptySeances] Job terminé');
    }

    /**
     * Gestion des échecs du job.
     *
     * Contrairement à `handle()`, le worker Laravel appelle
     * `$command->failed($e)` sans pouvoir injecter de dépendances par le
     * container (cf. {@see \Illuminate\Queue\CallQueuedHandler::failed()}).
     * On résout le LoggerInterface via {@see app()} — c'est la seule
     * concession §1.6 D justifiée et tracée : Laravel ne nous laisse
     * pas le choix de la signature.
     */
    public function failed(Throwable $exception): void
    {
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $logger->error('[AutoCloseEmptySeances] Job échoué', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

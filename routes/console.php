<?php

use App\Jobs\ArchiveOldSeances;
use App\Jobs\AutoCloseEmptySeances;
use App\Jobs\CleanObsoleteSeances;
use App\Jobs\CleanOldEvaluations;
use App\Jobs\DetectDisconnectedParticipants;
use App\Jobs\FinalizeSeanceAttendances;
use App\Jobs\SyncKlassciSeances;
use App\Services\NotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Planifier la synchronisation des séances Klassci toutes les 5 minutes
// Détecte les nouvelles séances et les séances supprimées de Klassci
Schedule::job(new SyncKlassciSeances, 'low')
    ->everyFiveMinutes()
    ->name('sync-klassci-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier le nettoyage des séances obsolètes (supprimées dans Klassci)
// Vérifie toutes les 30 minutes si les séances locales existent encore dans Klassci
Schedule::job(new CleanObsoleteSeances, 'low')
    ->everyThirtyMinutes()
    ->name('clean-obsolete-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier l'archivage des vieilles séances tous les jours à 2h du matin
// Archive les séances > 2 semaines après leur date
Schedule::job(new ArchiveOldSeances, 'low')
    ->dailyAt('02:00')
    ->name('archive-old-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier le nettoyage des évaluations passées non effectuées
// Archive les évaluations terminées depuis 7+ jours sans aucune soumission
// Les évaluations avec au moins 1 soumission sont toujours conservées
Schedule::job(new CleanOldEvaluations, 'low')
    ->dailyAt('03:00')
    ->name('clean-old-evaluations')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier les notifications d'évaluations approchantes
// Envoie un rappel aux étudiants 24h avant chaque évaluation
Schedule::command('evaluations:notify-upcoming --hours=24')
    ->dailyAt('08:00')
    ->name('notify-upcoming-evaluations')
    ->withoutOverlapping();

// Planifier le nettoyage des anciennes notifications lues
// Supprime les notifications lues de plus de 30 jours pour alléger la base
Schedule::call(function () {
    app(NotificationService::class)->cleanupOldNotifications(30);
})
    ->weekly()
    ->sundays()
    ->at('04:00')
    ->name('cleanup-old-notifications');

// Planifier la détection des participants déconnectés (via heartbeat)
// Marque comme 'disconnected' les participants inactifs depuis 5+ minutes
// Permet de gérer les déconnexions involontaires (fermeture onglet, crash, etc.)
Schedule::job(new DetectDisconnectedParticipants, 'high')
    ->everyTwoMinutes()
    ->name('detect-disconnected-participants')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier la finalisation automatique des présences après fin des séances
// Marque comme 'disconnected' les participants des séances terminées depuis 30+ minutes
// Calcule automatiquement la durée de participation pour les oublieux
Schedule::job(new FinalizeSeanceAttendances, 'low')
    ->everyTenMinutes()
    ->name('finalize-seance-attendances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier la fermeture automatique des séances visio vides ou abandonnées (#369)
// Le job existait mais n'était PAS planifié — origine des incidents « visios
// jamais fermées » (DIAGNOSTIC_HEARTBEAT_PROBLEM.md, ANALYSE_SEANCES_OBSOLETES.md).
// Fréquence 5 min = plus petit seuil des règles de fermeture (enseignant
// déconnecté 5 min / tous déconnectés 10 min / aucun participant 30 min) :
// latence de détection max = seuil + 5 min, proportionnée sans sur-scanner.
Schedule::job(new AutoCloseEmptySeances, 'high')
    ->everyFiveMinutes()
    ->name('auto-close-empty-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Purge quotidienne du journal d'audit au-delà du seuil de rétention (#215).
// Le journal est append-only ; ceci est le seul mécanisme de suppression.
Schedule::command('audit:purge')
    ->dailyAt('03:30')
    ->name('purge-audit-logs')
    ->withoutOverlapping()
    ->onOneServer();

// Rétention RGPD des enregistrements visio (#461). L'application est
// explicite afin qu'une invocation manuelle sans --apply reste sans effet.
Schedule::command('recordings:purge --apply')
    ->dailyAt('03:45')
    ->name('purge-visio-recordings')
    ->withoutOverlapping()
    ->onOneServer();

// Battement de vie du scheduler (#369) — marqueur cache lu par
// `scheduler:healthcheck` pour détecter un cron mort en < 10 min.
// Chaque minute : c'est la granularité du cron `schedule:run` lui-même.
Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Healthcheck cPanel-safe de la queue database (#379) — pas de Redis requis.
// Échoue si failed_jobs > 0, si trop de jobs attendent, ou si le plus vieux
// job pending dépasse le seuil. Succès silencieux ; incident loggé + exit 1.
Schedule::command('queue:healthcheck --max-pending=1000 --max-age-minutes=5 --max-failed=0')
    ->everyFiveMinutes()
    ->name('queue-healthcheck')
    ->withoutOverlapping();

// Worker de queue pour mutualisé cPanel (#369) — pas de Supervisor disponible,
// donc pas de démon `queue:work` permanent. Stratégie : drainer la table `jobs`
// chaque minute puis rendre la main (--stop-when-empty).
//   --max-time=55     : borne le drain sous le tick d'1 min (l'hébergeur
//                       mutualisé tue les process longs).
//   runInBackground   : le drain ne doit pas bloquer les autres tâches du
//                       même tick `schedule:run`.
//   withoutOverlapping(10) : verrou à expiration COURTE — si le process est
//                       tué net (kill -9 hébergeur), le verrou par défaut
//                       (24 h) gèlerait la queue ; 10 min = auto-guérison.
Schedule::command('queue:work --queue=high,default,low --stop-when-empty --max-time=55')
    ->everyMinute()
    ->name('queue-worker')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

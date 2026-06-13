<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SyncKlassciSeances;
use App\Jobs\ArchiveOldSeances;
use App\Jobs\CleanObsoleteSeances;
use App\Jobs\CleanOldEvaluations;
use App\Jobs\FinalizeSeanceAttendances;
use App\Jobs\DetectDisconnectedParticipants;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Planifier la synchronisation des séances Klassci toutes les 5 minutes
// Détecte les nouvelles séances et les séances supprimées de Klassci
Schedule::job(new SyncKlassciSeances)
    ->everyFiveMinutes()
    ->name('sync-klassci-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier le nettoyage des séances obsolètes (supprimées dans Klassci)
// Vérifie toutes les 30 minutes si les séances locales existent encore dans Klassci
Schedule::job(new CleanObsoleteSeances)
    ->everyThirtyMinutes()
    ->name('clean-obsolete-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier l'archivage des vieilles séances tous les jours à 2h du matin
// Archive les séances > 2 semaines après leur date
Schedule::job(new ArchiveOldSeances)
    ->dailyAt('02:00')
    ->name('archive-old-seances')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier le nettoyage des évaluations passées non effectuées
// Archive les évaluations terminées depuis 7+ jours sans aucune soumission
// Les évaluations avec au moins 1 soumission sont toujours conservées
Schedule::job(new CleanOldEvaluations)
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
    app(\App\Services\NotificationService::class)->cleanupOldNotifications(30);
})
    ->weekly()
    ->sundays()
    ->at('04:00')
    ->name('cleanup-old-notifications');

// Planifier la détection des participants déconnectés (via heartbeat)
// Marque comme 'disconnected' les participants inactifs depuis 5+ minutes
// Permet de gérer les déconnexions involontaires (fermeture onglet, crash, etc.)
Schedule::job(new DetectDisconnectedParticipants)
    ->everyTwoMinutes()
    ->name('detect-disconnected-participants')
    ->withoutOverlapping()
    ->onOneServer();

// Planifier la finalisation automatique des présences après fin des séances
// Marque comme 'disconnected' les participants des séances terminées depuis 30+ minutes
// Calcule automatiquement la durée de participation pour les oublieux
Schedule::job(new FinalizeSeanceAttendances)
    ->everyTenMinutes()
    ->name('finalize-seance-attendances')
    ->withoutOverlapping()
    ->onOneServer();

// Purge quotidienne du journal d'audit au-delà du seuil de rétention (#215).
// Le journal est append-only ; ceci est le seul mécanisme de suppression.
Schedule::command('audit:purge')
    ->dailyAt('03:30')
    ->name('purge-audit-logs')
    ->withoutOverlapping()
    ->onOneServer();

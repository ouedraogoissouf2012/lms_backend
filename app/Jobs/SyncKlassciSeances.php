<?php

namespace App\Jobs;

use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SyncKlassciSeances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Nombre max de tentatives — HTTP KLASSCI instable, mais sync = idempotent. */
    public int $tries = 3;

    /** Timeout par tentative en secondes — sync potentiellement lourd. */
    public int $timeout = 600;

    /**
     * Backoff HTTP progressif : 1 min, 5 min, 15 min.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Execute the job.
     */
    public function handle(
        KlassciProxyService $klassciService,
        ClasseSyncService $classeSyncService,
        NotificationService $notificationService,
        LoggerInterface $logger
    ): void {
        $logger->info('Job SyncKlassciSeances démarré');

        try {
            // Récupérer tous les enseignants avec token Klassci
            $teachers = User::where('role', 'enseignant')
                ->whereNotNull('klassci_token')
                ->get();

            $stats = [
                'teachers_checked' => 0,
                'seances_found' => 0,
                'seances_new' => 0,
                'notifications_sent' => 0,
                'seances_archived' => 0,
                'errors' => 0,
            ];

            // Collecter tous les IDs de séances qui existent actuellement dans Klassci
            $activeKlassciSeanceIds = [];

            foreach ($teachers as $teacher) {
                try {
                    $stats['teachers_checked']++;

                    // Récupérer toutes les matières de l'enseignant
                    $matieres = $klassciService->requestWithUserToken(
                        $teacher->klassci_token,
                        'matieres',
                        'GET'
                    );

                    foreach ($matieres['data'] ?? [] as $matiere) {
                        // Récupérer les séances de chaque matière
                        $details = $klassciService->requestWithUserToken(
                            $teacher->klassci_token,
                            "matieres/{$matiere['id']}",
                            'GET'
                        );

                        $seances = $details['data']['seances_programmees'] ?? [];
                        $stats['seances_found'] += count($seances);

                        foreach ($seances as $seanceKlassci) {
                            try {
                                // Ajouter cet ID à la liste des séances actives dans Klassci
                                $activeKlassciSeanceIds[] = $seanceKlassci['id'];

                                // Vérifier si la séance existe déjà localement
                                $seanceLocal = Seance::where('klassci_seance_id', $seanceKlassci['id'])->first();

                                if (! $seanceLocal) {
                                    // Nouvelle séance découverte!
                                    $stats['seances_new']++;

                                    $logger->info('Nouvelle séance Klassci détectée par le job', [
                                        'seance_id' => $seanceKlassci['id'],
                                        'matiere' => $matiere['nom'] ?? 'N/A',
                                        'teacher_id' => $teacher->id,
                                    ]);

                                    // Créer la séance locale
                                    $seanceLocal = Seance::create([
                                        'klassci_seance_id' => $seanceKlassci['id'],
                                        'klassci_matiere_id' => $matiere['id'],
                                        'klassci_classe_id' => $seanceKlassci['classe']['id'] ?? null,
                                        'klassci_enseignant_id' => $teacher->klassci_id,
                                        'enseignant_nom' => $teacher->name,
                                        'matiere_nom' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
                                        'visio_enabled' => true,  // Toutes les séances = visio
                                        'visio_type' => 'jitsi',
                                        'visio_status' => 'programmee',
                                        'visio_room_id' => SecureVisioRoomIdGenerator::make(),
                                        'visio_active' => false,
                                        'created_by' => $teacher->id,
                                    ]);

                                    // Synchroniser la classe pour avoir les étudiants
                                    if (isset($seanceKlassci['classe']['id'])) {
                                        $classeSyncService->syncClasseById(
                                            $seanceKlassci['classe']['id'],
                                            $teacher->klassci_token
                                        );
                                    }

                                    // Envoyer les notifications
                                    $count = $notificationService->notifyVisioScheduled($seanceKlassci['id'], [
                                        'klassci_classe_id' => $seanceKlassci['classe']['id'] ?? null,
                                        'klassci_enseignant_id' => $teacher->klassci_id,
                                        'matiere_nom' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
                                        'enseignant_nom' => $teacher->name,
                                    ]);

                                    $stats['notifications_sent'] += $count;

                                    $logger->info('Notifications envoyées par le job', [
                                        'seance_id' => $seanceKlassci['id'],
                                        'notifications_count' => $count,
                                    ]);
                                }

                            } catch (\Exception $e) {
                                $stats['errors']++;
                                $logger->error('Erreur traitement séance dans job', [
                                    'seance_id' => $seanceKlassci['id'] ?? 'unknown',
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

                } catch (\Exception $e) {
                    $stats['errors']++;
                    $logger->error('Erreur traitement enseignant dans job', [
                        'teacher_id' => $teacher->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Archiver les séances qui n'existent plus dans Klassci
            // (seulement si on a bien collecté des IDs pour éviter d'archiver tout par erreur)
            if (! empty($activeKlassciSeanceIds)) {
                $archivedSeances = Seance::where('is_active', true)
                    ->whereNotNull('klassci_seance_id')
                    ->whereNotIn('klassci_seance_id', $activeKlassciSeanceIds)
                    ->get();

                foreach ($archivedSeances as $seance) {
                    $seance->update([
                        'is_active' => false,
                        'archived_at' => now(),
                        'archive_reason' => 'supprimee_klassci',
                    ]);

                    $stats['seances_archived']++;

                    $logger->info('Séance archivée (supprimée de Klassci)', [
                        'seance_id' => $seance->id,
                        'klassci_seance_id' => $seance->klassci_seance_id,
                        'matiere' => $seance->matiere_nom,
                    ]);
                }
            }

            $logger->info('Job SyncKlassciSeances terminé', $stats);

        } catch (\Exception $e) {
            $logger->error('Erreur fatale dans job SyncKlassciSeances', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Re-throw pour permettre au retry mechanism de fonctionner.
            throw $e;
        }
    }

    /**
     * Job échoué après toutes les tentatives.
     */
    public function failed(\Throwable $exception): void
    {
        // Pattern AutoCloseEmptySeances (#209) : failed() est appelée hors
        // container (aucune injection possible) — résolution explicite.
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $logger->error('[SyncKlassciSeances] Job failed after all retries', [
            'tries' => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}

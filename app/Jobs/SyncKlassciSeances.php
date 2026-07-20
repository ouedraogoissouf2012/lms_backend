<?php

namespace App\Jobs;

use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
use App\Services\Seances\KlassciPayload;
use App\Services\Seances\SeanceProgrammationNormalizer;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Carbon\Carbon;
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

            // Collecter les IDs de séances actives dans KLASSCI, GROUPÉS PAR
            // INSTITUTION (#473). L'archivage doit se faire tenant par tenant :
            // un ID actif chez l'institution A ne doit pas empêcher — ni provoquer —
            // l'archivage de la séance homonyme de l'institution B.
            // @var array<int, array<int, int>> $activeIdsByInstitution
            $activeIdsByInstitution = [];

            foreach ($teachers as $teacher) {
                try {
                    $stats['teachers_checked']++;

                    // Récupérer toutes les matières de l'enseignant
                    $matieres = $klassciService->requestWithUserToken(
                        $teacher->klassci_token,
                        'matieres',
                        'GET'
                    );

                    $matieresList = KlassciPayload::listOfArrays(
                        KlassciPayload::asArray($matieres)['data'] ?? null
                    );
                    foreach ($matieresList as $matiere) {
                        $matiereId = KlassciPayload::toInt($matiere['id'] ?? null);
                        if ($matiereId === null || ! is_string($teacher->klassci_token)) {
                            continue;
                        }

                        // Récupérer les séances de chaque matière
                        $details = $klassciService->requestWithUserToken(
                            $teacher->klassci_token,
                            "matieres/{$matiereId}",
                            'GET'
                        );

                        $seances = KlassciPayload::listOfArrays(
                            KlassciPayload::asArray(
                                KlassciPayload::asArray($details)['data'] ?? null
                            )['seances_programmees'] ?? null
                        );
                        $stats['seances_found'] += count($seances);

                        foreach ($seances as $seanceArr) {
                            try {
                                $klassciSeanceId = KlassciPayload::toInt($seanceArr['id'] ?? null);
                                if ($klassciSeanceId === null) {
                                    continue;
                                }

                                // Ajouter cet ID à la liste des séances actives de
                                // l'institution de cet enseignant (#473).
                                $activeIdsByInstitution[$teacher->institution_id][] = $klassciSeanceId;

                                // Vérifier si la séance existe déjà localement.
                                // #473 : ce job tourne hors requête HTTP, donc le scope
                                // global `institution` est inerte. On scope EXPLICITEMENT
                                // par l'institution de l'enseignant pour ne jamais matcher
                                // (ni écraser) la séance homonyme d'un autre tenant.
                                $seanceLocal = Seance::withoutGlobalScope('institution')
                                    ->where('institution_id', $teacher->institution_id)
                                    ->where('klassci_seance_id', $klassciSeanceId)
                                    ->first();
                                $cacheData = $this->localCacheData($seanceArr, $matiere, $teacher);

                                if (! $seanceLocal) {
                                    // Nouvelle séance découverte!
                                    $stats['seances_new']++;
                                    $classeId = KlassciPayload::toInt(
                                        KlassciPayload::asArray($seanceArr['classe'] ?? null)['id'] ?? null
                                    );
                                    $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? null;

                                    $logger->info('Nouvelle séance Klassci détectée par le job', [
                                        'seance_id' => $klassciSeanceId,
                                        'matiere' => $matiereNom ?? 'N/A',
                                        'teacher_id' => $teacher->id,
                                    ]);

                                    // Créer la séance locale
                                    $seanceLocal = Seance::create($cacheData + [
                                        'klassci_seance_id' => $klassciSeanceId,
                                        'visio_enabled' => true,  // Toutes les séances = visio
                                        'visio_type' => 'jitsi',
                                        'visio_status' => 'programmee',
                                        'visio_room_id' => SecureVisioRoomIdGenerator::make(),
                                        'visio_active' => false,
                                        'created_by' => $teacher->id,
                                    ]);

                                    // Synchroniser la classe pour avoir les étudiants.
                                    // klassci_token est déjà garanti non-null (continue plus haut).
                                    if ($classeId !== null) {
                                        $classeSyncService->syncClasseById($classeId, $teacher->klassci_token);
                                    }

                                    // Envoyer les notifications
                                    // #473 : institution_id transmis pour que la
                                    // résolution de l'audience (classe + étudiants +
                                    // enseignant) soit scopée au bon tenant — le job
                                    // tourne sans tenant résolu, le scope global est inerte.
                                    $count = $notificationService->notifyVisioScheduled($klassciSeanceId, [
                                        'institution_id' => $teacher->institution_id,
                                        'klassci_classe_id' => $classeId,
                                        'klassci_enseignant_id' => $teacher->klassci_id,
                                        'matiere_nom' => $matiereNom,
                                        'enseignant_nom' => $teacher->name,
                                    ]);

                                    $stats['notifications_sent'] += $count;

                                    $logger->info('Notifications envoyées par le job', [
                                        'seance_id' => $klassciSeanceId,
                                        'notifications_count' => $count,
                                    ]);
                                } else {
                                    $this->updateLocalCacheData($seanceLocal, $cacheData);
                                }

                            } catch (\Exception $e) {
                                $stats['errors']++;
                                $logger->error('Erreur traitement séance dans job', [
                                    'seance_id' => $seanceArr['id'] ?? 'unknown',
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

            // Archiver les séances qui n'existent plus dans Klassci, TENANT PAR
            // TENANT (#473). On n'archive que dans les institutions réellement
            // synchronisées (celles ayant au moins un ID actif collecté), pour ne
            // jamais toucher aux séances d'une institution absente de ce run.
            // Chaque entrée du map contient au moins un ID (la clé n'est créée
            // qu'au moment d'un push), donc pas de garde « liste vide » nécessaire.
            foreach ($activeIdsByInstitution as $institutionId => $activeIds) {
                $archivedSeances = Seance::withoutGlobalScope('institution')
                    ->where('institution_id', $institutionId)
                    ->where('is_active', true)
                    ->whereNotNull('klassci_seance_id')
                    ->whereNotIn('klassci_seance_id', $activeIds)
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
                        'institution_id' => $institutionId,
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
     * @param array<string, mixed> $seanceKlassci
     * @param array<string, mixed> $matiere
     * @return array<string, mixed>
     */
    private function localCacheData(array $seanceKlassci, array $matiere, User $teacher): array
    {
        $prog = KlassciPayload::asArray($seanceKlassci['programmation'] ?? null);
        $classe = KlassciPayload::asArray($seanceKlassci['classe'] ?? null);
        $date = KlassciPayload::toStringOrNull($prog['date'] ?? null);
        $heureDebut = SeanceProgrammationNormalizer::alignDate(
            KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null),
            $date
        );

        return [
            // #473 : institution_id écrit EXPLICITEMENT (scope global inerte en job).
            // Garantit l'isolation tenant et rend le composite unique effectif.
            'institution_id' => $teacher->institution_id,
            'klassci_matiere_id' => KlassciPayload::toInt($matiere['id'] ?? null),
            'klassci_classe_id' => KlassciPayload::toInt($classe['id'] ?? null),
            'klassci_enseignant_id' => $teacher->klassci_id,
            'enseignant_nom' => $teacher->name,
            'matiere_nom' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
            'classe_nom' => KlassciPayload::toStringOrNull($classe['nom'] ?? null),
            'titre' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
            'date_seance' => $this->parseSeanceStart($heureDebut, $date),
        ];
    }

    /**
     * @param array<string, mixed> $cacheData
     */
    private function updateLocalCacheData(Seance $seance, array $cacheData): void
    {
        $updates = array_filter($cacheData, static fn ($value): bool => $value !== null);
        if ($updates === []) {
            return;
        }

        $seance->fill($updates);
        if ($seance->isDirty()) {
            $seance->save();
        }
    }

    private function parseSeanceStart(?string $heureDebut, ?string $date): ?Carbon
    {
        if ($heureDebut !== null) {
            try {
                return Carbon::parse($heureDebut);
            } catch (\Throwable) {
                // Fallback sur la date seule ci-dessous.
            }
        }

        if ($date !== null) {
            try {
                return Carbon::parse($date)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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

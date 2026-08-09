<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * #538 — Fan-out ASYNCHRONE des notifications de publication d'une leçon.
 *
 * Avant : `LessonCrudOperationsService::dispatchPublicationNotifications`
 * exécutait 1+N GET HTTP KLASSCI (5 s de timeout chacun), puis N `->first()`
 * et N INSERT, **de façon synchrone dans la requête** `POST /lessons/{id}/publish`.
 * Sur une grosse promo, le worker PHP-FPM restait bloqué plusieurs dizaines de
 * secondes (KLASSCI 5 s × N classes).
 *
 * Désormais la requête ne fait que dispatcher ce job. Le job :
 *  - repose le tenant du demandeur (le worker n'exécute PAS ResolveInstitution :
 *    sans `set()`, le global scope BelongsToInstitution devient no-op → fuite ou
 *    écriture cross-tenant — même cause-racine que #536) ;
 *  - parallélise les N GET classes via {@see \App\Services\Klassci\KlassciBatchFetcher}
 *    (Http::pool) au lieu de N appels séquentiels bloquants ;
 *  - résout les étudiants locaux en UNE requête (`whereIn`) au lieu de N `first()` ;
 *  - insère les notifications en lot.
 *
 * Best-effort (comme le comportement pré-#538) : toute erreur KLASSCI/DB est
 * logguée sans être relancée, et `$tries = 1` évite les doublons qu'un retry
 * après insertion partielle produirait. DETTE tracée : sur échec transitoire,
 * certains étudiants peuvent ne pas être notifiés (choix assumé vs risque de
 * doublons ; une version idempotente reste possible ultérieurement).
 *
 * @see app/Services/Lesson/LessonCrudOperationsService.php (dispatcher)
 * @see app/Jobs/GenerateReportPdf.php (#536 — même pattern tenant en job)
 */
final class DispatchLessonPublishedNotifications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Best-effort : pas de retry (éviter les doublons après insertion partielle). */
    public int $tries = 1;

    /** #539 — rester dans le budget de drain (`queue:work --max-time=55`). */
    public int $timeout = 50;

    public function __construct(
        public readonly int $lessonId,
        public readonly int $matiereId,
        public readonly string $lessonTitle,
        public readonly int $institutionId,
    ) {
    }

    public function handle(
        KlassciProxyService $klassci,
        TenantManager $tenantManager,
        LoggerInterface $logger,
    ): void {
        // Purge un tenant résiduel d'un job précédent (worker persistant), puis
        // repose le tenant du demandeur — sinon les requêtes ci-dessous ne sont
        // pas scopées (cf. #536).
        $tenantManager->reset();

        try {
            $institution = Institution::find($this->institutionId);
            if ($institution === null) {
                $logger->warning('Fan-out publication : institution introuvable', [
                    'lesson_id' => $this->lessonId,
                    'institution_id' => $this->institutionId,
                ]);

                return;
            }
            $tenantManager->set($institution);

            $classeIds = $this->resolveClasseIds($klassci);
            if ($classeIds === []) {
                return;
            }

            $klassciStudentIds = $this->resolveStudentKlassciIds($klassci, $classeIds);
            if ($klassciStudentIds === []) {
                return;
            }

            // UNE requête DB (`whereIn`) au lieu de N `->first()`. Scopée tenant par
            // le global scope actif (`set()` ci-dessus).
            $userIds = $this->toIntIds(
                User::whereIn('klassci_id', $klassciStudentIds)->pluck('id')->all(),
            );

            if ($userIds === []) {
                return;
            }

            $this->insertNotifications($userIds);
        } catch (Throwable $e) {
            // Best-effort : la publication reste acquise (comportement pré-#538).
            $logger->warning(
                'Erreur lors du fan-out des notifications de publication: ' . $e->getMessage(),
                ['lesson_id' => $this->lessonId],
            );
        } finally {
            // Ne pas fuiter le tenant vers le prochain job du worker.
            $tenantManager->reset();
        }
    }

    /**
     * 1 GET matière (token système, caché) → liste des `classe_ids`.
     *
     * @return array<int, int>
     */
    private function resolveClasseIds(KlassciProxyService $klassci): array
    {
        $matiereData = $klassci->get("/matieres/{$this->matiereId}");
        $data = $matiereData['data'] ?? null;
        $classeIds = is_array($data) ? ($data['classe_ids'] ?? null) : null;

        if (! is_array($classeIds)) {
            return [];
        }

        return $this->toIntIds($classeIds);
    }

    /**
     * N GET classes EN PARALLÈLE (Http::pool via batch fetcher, token système)
     * → union dédupliquée des `etudiant_ids`.
     *
     * @param  array<int, int>  $classeIds
     * @return array<int, int>
     */
    private function resolveStudentKlassciIds(KlassciProxyService $klassci, array $classeIds): array
    {
        $classesMap = $klassci->fetchManyByEndpoint($classeIds, 'classes/{id}');

        $studentIds = [];
        foreach ($classesMap as $classe) {
            $data = $classe['data'] ?? null;
            $ids = is_array($data) ? ($data['etudiant_ids'] ?? null) : null;
            if (is_array($ids)) {
                $studentIds = array_merge($studentIds, $this->toIntIds($ids));
            }
        }

        return array_values(array_unique($studentIds));
    }

    /**
     * Insertion groupée des notifications (au lieu de N `create()`).
     *
     * @param  array<int, int>  $userIds
     */
    private function insertNotifications(array $userIds): void
    {
        $now = now();
        $data = json_encode(
            ['lesson_id' => $this->lessonId, 'matiere_id' => $this->matiereId],
            JSON_THROW_ON_ERROR,
        );
        $message = 'Un nouveau cours "' . $this->lessonTitle . '" est maintenant disponible';

        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'institution_id' => $this->institutionId,
                'type' => Notification::TYPE_LESSON_PUBLISHED,
                'title' => 'Nouveau cours disponible',
                'message' => $message,
                'data' => $data,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Notification::insert($rows);
    }

    /**
     * Convertit une liste de valeurs (JSON KLASSCI / colonnes DB) en IDs entiers,
     * en ignorant toute valeur non numérique (robustesse type mixte).
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int, int>
     */
    private function toIntIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}

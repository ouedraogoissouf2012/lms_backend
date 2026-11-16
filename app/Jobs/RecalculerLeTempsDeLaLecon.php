<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\Chapter\ChapterTimeRecorder;
use App\Services\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * #1146 — recalcule le temps d'une leçon quand son périmètre change.
 *
 * `lesson_progress.time_spent_minutes` somme le temps des chapitres ACTIFS de la
 * leçon. La mise à la corbeille d'un chapitre, sa restauration et la purge d'un
 * chapitre d'enregistrement changent ce périmètre : chacune envoie cette tâche,
 * après son commit.
 *
 * ## Une tâche, et non un recalcul synchrone
 *
 * Par élève, un recalcul coûte une transaction et 4 à 5 requêtes
 * ({@see ChapterTimeRecorder::recalculateLesson()}). Une leçon sert une classe :
 * au pire, plusieurs centaines d'élèves (216 en production, M7). La purge de
 * rétention tourne dans une transaction qui verrouille l'enregistrement ; y loger
 * ces transactions allongerait ce verrou et croiserait les écritures de temps
 * des élèves. Le recalcul est idempotent : la tâche peut être rejouée.
 *
 * ## Seuls les élèves qui ont DÉJÀ une progression
 *
 * `recalculateLesson()` crée une ligne `not_started` pour un élève qui n'en a
 * pas (#1147). La tâche ne parcourt donc que les lignes `lesson_progress` qui
 * existent, par lots de clés.
 *
 * ## Le tenant de la LEÇON
 *
 * Un worker n'exécute pas ResolveInstitution. La tâche pose le tenant de la
 * leçon : les lectures scopées de `recalculateLesson()` portent alors sur la
 * bonne école. En sortant, elle REMET le tenant d'avant : nul pour un worker
 * (rien ne fuit vers la tâche suivante), celui de la requête quand la file est
 * synchrone (la requête garde son école).
 */
final class RecalculerLeTempsDeLaLecon implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Idempotente : rejouer ne fausse rien. */
    public int $tries = 3;

    /** Rester dans le budget de drain (`queue:work --max-time=55`, #539). */
    public int $timeout = 50;

    private const LOT = 200;

    public function __construct(public readonly int $lessonId) {}

    public function handle(ChapterTimeRecorder $temps, TenantManager $tenants): void
    {
        $precedent = $tenants->get();
        $tenants->reset();

        try {
            $ecole = $this->ecoleDeLaLecon();
            if ($ecole === null) {
                return;
            }
            $tenants->set($ecole);

            /** @param Collection<int, LessonProgress> $lignes */
            $recalculer = function (Collection $lignes) use ($temps): void {
                foreach ($lignes as $ligne) {
                    $temps->recalculateLesson((int) $ligne->user_id, $this->lessonId);
                }
            };

            LessonProgress::query()->withoutGlobalScope('institution')
                ->where('lesson_id', $this->lessonId)
                ->select(['id', 'user_id'])
                ->chunkById(self::LOT, $recalculer);
        } finally {
            $tenants->reset();
            if ($precedent !== null) {
                $tenants->set($precedent);
            }
        }
    }

    private function ecoleDeLaLecon(): ?Institution
    {
        $institutionId = Lesson::query()->withoutGlobalScope('institution')->withTrashed()
            ->whereKey($this->lessonId)->value('institution_id');

        return $institutionId === null ? null : Institution::find($institutionId);
    }
}

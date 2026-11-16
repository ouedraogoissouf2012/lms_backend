<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\RecalculerLeTempsDeLaLecon;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\Chapter\ChapterTrashService;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1146 — le temps d'une leçon suit son périmètre.
 *
 * `lesson_progress.time_spent_minutes` somme le temps des chapitres ACTIFS de
 * la leçon. Trois gestes changent ce périmètre : la mise à la corbeille d'un
 * chapitre, sa restauration, et la suppression définitive d'un chapitre
 * d'enregistrement par la purge de rétention. Chacun envoie, après son commit,
 * une tâche qui recalcule l'agrégat des élèves ayant DÉJÀ une progression sur
 * la leçon. Elle n'en crée aucune pour les autres (le défaut de #1147).
 *
 * La tâche repose le tenant de la LEÇON : un worker n'a pas de requête, et un
 * contexte résiduel d'une autre école masquerait les lignes. Le test se place
 * donc dans le contexte d'une AUTRE école, vide les instances scopées avant
 * `handle()`, et lit sans le scope de tenant.
 */
final class RecalculDuTempsDeLaLeconTest extends TestCase
{
    use RefreshDatabase;

    private Institution $ecole;

    private Lesson $lecon;

    private Chapter $ch1;

    private User $eleve;

    private User $inscritSansProgression;

    /**
     * @return array<string, array{bool}>
     */
    public static function volets(): array
    {
        return ['école KLASSCI' => [false], 'école autonome' => [true]];
    }

    #[DataProvider('volets')]
    public function test_la_corbeille_et_la_restauration_envoient_le_recalcul(bool $autonome): void
    {
        $this->ecole($autonome);
        Queue::fake();

        app(ChapterTrashService::class)->trash($this->ch1->id);
        app(ChapterTrashService::class)->restore($this->ch1->id);

        Queue::assertPushed(
            RecalculerLeTempsDeLaLecon::class,
            fn (RecalculerLeTempsDeLaLecon $tache): bool => $tache->lessonId === $this->lecon->id,
        );
        Queue::assertPushedTimes(RecalculerLeTempsDeLaLecon::class, 2);
    }

    public function test_la_purge_d_un_chapitre_d_enregistrement_envoie_le_recalcul(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');
        $ecole = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($ecole)->create();
        $chapitre = Chapter::factory()->forInstitution($ecole)->create([
            'notes_enseignant' => ['source' => 'visio_recording', 'seance_id' => $seance->id],
        ]);
        $enregistrement = SeanceRecording::factory()->forSeance($seance)->create([
            'chapter_id' => $chapitre->id,
            'status' => SeanceRecordingStatus::Ready,
            'provider_recording_id' => 'provider-'.$seance->id,
            'recording_url' => 'https://provider.example.test/recording.mp4',
            'processed_at' => now()->subDays(400),
        ]);
        Queue::fake();

        $purge = app(SeanceRecordingRetentionService::class)->purge($enregistrement, now());

        self::assertTrue($purge, 'Le chapitre engendré par l\'enregistrement doit être purgé.');
        Queue::assertPushed(
            RecalculerLeTempsDeLaLecon::class,
            static fn (RecalculerLeTempsDeLaLecon $tache): bool => $tache->lessonId === (int) $chapitre->lesson_id,
        );
        Carbon::setTestNow();
    }

    #[DataProvider('volets')]
    public function test_la_tache_recalcule_sans_creer_de_ligne(bool $autonome): void
    {
        $this->ecole($autonome);
        self::assertSame(15, $this->minutes($this->eleve));

        $this->ch1->delete();
        $this->executerDansUneAutreEcole();

        self::assertSame(5, $this->minutes($this->eleve), 'Le chapitre à la corbeille ne compte plus.');
        self::assertNull($this->minutes($this->inscritSansProgression), 'Aucune ligne pour un élève sans progression (#1147).');

        $this->ch1->restore();
        $this->executerDansUneAutreEcole();

        self::assertSame(15, $this->minutes($this->eleve), 'Le chapitre restauré compte de nouveau.');
    }

    /**
     * La tâche, exécutée comme par un worker, mais depuis le contexte résiduel
     * d'une autre école : elle doit reposer le tenant de la leçon.
     */
    private function executerDansUneAutreEcole(): void
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->set(Institution::factory()->create());

        $this->app->call([new RecalculerLeTempsDeLaLecon($this->lecon->id), 'handle']);
    }

    private function ecole(bool $autonome): void
    {
        $factory = Institution::factory();
        $this->ecole = $autonome ? $factory->standalone()->create() : $factory->create();
        $this->lecon = Lesson::factory()->published()->create(['institution_id' => $this->ecole->id]);
        $this->ch1 = Chapter::factory()->forInstitution($this->ecole)->create(['lesson_id' => $this->lecon->id, 'order' => 0]);
        $ch2 = Chapter::factory()->forInstitution($this->ecole)->create(['lesson_id' => $this->lecon->id, 'order' => 1]);
        $this->eleve = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'etudiant']);
        $this->inscritSansProgression = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'etudiant']);

        // 600 s sur le chapitre 1, 300 s sur le chapitre 2 : 15 minutes.
        foreach ([[$this->ch1, 600], [$ch2, 300]] as [$chapitre, $secondes]) {
            DB::table('chapter_progress')->insert([
                'user_id' => $this->eleve->id, 'chapter_id' => $chapitre->id, 'institution_id' => $this->ecole->id,
                'time_spent_seconds' => $secondes, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('lesson_progress')->insert([
            'user_id' => $this->eleve->id, 'lesson_id' => $this->lecon->id, 'institution_id' => $this->ecole->id,
            'time_spent_minutes' => 15, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function minutes(User $eleve): ?int
    {
        $ligne = LessonProgress::query()->withoutGlobalScope('institution')
            ->where('user_id', $eleve->id)->where('lesson_id', $this->lecon->id)->first();

        return $ligne === null ? null : (int) $ligne->time_spent_minutes;
    }
}

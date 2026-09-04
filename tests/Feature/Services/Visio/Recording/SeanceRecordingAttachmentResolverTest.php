<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Visio\Recording;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingAttachmentGuard;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class SeanceRecordingAttachmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($this->institution);
    }

    public function test_ready_recording_is_attached_to_matching_lesson_as_video_chapter(): void
    {
        [$seance, $lesson] = $this->makeResolvableSeanceAndLesson();

        $result = $this->resolver()->attachReadyRecording(
            $seance,
            'https://cdn.example.test/recordings/seance-501.mp4',
            provider: 's3',
        );

        self::assertTrue($result->success);
        self::assertTrue($result->lesson?->is($lesson));
        self::assertSame('attached', $result->reason);

        $chapter = $result->chapter;
        self::assertInstanceOf(Chapter::class, $chapter);
        self::assertSame($lesson->id, $chapter->lesson_id);
        self::assertSame('video', $chapter->content_type);
        self::assertSame('s3', $chapter->video_provider);
        self::assertSame('https://cdn.example.test/recordings/seance-501.mp4', $chapter->video_url);
        self::assertSame('visio_recording', $chapter->notes_enseignant['source']);
        self::assertSame($seance->id, $chapter->notes_enseignant['seance_id']);
    }

    public function test_ambiguous_matching_lessons_fail_without_creating_chapter(): void
    {
        [$seance, $lesson] = $this->makeResolvableSeanceAndLesson();
        Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $lesson->matiere_id,
            'classe_id' => $lesson->classe_id,
            'enseignant_id' => $lesson->enseignant_id,
        ]);

        $result = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/a.mp4');

        self::assertFalse($result->success);
        self::assertSame('ambiguous_lesson', $result->reason);
        self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
        self::assertCount(2, $result->context['lesson_ids']);
    }

    public function test_missing_klassci_matiere_fails_without_losing_recording_url(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => null,
        ]);

        $result = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/orphan.mp4');

        self::assertFalse($result->success);
        self::assertSame('missing_klassci_matiere_id', $result->reason);
        self::assertSame('https://cdn.example.test/orphan.mp4', $result->context['recording_url']);
        self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
    }

    public function test_second_attachment_updates_existing_recording_chapter(): void
    {
        [$seance] = $this->makeResolvableSeanceAndLesson();

        $first = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/old.mp4');
        $second = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/new.mp4');

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertSame($first->chapter?->id, $second->chapter?->id);
        self::assertSame('https://cdn.example.test/new.mp4', $second->chapter?->video_url);
        self::assertSame(1, Chapter::query()->where('content_type', 'video')->count());
    }

    public function test_contended_attachment_lock_creates_no_duplicate_chapter(): void
    {
        [$seance] = $this->makeResolvableSeanceAndLesson();
        $store = app(CacheFactory::class)->store()->getStore();
        self::assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock(SeanceRecordingAttachmentGuard::key($seance->id), 30);
        self::assertTrue($lock->get());

        try {
            $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/race.mp4');
            self::fail('A concurrent attachment must be retried.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Seance recording attachment is already locked.', $exception->getMessage());
            self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{Seance, Lesson}
     */
    private function makeResolvableSeanceAndLesson(): array
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 701,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 31,
        ]);
        $teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 9001,
        ]);
        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $teacher->id,
        ]);
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => 701,
            'klassci_classe_id' => 31,
            'klassci_enseignant_id' => 9001,
            'klassci_seance_id' => 501,
            'titre' => 'Physique live',
        ]);

        return [$seance, $lesson];
    }

    // ───────── #707 : deux espaces d'identifiants dans la même comparaison ─────────

    /**
     * LE test du défaut : l'enregistrement atterrit chez un COLLÈGUE.
     *
     * `teacherCandidateIds()` construisait `[$klassciTeacherId]` — un identifiant
     * KLASSCI — puis y ajoutait des `users.id` **locaux**, et confrontait le tout
     * à `lessons.enseignant_id`, qui est un `users.id` local partout ailleurs
     * (six FormRequests et `Lesson::belongsTo(User::class, 'enseignant_id')` le
     * lisent ainsi).
     *
     * Il suffit donc qu'un collègue porte un `users.id` égal au
     * `klassci_enseignant_id` de la séance, et enseigne la même matière à la même
     * classe, pour que la vidéo soit publiée dans SON cours — visible par ses
     * étudiants. Le résultat attendu est `lesson_not_found`.
     *
     * La fuite est intra-établissement : chaque requête porte bien son
     * `institution_id`. C'est grave, ce n'est pas une fuite entre écoles.
     */
    public function test_a_recording_never_lands_in_a_colleague_lesson_by_id_collision(): void
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 701,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 31,
        ]);

        // Le collègue : c'est son `users.id` LOCAL qui va entrer en collision.
        $collegue = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
        $coursDuCollegue = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $collegue->id,
        ]);

        // La séance appartient à un enseignant dont l'identifiant KLASSCI vaut,
        // par malchance, le `users.id` du collègue. Le vrai enseignant n'a pas
        // de cours pour cette matière — condition du scénario.
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => 701,
            'klassci_classe_id' => 31,
            'klassci_enseignant_id' => $collegue->id,
            'titre' => 'Physique live',
        ]);

        $result = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/collision.mp4');

        self::assertFalse(
            $result->success,
            'La vidéo a été publiée dans le cours d un collègue par collision d identifiants.',
        );
        self::assertSame('lesson_not_found', $result->reason);
        self::assertSame(
            0,
            Chapter::query()->where('lesson_id', $coursDuCollegue->id)->count(),
            'Aucun chapitre ne doit être créé dans le cours du collègue.',
        );
    }

    /**
     * Le corollaire, et le piège de la correction.
     *
     * Retirer simplement l'identifiant KLASSCI du tableau le rendrait **vide** —
     * et `candidateLessons()` sautait alors le filtre enseignant entièrement,
     * renvoyant tous les cours de la matière et de la classe. La vidéo aurait
     * atterri chez n'importe qui, ou en `ambiguous_lesson`.
     *
     * Un enseignant DÉCLARÉ mais introuvable localement doit donc produire
     * `lesson_not_found`, jamais une absence de contrainte.
     */
    public function test_an_unresolvable_teacher_yields_lesson_not_found_not_an_open_search(): void
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 701,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 31,
        ]);
        $autreEnseignant = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
        Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $autreEnseignant->id,
        ]);

        // Aucun utilisateur ne porte ce `klassci_enseignant_id`, et la valeur ne
        // correspond à aucun `users.id` : la résolution échoue proprement.
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => 701,
            'klassci_classe_id' => 31,
            'klassci_enseignant_id' => 888_777_666,
            'titre' => 'Physique live',
        ]);

        $result = $this->resolver()->attachReadyRecording($seance, 'https://cdn.example.test/orphelin.mp4');

        self::assertFalse($result->success);
        self::assertSame(
            'lesson_not_found',
            $result->reason,
            'Un enseignant introuvable ne doit pas ouvrir la recherche à tous les cours.',
        );
        self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
    }

    private function resolver(): SeanceRecordingAttachmentResolver
    {
        return new SeanceRecordingAttachmentResolver(
            new NullLogger,
            app(SeanceRecordingAttachmentGuard::class),
        );
    }
}

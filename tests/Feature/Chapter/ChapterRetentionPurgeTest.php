<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\FileConversion\ChapterArtifactStorage;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La corbeille des chapitres doit finir par se vider (#674).
 *
 * ## Le défaut
 *
 * #689 a rendu la corbeille réelle : les fichiers survivent désormais à la
 * suppression. Mais RIEN ne la vide jamais. Les lignes ET leurs fichiers
 * s'accumulent sans limite, ce qui est un sujet de conformité avant d'être un
 * sujet d'espace disque :
 *
 * - conserver au-delà de la durée déclarée est une infraction en soi ;
 * - aucune demande d'effacement ne peut être honorée ;
 * - les URL de diapositives étant prédictibles (#598), un cours mis à la
 *   corbeille reste énumérable sans authentification **pour toujours**.
 *
 * ## Le partage avec la rétention visio
 *
 * Un chapitre engendré par un enregistrement appartient à
 * {@see SeanceRecordingRetentionService}, qui seul
 * sait quand son média expire. Cette appartenance se lit **en base** —
 * l'existence d'une ligne `seance_recordings` qui le référence — et non sur le
 * marqueur JSON `notes_enseignant['source']`.
 *
 * C'est ce qui fait que les deux mécanismes se composent au lieu de se
 * chevaucher. `SeanceRecording::chapter()` est un `belongsTo` simple, donc
 * aveugle aux chapitres à la corbeille : exclure par marqueur créerait une
 * classe d'orphelins que PERSONNE ne purgerait jamais — exactement le défaut
 * que cette issue existe pour corriger.
 */
final class ChapterRetentionPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const RETENTION_DAYS = 30;

    private Institution $institution;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('chapters.retention_days', self::RETENTION_DAYS);

        Storage::fake(ChapterArtifactStorage::PUBLIC_DISK);
        Storage::fake(ChapterArtifactStorage::PRIVATE_DISK);

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->lesson = Lesson::factory()
            ->for($this->institution)
            ->state(['enseignant_id' => $this->teacher->id])
            ->create();
    }

    // ───────────────── Ce que la purge doit détruire ─────────────────

    public function test_a_chapter_trashed_beyond_retention_is_destroyed_with_its_files(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        self::assertNull(
            Chapter::withTrashed()->find($chapter->id),
            'la ligne doit disparaitre definitivement, pas rester a la corbeille',
        );
        $this->assertChapterFilesGone($chapter->id);
    }

    public function test_purging_a_chapter_takes_its_quizzes_and_progress_with_it(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);
        $check = KnowledgeCheck::factory()->create(['chapter_id' => $chapter->id]);
        $progressId = $this->givenStudentProgressOn($chapter);

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        $this->assertDatabaseMissing('knowledge_checks', ['id' => $check->id]);
        $this->assertDatabaseMissing('chapter_progress', ['id' => $progressId]);
    }

    // ───────────────── Ce que la purge ne doit PAS toucher ─────────────────

    public function test_a_chapter_trashed_within_retention_is_left_alone(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS - 1);

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        self::assertNotNull(Chapter::withTrashed()->find($chapter->id));
        $this->assertChapterFilesExist($chapter->id, 'le delai de rattrapage court encore');
    }

    public function test_a_live_chapter_is_never_touched(): void
    {
        $chapter = Chapter::factory()
            ->for($this->institution)
            ->state(['lesson_id' => $this->lesson->id, 'enseignant_id' => $this->teacher->id])
            ->create();
        $this->givenChapterHasFiles($chapter->id);

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        self::assertNotNull(Chapter::find($chapter->id));
        $this->assertChapterFilesExist($chapter->id, 'un chapitre en ligne n est jamais concerne');
    }

    /**
     * LE test du partage. Tant qu'un enregistrement le référence, le chapitre
     * appartient à la rétention visio — qui seule sait quand son média expire.
     */
    public function test_a_chapter_still_referenced_by_a_recording_is_left_to_visio_retention(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);
        $this->givenRecordingReferencing($chapter);

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        self::assertNotNull(Chapter::withTrashed()->find($chapter->id));
        $this->assertChapterFilesExist($chapter->id, 'la retention visio en est proprietaire');
    }

    /**
     * Le pendant du précédent, et la preuve que les deux mécanismes se
     * composent : `seance_recordings` n'est PAS en suppression réversible, donc
     * la ligne disparaît vraiment à la purge visio — et le chapitre nous revient.
     */
    public function test_once_the_recording_is_gone_the_chapter_becomes_purgeable(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);
        $this->givenRecordingReferencing($chapter)->delete();

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        self::assertNull(Chapter::withTrashed()->find($chapter->id));
        $this->assertChapterFilesGone($chapter->id);
    }

    // ───────────────── L'inventaire et le garde d'invocation ─────────────────

    public function test_dry_run_reports_without_destroying_anything(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);

        $this->artisan('chapters:purge --dry-run')->assertSuccessful();

        self::assertNotNull(
            Chapter::withTrashed()->find($chapter->id),
            'une simulation ne detruit rien',
        );
        $this->assertChapterFilesExist($chapter->id, 'une simulation ne detruit rien');
    }

    public function test_the_command_refuses_an_ambiguous_invocation(): void
    {
        $chapter = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);

        $this->artisan('chapters:purge')->assertFailed();
        $this->artisan('chapters:purge --dry-run --apply')->assertFailed();

        self::assertNotNull(Chapter::withTrashed()->find($chapter->id));
    }

    // ───────────────── Le cloisonnement ne doit pas AVEUGLER la purge ─────────

    /**
     * Sans `withoutGlobalScope`, la purge devient silencieusement cloisonnée :
     * elle ne verrait que les chapitres d'un seul établissement et se
     * déclarerait pourtant en succès — le pire des deux mondes, rien n'est
     * effacé et l'indicateur reste au vert.
     *
     * ## Pourquoi ce test POSE un tenant, contrairement au reste du fichier
     *
     * `BelongsToInstitution` est fail-open : sans tenant résolu, son scope global
     * ne filtre RIEN. Or une commande de console n'en résout aucun. Écrit sans
     * `TenantManager::set()`, ce test passait donc **aussi bien avec que sans**
     * la garde — constaté par falsification, il ne prouvait rien.
     *
     * Poser un tenant est le seul moyen de rendre le scope actif, donc de rendre
     * son contournement mesurable. C'est aussi le scénario qui compte : le jour
     * où cette purge sera déclenchée depuis un contexte où un tenant existe,
     * c'est cette garde qui l'empêchera de devenir borgne.
     */
    public function test_chapters_of_every_institution_are_processed(): void
    {
        $autreEcole = Institution::factory()->create();
        $autreEnseignant = User::factory()->create([
            'institution_id' => $autreEcole->id,
            'role' => 'enseignant',
        ]);
        $autreLecon = Lesson::factory()
            ->for($autreEcole)
            ->state(['enseignant_id' => $autreEnseignant->id])
            ->create();

        $chezNous = $this->givenTrashedChapter(daysAgo: self::RETENTION_DAYS + 1);
        $ailleurs = $this->givenTrashedChapter(
            daysAgo: self::RETENTION_DAYS + 1,
            institution: $autreEcole,
            lesson: $autreLecon,
            enseignant: $autreEnseignant,
        );

        // Le scope global devient ACTIF, et pointe sur notre seule école.
        app(TenantManager::class)->set($this->institution);

        $this->artisan('chapters:purge --apply')->assertSuccessful();

        app(TenantManager::class)->reset();

        self::assertNull(
            Chapter::withoutGlobalScope('institution')->withTrashed()->find($chezNous->id),
        );
        self::assertNull(
            Chapter::withoutGlobalScope('institution')->withTrashed()->find($ailleurs->id),
            'La purge est devenue borgne : elle ignore les chapitres des autres etablissements.',
        );
    }

    // ───────────────── Fixtures ─────────────────

    private function givenTrashedChapter(
        int $daysAgo,
        ?Institution $institution = null,
        ?Lesson $lesson = null,
        ?User $enseignant = null,
    ): Chapter {
        $chapter = Chapter::factory()
            ->for($institution ?? $this->institution)
            ->state([
                'lesson_id' => ($lesson ?? $this->lesson)->id,
                'enseignant_id' => ($enseignant ?? $this->teacher)->id,
            ])
            ->create();

        $this->givenChapterHasFiles($chapter->id);
        $chapter->delete();

        // `deleted_at` est l'ancre de rétention : on la recule explicitement
        // plutôt que de voyager dans le temps, pour que le test dise ce qu'il
        // mesure.
        Chapter::withTrashed()->whereKey($chapter->id)
            ->update(['deleted_at' => now()->subDays($daysAgo)]);

        return $chapter;
    }

    private function givenRecordingReferencing(Chapter $chapter): SeanceRecording
    {
        $seance = Seance::factory()->for($this->institution)->create();

        return SeanceRecording::factory()->create([
            'seance_id' => $seance->id,
            'institution_id' => $this->institution->id,
            'chapter_id' => $chapter->id,
        ]);
    }

    /** Pas de factory pour `chapter_progress` : insertion directe. */
    private function givenStudentProgressOn(Chapter $chapter): int
    {
        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        return (int) DB::table('chapter_progress')->insertGetId([
            'user_id' => $student->id,
            'chapter_id' => $chapter->id,
            'time_spent_seconds' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function givenChapterHasFiles(int $chapterId): void
    {
        Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)
            ->put("chapters/{$chapterId}/original/cours.pdf", 'document source');
        Storage::disk(ChapterArtifactStorage::PUBLIC_DISK)
            ->put("chapters/{$chapterId}/slides/slide_001.png", 'diapositive');
    }

    private function assertChapterFilesExist(int $chapterId, string $message): void
    {
        self::assertTrue(
            Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)->exists("chapters/{$chapterId}/original/cours.pdf"),
            "Document source manquant — {$message}",
        );
        self::assertTrue(
            Storage::disk(ChapterArtifactStorage::PUBLIC_DISK)->exists("chapters/{$chapterId}/slides/slide_001.png"),
            "Diapositive manquante — {$message}",
        );
    }

    /** Les DEUX disques : purger le seul public laisserait le document source. */
    private function assertChapterFilesGone(int $chapterId): void
    {
        self::assertFalse(
            Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)->exists("chapters/{$chapterId}/original/cours.pdf"),
            'Le document source de l enseignant survit a la purge (disque prive).',
        );
        self::assertFalse(
            Storage::disk(ChapterArtifactStorage::PUBLIC_DISK)->exists("chapters/{$chapterId}/slides/slide_001.png"),
            'Une diapositive survit a la purge, et son URL est predictible (#598).',
        );
    }
}

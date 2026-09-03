<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\FileConversion\ChapterArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La corbeille des chapitres doit permettre de se rattraper (#689).
 *
 * ## Le défaut corrigé
 *
 * `ChapterCrudService::delete()` détruisait les fichiers du chapitre AVANT de
 * mettre la ligne à la corbeille — `purgeChapter()` faisant un
 * `deleteDirectory()` sur les DEUX disques. La corbeille ne contenait donc que
 * des coquilles vides, et aucune restauration n'existait :
 *
 *     grep -rn "restore()\|withTrashed()" app/Services/Chapter/ → aucun résultat
 *
 * Le mécanisme avait le coût du soft delete sans aucun de ses bénéfices.
 * Supprimer un chapitre détruit pourtant un cours entier, document source de
 * l'enseignant compris.
 *
 * ## La décision
 *
 * Option B, actée par le propriétaire du produit : les fichiers ne sont plus
 * détruits à la suppression mais à la purge (#674), et une restauration rend un
 * chapitre COMPLET, jamais une coquille.
 *
 * ## Le risque que ces tests couvrent aussi
 *
 * Une corbeille qui continuerait à servir son contenu serait une fuite de
 * données supprimées — aggravée par le fait que les URL de diapositives sont
 * prédictibles (#598).
 */
final class ChapterTrashAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    private Lesson $lesson;

    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

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

        $this->chapter = Chapter::factory()
            ->for($this->institution)
            ->state([
                'lesson_id' => $this->lesson->id,
                'enseignant_id' => $this->teacher->id,
            ])
            ->create();

        $this->givenChapterHasFiles($this->chapter->id);
    }

    // ───────────────────── La corbeille conserve ─────────────────────

    /**
     * LE test du défaut. Sans lui, la restauration rendrait une coquille vide.
     */
    public function test_deleting_a_chapter_keeps_its_files(): void
    {
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $this->assertSoftDeleted('chapters', ['id' => $this->chapter->id]);
        $this->assertChapterFilesExist($this->chapter->id, 'les fichiers doivent survivre à la mise à la corbeille');
    }

    // ───────────────────── La corbeille ne fuit pas ─────────────────────

    /**
     * Une corbeille qui sert encore son contenu est une fuite de données
     * supprimées, aggravée par des URL de diapositives prédictibles (#598).
     */
    public function test_a_trashed_chapter_is_no_longer_listed(): void
    {
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $response = $this->asTeacher()->getJson("/api/lessons/{$this->lesson->id}/chapters")->assertStatus(200);

        $ids = collect($response->json('data') ?? [])->pluck('id')->all();
        self::assertNotContains($this->chapter->id, $ids);
    }

    public function test_a_trashed_chapter_can_no_longer_be_shown(): void
    {
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $this->asTeacher()->getJson("/api/chapters/{$this->chapter->id}")->assertStatus(404);
    }

    // ───────────────────── La restauration rend un chapitre COMPLET ─────────

    public function test_restoring_gives_back_a_complete_chapter(): void
    {
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $this->asTeacher()->postJson("/api/chapters/{$this->chapter->id}/restore")->assertStatus(200);

        $this->assertNotSoftDeleted('chapters', ['id' => $this->chapter->id]);
        $this->assertChapterFilesExist($this->chapter->id, 'un chapitre restauré ne doit jamais être une coquille vide');
        $this->asTeacher()->getJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);
    }

    public function test_restoring_a_chapter_that_was_never_deleted_is_harmless(): void
    {
        $this->asTeacher()->postJson("/api/chapters/{$this->chapter->id}/restore")->assertStatus(200);

        $this->assertNotSoftDeleted('chapters', ['id' => $this->chapter->id]);
    }

    // ───────────────────── La restauration est gardée ─────────────────────

    /**
     * La restauration remet du contenu en ligne : elle ne peut pas être moins
     * gardée que la suppression.
     */
    public function test_a_student_cannot_restore(): void
    {
        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $this->asUser($student)->postJson("/api/chapters/{$this->chapter->id}/restore")->assertStatus(403);

        $this->assertSoftDeleted('chapters', ['id' => $this->chapter->id]);
    }

    public function test_a_teacher_from_another_institution_cannot_restore(): void
    {
        $autreEcole = Institution::factory()->create();
        $intrus = User::factory()->create([
            'institution_id' => $autreEcole->id,
            'role' => 'enseignant',
        ]);
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $this->asUser($intrus)->postJson("/api/chapters/{$this->chapter->id}/restore")->assertStatus(403);

        $this->assertSoftDeleted('chapters', ['id' => $this->chapter->id]);
    }

    public function test_an_anonymous_caller_cannot_restore(): void
    {
        $this->asTeacher()->deleteJson("/api/chapters/{$this->chapter->id}")->assertStatus(200);

        $this->asAnonymous()->postJson("/api/chapters/{$this->chapter->id}/restore")->assertStatus(401);
    }

    // ───────────────────── Fixtures ─────────────────────

    private function givenChapterHasFiles(int $chapterId): void
    {
        Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)
            ->put("chapters/{$chapterId}/original/cours.pdf", 'document source');
        Storage::disk(ChapterArtifactStorage::PUBLIC_DISK)
            ->put("chapters/{$chapterId}/slides/slide_001.png", 'diapositive');
    }

    /**
     * `Storage::assertExists($chemin, $contenu)` prend un CONTENU en second
     * argument, pas un message — d'où l'assertion explicite ici, qui laisse le
     * message lisible en cas d'échec.
     */
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

    private function asTeacher(): self
    {
        return $this->asUser($this->teacher);
    }

    /**
     * Jeton porteur RÉEL : sans bearer, aucun tenant n'est résolu et le scope
     * multi-tenant s'efface en silence (fail-open documenté).
     *
     * Les deux purges sont indispensables, et l'oubli de la seconde a produit
     * ici un faux négatif mesuré — un appel anonyme répondait **200** :
     *
     * - `flushHeaders()` : `withHeaders()` MERGE dans les en-têtes par défaut de
     *   l'instance ; sans purge, le jeton précédent voyage encore.
     * - `forgetGuards()` : le conteneur survit d'une requête à l'autre au sein
     *   d'un même test, et le garde Sanctum mémoïse l'utilisateur résolu au
     *   premier appel. Les en-têtes suivants ne sont alors même plus lus.
     *
     * Vérifié : la même requête anonyme rendait 401 en isolation et 200 après un
     * appel enseignant. Sans cette ligne, tout test « X ne peut pas » exécuté
     * après une requête authentifiée est vert sans rien prouver.
     */
    private function asUser(User $user): self
    {
        $this->asAnonymous();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('chapter-trash-test')->plainTextToken,
        ]);
    }

    /** Ni en-tête, ni utilisateur déjà résolu : un appel réellement anonyme. */
    private function asAnonymous(): self
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }
}

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
 * Issue #598 (R4) — le document source d'un chapitre n'est plus servi par
 * Apache via `/storage/...` mais par une route **authentifiée** et cloisonnée.
 *
 * Ce test éprouve la frontière d'autorisation elle-même : qui obtient les
 * octets, et qui reçoit un refus.
 *
 * Codes attendus, et pourquoi :
 *   - **401** anonyme (`auth:sanctum`) ;
 *   - **404** pour un chapitre d'une AUTRE institution — le scope global
 *     `BelongsToInstitution` le rend inexistant pour la requête. 404 plutôt que
 *     403 : un 403 confirmerait l'existence de la ressource, et c'est déjà le
 *     comportement de `GET /api/chapters/{id}` ;
 *   - **403** intra-tenant lorsque l'enseignant a refusé le téléchargement
 *     (`allow_download = false`) ;
 *   - **200** pour l'enseignant propriétaire, avec les octets exacts.
 *
 * ## Harnais : vrai jeton porteur, pas `Sanctum::actingAs()`
 *
 * `Sanctum::actingAs()` n'envoie **aucun** en-tête `Authorization`. Or
 * `ResolveInstitution` ne résout le tenant qu'à partir de `bearerToken()` : sans
 * lui, `TenantManager` reste vide et le scope global `BelongsToInstitution`
 * (fail-open, #565) ne filtre rien — le test n'éprouverait alors que le contrôle
 * explicite du trait, pas la chaîne réelle. On émet donc un vrai jeton Sanctum,
 * comme un client.
 *
 * @see .claude/specs/598-chapter-artifacts-private/design.md §1.2
 */
final class ChapterOriginalDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_BYTES = 'contenu-confidentiel-du-cours';

    public function test_anonymous_caller_is_refused(): void
    {
        $chapter = $this->chapterWithSource($this->institution());

        $this->getJson("/api/chapters/{$chapter->id}/original")->assertStatus(401);
    }

    public function test_owning_teacher_downloads_the_source_bytes(): void
    {
        $institution = $this->institution();
        $teacher = $this->user($institution, 'enseignant');
        $chapter = $this->chapterWithSource($institution, $teacher);

        $response = $this->actingWithToken($teacher)->get("/api/chapters/{$chapter->id}/original");

        $response->assertOk();
        self::assertSame(self::SOURCE_BYTES, $response->streamedContent());
    }

    public function test_user_of_another_institution_gets_nothing(): void
    {
        $chapter = $this->chapterWithSource($this->institution());
        $outsider = $this->user($this->institution(), 'enseignant');

        $response = $this->actingWithToken($outsider)->getJson("/api/chapters/{$chapter->id}/original");

        $response->assertStatus(404);
        self::assertStringNotContainsString(self::SOURCE_BYTES, (string) $response->getContent());
    }

    public function test_student_is_refused_when_download_is_disabled(): void
    {
        $institution = $this->institution();
        $chapter = $this->chapterWithSource($institution, allowDownload: false);
        $student = $this->user($institution, 'etudiant');

        $response = $this->actingWithToken($student)->getJson("/api/chapters/{$chapter->id}/original");

        $response->assertStatus(403);
        self::assertStringNotContainsString(self::SOURCE_BYTES, (string) $response->getContent());
    }

    public function test_student_may_download_when_teacher_allows_it(): void
    {
        $institution = $this->institution();
        $chapter = $this->chapterWithSource($institution, allowDownload: true);
        $student = $this->user($institution, 'etudiant');

        $response = $this->actingWithToken($student)->get("/api/chapters/{$chapter->id}/original");

        $response->assertOk();
        self::assertSame(self::SOURCE_BYTES, $response->streamedContent());
    }

    /**
     * Un chapitre **vidéo** garde son fichier sur le disque PUBLIC (lecture
     * `<video>`), alors que les documents convertis sont privés :
     * `file_original_path` est donc polymorphe. Coder le disque en dur côté
     * lecture renvoyait 404 en silence sur tout chapitre vidéo — défaut relevé
     * par l'audit `spec-architect`, verrouillé ici.
     */
    public function test_video_chapter_source_is_still_downloadable(): void
    {
        Storage::fake(ChapterArtifactStorage::PUBLIC_DISK);

        $institution = $this->institution();
        $teacher = $this->user($institution, 'enseignant');
        $chapter = $this->chapter($institution, $teacher, filePath: null);

        $path = "chapters/{$chapter->id}/video/cours.mp4";
        Storage::disk(ChapterArtifactStorage::PUBLIC_DISK)->put($path, self::SOURCE_BYTES);
        $chapter->update(['content_type' => 'video', 'file_original_path' => $path]);

        $response = $this->actingWithToken($teacher)->get("/api/chapters/{$chapter->id}/original");

        $response->assertOk();
        self::assertSame(self::SOURCE_BYTES, $response->streamedContent());
    }

    /**
     * Les deux branches d'élévation de privilège du trait — `supradmin`
     * (cross-tenant) et administrateur intra-tenant — sont les plus sensibles :
     * ce sont elles qui régressent en silence si quelqu'un « modernise » la
     * comparaison stricte `'supradmin'` en `asRoleEnum()`, qui normaliserait
     * aussi `'superAdmin'`.
     */
    public function test_supradmin_downloads_across_institutions_but_admin_stays_scoped(): void
    {
        $institution = $this->institution();
        $chapter = $this->chapterWithSource($institution, allowDownload: false);

        // Un supradmin RÉEL n'a pas d'institution : c'est `institution_id = null`
        // qui fait que `ResolveInstitution` le laisse non scopé (cf. #565).
        $supradmin = User::factory()->create([
            'role'              => 'supradmin',
            'institution_id'    => null,
            'last_klassci_sync' => now(),
        ]);
        $this->actingWithToken($supradmin)
            ->get("/api/chapters/{$chapter->id}/original")
            ->assertOk();

        $foreignAdmin = $this->user($this->institution(), 'admin');
        $this->actingWithToken($foreignAdmin)
            ->getJson("/api/chapters/{$chapter->id}/original")
            ->assertStatus(404);
    }

    /**
     * Un chemin qui ne descend pas de l'arborescence du chapitre demandé est
     * refusé, même s'il existe sur le disque privé : défense en profondeur
     * contre une écriture future (import, resync, correctif SQL) qui placerait
     * une autre valeur dans `file_original_path`.
     */
    public function test_path_outside_the_chapter_tree_is_refused(): void
    {
        Storage::fake(ChapterArtifactStorage::PRIVATE_DISK);

        $institution = $this->institution();
        $teacher = $this->user($institution, 'enseignant');
        $chapter = $this->chapter($institution, $teacher, filePath: null);

        Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)->put('reports/secret.pdf', self::SOURCE_BYTES);
        $chapter->update(['file_original_path' => 'reports/secret.pdf']);

        $response = $this->actingWithToken($teacher)->getJson("/api/chapters/{$chapter->id}/original");

        $response->assertStatus(404);
        self::assertStringNotContainsString(self::SOURCE_BYTES, (string) $response->getContent());
    }

    /**
     * Un chapitre sans document source (leçon rédigée directement, ou artefact
     * purgé) donne 404 — jamais une erreur serveur.
     */
    public function test_chapter_without_source_returns_not_found(): void
    {
        $institution = $this->institution();
        $teacher = $this->user($institution, 'enseignant');
        $chapter = $this->chapter($institution, $teacher, filePath: null);

        $this->actingWithToken($teacher)->getJson("/api/chapters/{$chapter->id}/original")->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Harnais
    // ------------------------------------------------------------------

    /**
     * Émet un vrai jeton Sanctum et l'envoie en `Authorization: Bearer` — seule
     * façon de faire tourner `ResolveInstitution`, donc le scope tenant.
     */
    private function actingWithToken(User $user): static
    {
        return $this->withToken($user->createToken('test-598')->plainTextToken);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function institution(): Institution
    {
        return Institution::factory()->create(['is_active' => true]);
    }

    private function user(Institution $institution, string $role): User
    {
        return User::factory()->for($institution)->create([
            'role'              => $role,
            'last_klassci_sync' => now(),
        ]);
    }

    private function chapterWithSource(
        Institution $institution,
        ?User $teacher = null,
        bool $allowDownload = true,
    ): Chapter {
        Storage::fake(ChapterArtifactStorage::PRIVATE_DISK);

        $teacher ??= $this->user($institution, 'enseignant');
        $chapter = $this->chapter($institution, $teacher, filePath: null, allowDownload: $allowDownload);

        $path = "chapters/{$chapter->id}/original/source.docx";
        Storage::disk(ChapterArtifactStorage::PRIVATE_DISK)->put($path, self::SOURCE_BYTES);
        $chapter->update(['file_original_path' => $path]);

        return $chapter->refresh();
    }

    private function chapter(
        Institution $institution,
        User $teacher,
        ?string $filePath,
        bool $allowDownload = true,
    ): Chapter {
        $lesson = Lesson::factory()->create(['institution_id' => $institution->id]);

        return Chapter::factory()->create([
            'lesson_id'          => $lesson->id,
            'institution_id'     => $institution->id,
            'enseignant_id'      => $teacher->id,
            'title'              => 'Chapitre de test',
            'content_type'       => 'word',
            'file_original_path' => $filePath,
            'allow_download'     => $allowDownload,
        ]);
    }
}

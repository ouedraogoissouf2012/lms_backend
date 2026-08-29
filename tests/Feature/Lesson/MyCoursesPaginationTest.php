<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #483 — pagination + validation de `/lessons/my-courses`.
 *
 * `myCourses()` faisait `->get()` (non paginé) et le contrôleur ignorait
 * `MyCoursesRequest`. Ces tests figent : pagination effective, `data` reste un
 * TABLEAU PLAT (contrat frontend `response.data`), `meta` additif, per_page
 * borné (anti-DOS), restriction classe #482 préservée, filtres exhaustifs.
 *
 * @see app/Services/Lesson/LessonListService.php myCourses()
 * @see app/Services/Lesson/MyCoursesPresenter.php
 * @see .claude/specs/my-courses-pagination/
 */
final class MyCoursesPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;
    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 7001,
        ]);
        UserClass::create([
            'user_id' => $this->student->id,
            'klassci_classe_id' => 7001,
            'classe_nom' => $this->classe->libelle,
            'institution_id' => $this->institution->id,
            'synced_at' => now(),
        ]);
    }

    private function publishedLessonsInStudentClasse(int $count, ?Matiere $matiere = null): void
    {
        Lesson::factory()->published()->count($count)->create([
            'institution_id' => $this->institution->id,
            'classe_id' => $this->classe->id,
            'matiere_id' => $matiere?->id,
        ]);
    }

    public function test_my_courses_is_paginated_and_data_stays_flat(): void
    {
        $this->publishedLessonsInStudentClasse(20);

        Sanctum::actingAs($this->student);
        $response = $this->getJson('/api/lessons/my-courses?per_page=5');

        $response->assertStatus(200)->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(5, $data, 'data doit contenir per_page éléments.');
        // data reste un TABLEAU PLAT (pas de data.data paginator brut).
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('title', $data[0]);

        $this->assertSame(20, $response->json('meta.total'));
        $this->assertSame(4, $response->json('meta.last_page'));
        $this->assertSame(5, $response->json('meta.per_page'));
    }

    public function test_my_courses_rejects_oversized_per_page(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/lessons/my-courses?per_page=1000')
            ->assertStatus(422); // anti-DOS via MyCoursesRequest
    }

    public function test_my_courses_default_page_size(): void
    {
        $this->publishedLessonsInStudentClasse(3);

        Sanctum::actingAs($this->student);
        $response = $this->getJson('/api/lessons/my-courses');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(15, count($response->json('data')));
    }

    public function test_my_courses_pagination_preserves_classe_restriction(): void
    {
        // 5 cours de la classe de l'étudiant + 5 d'une AUTRE classe.
        $this->publishedLessonsInStudentClasse(5);
        $otherClasse = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 8002,
        ]);
        Lesson::factory()->published()->count(5)->create([
            'institution_id' => $this->institution->id,
            'classe_id' => $otherClasse->id,
        ]);

        Sanctum::actingAs($this->student);
        $response = $this->getJson('/api/lessons/my-courses?per_page=50');

        $response->assertStatus(200);
        // Seuls les 5 de SA classe, malgré per_page large (#482 préservé).
        $this->assertSame(5, $response->json('meta.total'));
    }

    public function test_my_courses_filters_cover_all_pages(): void
    {
        $matiereA = Matiere::factory()->create(['institution_id' => $this->institution->id]);
        $matiereB = Matiere::factory()->create(['institution_id' => $this->institution->id]);

        // 5 cours matière A + 5 cours matière B, tous dans la classe de l'étudiant.
        $this->publishedLessonsInStudentClasse(5, $matiereA);
        $this->publishedLessonsInStudentClasse(5, $matiereB);

        Sanctum::actingAs($this->student);
        // per_page=3 : une seule page ne contiendrait pas les 2 matières.
        $response = $this->getJson('/api/lessons/my-courses?per_page=3');

        $response->assertStatus(200);
        $matiereIds = collect($response->json('filters.matieres'))->pluck('id')->all();
        $this->assertContains($matiereA->id, $matiereIds);
        $this->assertContains($matiereB->id, $matiereIds);
    }
}

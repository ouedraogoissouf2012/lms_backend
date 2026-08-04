<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #482 — isolation par classe sur `GET /lessons` (endpoint index / `list()`).
 *
 * Le trou de sécurité de `myCourses()` existait AUSSI dans `list()` : un
 * étudiant pouvait lister toutes les leçons publiées du tenant sans passer de
 * `classe_id`. Ce test verrouille la même restriction (périmètre élargi #482).
 *
 * Inclut la non-régression : un enseignant n'est PAS restreint par classe.
 *
 * @see app/Services/Lesson/LessonListService.php list()
 */
final class LessonIndexClasseScopingTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->institution = Institution::factory()->create();
    }

    private function classeWithStudent(User $student, int $klassciClasseId): Classe
    {
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciClasseId,
        ]);
        UserClass::create([
            'user_id' => $student->id,
            'klassci_classe_id' => $klassciClasseId,
            'classe_nom' => $classe->libelle,
            'institution_id' => $this->institution->id,
            'synced_at' => now(),
        ]);

        return $classe;
    }

    private function publishedLesson(int $localClasseId): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'classe_id' => $localClasseId,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_student_index_is_restricted_to_their_classe(): void
    {
        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $classeA = $this->classeWithStudent($student, klassciClasseId: 1001);
        $classeB = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 2002,
        ]);

        $lessonA = $this->publishedLesson($classeA->id);
        $this->publishedLesson($classeB->id); // autre classe → invisible

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/lessons');

        $response->assertStatus(200);
        // GET /lessons est paginé → data.data[] (cf. successResponse(paginator)).
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$lessonA->id], $ids);
    }

    public function test_teacher_index_is_not_restricted_by_classe(): void
    {
        // REQ-5 : un enseignant n'est PAS soumis à la restriction classe.
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $classeA = Classe::factory()->create(['institution_id' => $this->institution->id, 'klassci_id' => 1001]);
        $classeB = Classe::factory()->create(['institution_id' => $this->institution->id, 'klassci_id' => 2002]);

        $lessonA = $this->publishedLesson($classeA->id);
        $lessonB = $this->publishedLesson($classeB->id);

        Sanctum::actingAs($teacher);
        $response = $this->getJson('/api/lessons');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        // L'enseignant voit les DEUX classes (aucune restriction #482).
        $this->assertContains($lessonA->id, $ids);
        $this->assertContains($lessonB->id, $ids);
    }
}

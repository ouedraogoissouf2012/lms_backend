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
 * Issue #482 — isolation par classe sur `/lessons/my-courses`.
 *
 * `LessonListService::myCourses()` renvoyait TOUTES les leçons publiées du
 * tenant, sans restriction à la classe de l'étudiant → fuite inter-classes.
 * Ces tests figent la restriction : un étudiant ne voit que les leçons dont
 * `classe_id` correspond à SA classe (résolue via le pont
 * UserClass.klassci_classe_id → classes.klassci_id → classes.id).
 *
 * @see app/Services/Lesson/LessonListService.php
 * @see app/Services/Lesson/StudentClasseResolver.php
 * @see .claude/specs/lessons-student-classe-scoping/
 */
final class MyCoursesClasseScopingTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->institution = Institution::factory()->create();
    }

    private function student(): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    /**
     * Crée une classe locale + rattache l'étudiant à cette classe via UserClass
     * (source de vérité KLASSCI), en respectant le pont klassci_id ↔ id local.
     */
    private function enrollStudentInClasse(User $student, int $klassciClasseId): Classe
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

    private function publishedLesson(?int $localClasseId): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'classe_id' => $localClasseId,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_student_sees_only_lessons_of_their_classe(): void
    {
        $student = $this->student();
        $classeA = $this->enrollStudentInClasse($student, klassciClasseId: 1001);

        // Classe B : une AUTRE classe locale, l'étudiant n'y est pas inscrit.
        $classeB = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 2002,
        ]);

        $lessonA = $this->publishedLesson($classeA->id);
        $this->publishedLesson($classeB->id); // ne doit PAS apparaître

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/lessons/my-courses');

        $response->assertStatus(200)->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$lessonA->id], $ids);
    }

    public function test_student_does_not_see_lessons_without_classe(): void
    {
        $student = $this->student();
        $classeA = $this->enrollStudentInClasse($student, klassciClasseId: 1001);

        $lessonA = $this->publishedLesson($classeA->id);
        $this->publishedLesson(null); // classe_id NULL → exclue (REQ-3)

        Sanctum::actingAs($student);
        $ids = collect($this->getJson('/api/lessons/my-courses')->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$lessonA->id], $ids);
    }

    public function test_student_without_classe_gets_empty_list(): void
    {
        $student = $this->student(); // aucune UserClass
        $this->publishedLesson(
            Classe::factory()->create(['institution_id' => $this->institution->id])->id
        );

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/lessons/my-courses');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data')); // REQ-4 fail-secure
    }

    public function test_bridge_klassci_to_local_resolves_correct_lessons(): void
    {
        $student = $this->student();
        // klassci_classe_id = 5555 ; la classe locale a un id DIFFÉRENT de 5555.
        $classe = $this->enrollStudentInClasse($student, klassciClasseId: 5555);
        $this->assertNotSame(5555, $classe->id, 'Le test doit prouver le pont, pas une coïncidence d\'ids.');

        $lesson = $this->publishedLesson($classe->id);

        Sanctum::actingAs($student);
        $ids = collect($this->getJson('/api/lessons/my-courses')->json('data'))->pluck('id')->all();

        $this->assertContains($lesson->id, $ids);
    }
}

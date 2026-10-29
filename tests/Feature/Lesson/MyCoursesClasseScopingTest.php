<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
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
 * ## Vrai jeton porteur (#943)
 *
 * Ces tests s'authentifiaient par `Sanctum::actingAs`, qui n'émet aucun jeton :
 * `ResolveInstitution` ne posait aucun tenant et le scope `BelongsToInstitution`
 * ne filtrait rien. Ils passent désormais par {@see ActsAsTenantUser::asTenant()}.
 *
 * @see app/Services/Lesson/LessonListService.php
 * @see app/Services/Enrollment/CompositeEnrollmentSource.php
 * @see .claude/specs/lessons-student-classe-scoping/
 */
final class MyCoursesClasseScopingTest extends TestCase
{
    use ActsAsTenantUser;
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
        $classe->etudiants()->attach($student->id, ['statut' => 'actif']);

        return $classe;
    }

    /**
     * Inscription par le SEUL cache KLASSCI (`user_classes`), sans pivot local.
     *
     * `CompositeEnrollmentSource` lit d'abord le pivot `classe_etudiant` et ne
     * se replie sur le cache que si le pivot est vide. Un test qui attache aussi
     * le pivot ne traverse donc jamais le pont : il resterait vert avec une
     * `KlassciEnrollmentSource` qui ne rend rien (#943).
     */
    private function enrollThroughKlassciCacheOnly(User $student, int $klassciClasseId): Classe
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

        $response = $this->asTenant($student)->getJson('/api/lessons/my-courses');

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

        $ids = collect($this->asTenant($student)->getJson('/api/lessons/my-courses')->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$lessonA->id], $ids);
    }

    public function test_student_without_classe_gets_empty_list(): void
    {
        $student = $this->student(); // aucune UserClass
        $this->publishedLesson(
            Classe::factory()->create(['institution_id' => $this->institution->id])->id
        );

        $response = $this->asTenant($student)->getJson('/api/lessons/my-courses');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data')); // REQ-4 fail-secure
    }

    public function test_bridge_klassci_to_local_resolves_correct_lessons(): void
    {
        $student = $this->student();
        // klassci_classe_id = 5555 ; la classe locale a un id DIFFÉRENT de 5555.
        $classe = $this->enrollThroughKlassciCacheOnly($student, klassciClasseId: 5555);
        $this->assertNotSame(5555, $classe->id, 'Le test doit prouver le pont, pas une coïncidence d\'ids.');
        $this->assertSame(0, $student->classes()->count(), 'Aucun pivot local : seul le cache KLASSCI doit pouvoir répondre.');

        $lesson = $this->publishedLesson($classe->id);
        $this->publishedLesson(
            Classe::factory()->create(['institution_id' => $this->institution->id, 'klassci_id' => 6666])->id
        ); // classe où l'étudiant n'est pas inscrit : absente

        $ids = collect($this->asTenant($student)->getJson('/api/lessons/my-courses')->json('data'))->pluck('id')->all();

        $this->assertSame([$lesson->id], $ids);
    }
}

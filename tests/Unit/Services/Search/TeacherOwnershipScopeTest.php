<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Search\TeacherOwnershipScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrat du collaborateur d'appartenance enseignant (#575).
 *
 * Les assertions portent sur le RÉSULTAT de la requête, jamais sur le SQL
 * produit : `toSql()` renverrait des identifiants entre guillemets doubles sous
 * SQLite et entre accents graves sous MySQL, ce qui casserait le test dès la
 * jambe MySQL de la CI (#574). Le comportement, lui, est identique sur les deux
 * moteurs.
 *
 * @see app/Services/Search/TeacherOwnershipScope.php
 * @see .claude/specs/575-search-teacher-id/design.md §1 (décision d'identité)
 */
final class TeacherOwnershipScopeTest extends TestCase
{
    use RefreshDatabase;

    private TeacherOwnershipScope $scope;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scope = new TeacherOwnershipScope();
        $this->institution = Institution::factory()->create();
    }

    // ───────────────────────── leçons ─────────────────────────

    public function test_lessons_are_scoped_to_the_local_user_id(): void
    {
        $teacher = $this->createTeacher();
        $other = $this->createTeacher();

        $own = $this->createLesson($teacher->id);
        $this->createLesson($other->id);

        $query = Lesson::query();
        $this->scope->applyToLessons($query, $teacher);

        self::assertSame([$own->id], $query->pluck('id')->all());
    }

    /**
     * Garde anti-« filtre tolérant » : `lessons.enseignant_id` porte l'id LOCAL
     * (écrit par LessonCrudOperationsService.php:43). Accepter aussi le
     * `klassci_id` ouvrirait une fuite par collision d'identifiants — un
     * enseignant d'id local 7 verrait les leçons rattachées au KLASSCI id 7.
     */
    public function test_lessons_are_not_matched_by_the_klassci_identifier(): void
    {
        // `klassci_id` volontairement hors de la plage des ids locaux
        // auto-incrémentés : le piège n'est un piège que si les deux diffèrent.
        $teacher = $this->createTeacher(7001, klassciId: 91001);
        $decoy = $this->createTeacher(7002, klassciId: 91002);

        // Une leçon rattachée au klassci_id de l'enseignant — et non à son id local.
        $this->createLesson(91001);
        $this->createLesson($decoy->id);

        $query = Lesson::query();
        $this->scope->applyToLessons($query, $teacher);

        self::assertSame([], $query->pluck('id')->all());
    }

    // ─────────────────────── évaluations ───────────────────────

    public function test_evaluations_are_scoped_to_the_klassci_enseignant_id(): void
    {
        $teacher = $this->createTeacher(7001);
        $other = $this->createTeacher(7002);

        $own = $this->createEvaluation(7001);
        $this->createEvaluation(7002);

        $query = Evaluation::query();
        $this->scope->applyToEvaluations($query, $teacher);

        self::assertSame([$own->id], $query->pluck('id')->all());
        self::assertNotSame($teacher->klassci_enseignant_id, $other->klassci_enseignant_id);
    }

    /**
     * `evaluations.klassci_enseignant_id` porte l'identité KLASSCI enseignant
     * (écrite par EvaluationCreationService.php:110), pas l'id local : comparer
     * `$user->id` rendrait le filtre faux dès que les deux séries d'ids diffèrent.
     */
    public function test_evaluations_are_not_matched_by_the_local_user_id(): void
    {
        $teacher = $this->createTeacher(7001);

        $this->createEvaluation($teacher->id);

        $query = Evaluation::query();
        $this->scope->applyToEvaluations($query, $teacher);

        self::assertSame([], $query->pluck('id')->all());
    }

    /**
     * Sans identité KLASSCI, `where('klassci_enseignant_id', null)` serait
     * réécrit en `whereNull(...)` par Illuminate\Database\Query\Builder::where()
     * et remonterait toutes les évaluations orphelines. Le scope doit fermer.
     */
    public function test_evaluations_are_fail_closed_without_klassci_identity(): void
    {
        $teacher = $this->createTeacher(null);

        $this->createEvaluation(null);
        $this->createEvaluation(7002);

        $query = Evaluation::query();
        $this->scope->applyToEvaluations($query, $teacher);

        self::assertSame([], $query->pluck('id')->all());
    }

    // ───────────────────────── fixtures ─────────────────────────

    private function createTeacher(?int $klassciEnseignantId = 7001, ?int $klassciId = null): User
    {
        $attributes = [
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_enseignant_id' => $klassciEnseignantId,
        ];

        // Laissé à la factory par défaut ; fixé seulement quand le test a besoin
        // d'un klassci_id hors de la plage des ids locaux.
        if ($klassciId !== null) {
            $attributes['klassci_id'] = $klassciId;
        }

        return User::factory()->create($attributes);
    }

    private function createLesson(int $enseignantId): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $enseignantId,
        ]);
    }

    private function createEvaluation(?int $klassciEnseignantId): Evaluation
    {
        return Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => $klassciEnseignantId,
        ]);
    }
}

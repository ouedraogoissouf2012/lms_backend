<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Dashboard\TeacherStatsService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de {@see TeacherStatsService} (#364), PORTÉS par #1624.
 *
 * Ces tests créaient les leçons avec `enseignant_id` = identité KLASSCI de l'enseignant, et des
 * classes et matières aux ids codés en dur, mêlant l'espace local des leçons à l'espace KLASSCI
 * des évaluations. Ils figeaient donc la confusion que #1624 corrige. Ils sont portés :
 * - une leçon porte l'id LOCAL de son auteur (`LessonCrudOperationsService`) ;
 * - classes et matières sont de vraies lignes, et une évaluation KLASSCI compte par le MIROIR
 *   local de sa classe et de sa matière ;
 * - aucune valeur d'id n'est codée en dur (auto-incrément InnoDB entre les tests).
 * Les propriétés éprouvées restent les mêmes : doublons et nuls ignorés, contenu d'un autre
 * enseignant exclu, isolement par école.
 */
final class TeacherStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_TEACHER_ID = 4242;

    private Institution $institutionA;

    private Institution $institutionB;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);
        $this->teacher = $this->enseignant($this->institutionA);

        $this->app->make(TenantManager::class)->set($this->institutionA);
    }

    public function test_counts_lessons_and_distinct_matieres_classes_of_the_teacher(): void
    {
        [$m1, $m2] = [$this->matiere($this->institutionA), $this->matiere($this->institutionA)];
        [$c1, $c2] = [$this->classe($this->institutionA), $this->classe($this->institutionA)];
        // 4 leçons : matières {m1, m2} (doublon m1, un nul ignoré), classes {c1, c2} (doublon c1, un nul ignoré).
        $this->lecon($this->teacher, $c1, $m1);
        $this->lecon($this->teacher, null, $m1);
        $this->lecon($this->teacher, $c1, $m2);
        $this->lecon($this->teacher, $c2, null);

        // Leçon d'un collègue : jamais comptée.
        $this->lecon($this->enseignant($this->institutionA, self::KLASSCI_TEACHER_ID + 1), $this->classe($this->institutionA), $this->matiere($this->institutionA));
        // Deux évaluations KLASSCI de l'enseignant, sur une classe et une matière dont le miroir existe.
        $this->evaluationKlassci($this->institutionA, self::KLASSCI_TEACHER_ID, 8, 30, 2);
        $this->evaluationKlassci($this->institutionA, self::KLASSCI_TEACHER_ID + 1, 99, 99);

        $this->assertSame(
            ['matieres' => 3, 'classes' => 3, 'evaluations' => 2, 'lessons' => 4],
            app(TeacherStatsService::class)->buildStats($this->teacher),
        );
    }

    public function test_teacher_without_content_gets_all_zero_counters(): void
    {
        $this->assertSame(
            ['matieres' => 0, 'classes' => 0, 'evaluations' => 0, 'lessons' => 0],
            app(TeacherStatsService::class)->buildStats($this->teacher),
        );
    }

    public function test_evaluations_contribute_through_their_local_mirror(): void
    {
        $this->evaluationKlassci($this->institutionA, self::KLASSCI_TEACHER_ID, 8, 30, 3);
        $this->lecon($this->teacher, $this->classe($this->institutionA), $this->matiere($this->institutionA));

        $stats = app(TeacherStatsService::class)->buildStats($this->teacher);

        $this->assertSame(3, $stats['evaluations']);
        $this->assertSame(2, $stats['matieres']);
        $this->assertSame(2, $stats['classes']);
        $this->assertSame(1, $stats['lessons']);
    }

    public function test_tenant_a_stats_ignore_identical_teacher_data_from_tenant_b(): void
    {
        $this->lecon($this->teacher, $this->classe($this->institutionA), $this->matiere($this->institutionA));
        // Même identité KLASSCI, école B : exclue.
        $this->lecon($this->enseignant($this->institutionB), $this->classe($this->institutionB), $this->matiere($this->institutionB));
        $this->evaluationKlassci($this->institutionB, self::KLASSCI_TEACHER_ID, 8, 30);

        $stats = app(TeacherStatsService::class)->buildStats($this->teacher);

        $this->assertSame(['matieres' => 1, 'classes' => 1, 'evaluations' => 0, 'lessons' => 1], $stats);
    }

    public function test_tenant_b_stats_only_see_tenant_b_lessons(): void
    {
        $teacherB = $this->enseignant($this->institutionB);
        $classeB = $this->classe($this->institutionB);
        $matiereB = $this->matiere($this->institutionB);
        $this->lecon($teacherB, $classeB, $matiereB);
        $this->lecon($teacherB, $classeB, $matiereB);
        // Bruit côté A avec la même identité KLASSCI.
        $this->lecon($this->teacher, $this->classe($this->institutionA), $this->matiere($this->institutionA));

        $this->app->make(TenantManager::class)->set($this->institutionB);

        $stats = app(TeacherStatsService::class)->buildStats($teacherB);

        $this->assertSame(2, $stats['lessons']);
        $this->assertSame(1, $stats['matieres']);
        $this->assertSame(1, $stats['classes']);
    }

    private function enseignant(Institution $ecole, int $identite = self::KLASSCI_TEACHER_ID): User
    {
        return User::factory()->teacher()->create([
            'institution_id' => $ecole->id, 'klassci_id' => $identite, 'klassci_enseignant_id' => $identite,
        ]);
    }

    private function classe(Institution $ecole, ?int $klassciId = null): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $klassciId]);
    }

    private function matiere(Institution $ecole, ?int $klassciId = null): Matiere
    {
        return Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $klassciId]);
    }

    private function lecon(User $auteur, ?Classe $classe, ?Matiere $matiere): void
    {
        Lesson::factory()->create([
            'institution_id' => $auteur->institution_id, 'enseignant_id' => $auteur->id,
            'classe_id' => $classe?->id, 'matiere_id' => $matiere?->id,
        ]);
    }

    /** Des évaluations KLASSCI synchronisées (sans auteur local), et le miroir de leur classe et de leur matière. */
    private function evaluationKlassci(Institution $ecole, int $enseignant, int $classe, int $matiere, int $nombre = 1): void
    {
        if (! Classe::query()->withoutGlobalScope('institution')->where('institution_id', $ecole->id)->where('klassci_id', $classe)->exists()) {
            $this->classe($ecole, $classe);
            $this->matiere($ecole, $matiere);
        }

        Evaluation::factory()->count($nombre)->create([
            'institution_id' => $ecole->id, 'enseignant_id' => null,
            'klassci_enseignant_id' => $enseignant, 'klassci_classe_id' => $classe, 'klassci_matiere_id' => $matiere,
        ]);
    }
}

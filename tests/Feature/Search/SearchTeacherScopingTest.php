<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cloisonnement enseignant de la recherche globale et des suggestions (#575).
 *
 * Avant correctif, `GlobalSearchService` et `SearchSuggestionsService` filtraient
 * sur une colonne `teacher_id` qui n'existe dans aucune migration :
 *
 *   - sous **MySQL** (production) → erreur 1054 « Unknown column » → 500 ;
 *   - sous **SQLite** (CI) → l'identifiant inconnu entre guillemets doubles est
 *     réinterprété en chaîne littérale, `'teacher_id' = 7` est faux, la requête
 *     réussit et renvoie 0 ligne.
 *
 * D'où des tests qui portent sur la **valeur** retournée et pas seulement sur la
 * forme de la réponse : c'est la seule façon d'attraper les deux modes d'échec
 * avec la même assertion.
 *
 * Les deux enseignants appartiennent à la MÊME institution : ce qui est vérifié
 * ici est le cloisonnement inter-enseignant, pas le cloisonnement inter-tenant
 * (assuré en amont par le scope global `BelongsToInstitution`).
 *
 * @see app/Services/Search/TeacherOwnershipScope.php
 * @see .claude/specs/575-search-teacher-id/design.md
 */
final class SearchTeacherScopingTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_KLASSCI_ENSEIGNANT_ID = 4001;

    private const COLLEAGUE_KLASSCI_ENSEIGNANT_ID = 4002;

    private Institution $institution;

    private User $owner;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        // `isStaff()` inclut l'enseignant : les buckets `classes` et `matieres`
        // appellent KLASSCI. On coupe le réseau pour que le résultat ne dépende
        // que de la base locale.
        Http::fake();

        $this->institution = Institution::factory()->create();

        $this->owner = $this->createTeacher(self::OWNER_KLASSCI_ENSEIGNANT_ID);
        $this->colleague = $this->createTeacher(self::COLLEAGUE_KLASSCI_ENSEIGNANT_ID);
    }

    // ───────────────────────── leçons ─────────────────────────

    public function test_teacher_finds_own_lesson(): void
    {
        $this->seedOneLessonPerTeacher();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/search?query=mathematiques');

        $response->assertOk();
        self::assertSame(1, $response->json('categories.lessons'));
        self::assertSame('Cours de mathematiques', $response->json('results.lessons.0.title'));
    }

    public function test_teacher_does_not_find_colleague_lesson(): void
    {
        $this->seedOneLessonPerTeacher();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/search?query=mathematiques');

        $response->assertOk();
        $titles = array_column((array) $response->json('results.lessons'), 'title');
        self::assertNotContains('Cours de mathematiques du collegue', $titles);
    }

    // ─────────────────────── évaluations ───────────────────────

    public function test_teacher_finds_own_evaluation_with_titre_exposed_as_title(): void
    {
        $this->seedOneEvaluationPerTeacher();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/search?query=mathematiques');

        $response->assertOk();
        self::assertSame(1, $response->json('categories.evaluations'));
        // La clé de réponse reste `title` (contrat client), mais sa valeur vient
        // désormais de la colonne réellement migrée `titre` — elle valait `null`.
        self::assertSame('Devoir de mathematiques', $response->json('results.evaluations.0.title'));
    }

    public function test_teacher_does_not_find_colleague_evaluation(): void
    {
        $this->seedOneEvaluationPerTeacher();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/search?query=mathematiques');

        $response->assertOk();
        $titles = array_column((array) $response->json('results.evaluations'), 'title');
        self::assertNotContains('Devoir de mathematiques du collegue', $titles);
    }

    /**
     * Fail-closed : `where('klassci_enseignant_id', null)` serait réécrit par
     * Laravel en `whereNull(...)` (Query\Builder::where(), branche is_null) et
     * remonterait TOUTES les évaluations orphelines du tenant. Un enseignant
     * sans identité KLASSCI ne peut posséder aucune évaluation
     * (EvaluationCrudController.php:84), il ne doit donc rien voir.
     */
    public function test_teacher_without_klassci_identity_gets_no_evaluation(): void
    {
        $this->createEvaluation(null, 'Devoir de mathematiques orphelin');

        $teacherWithoutKlassciIdentity = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_enseignant_id' => null,
        ]);
        Sanctum::actingAs($teacherWithoutKlassciIdentity);

        $response = $this->getJson('/api/search?query=mathematiques');

        $response->assertOk();
        self::assertSame(0, $response->json('categories.evaluations'));
    }

    // ─────────────────────── suggestions ───────────────────────

    public function test_suggestions_are_scoped_to_the_owning_teacher(): void
    {
        $this->seedOneLessonPerTeacher();
        $this->seedOneEvaluationPerTeacher();
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/search/suggestions?query=mathematiques');

        $response->assertOk();
        /** @var array<int, string> $suggestions */
        $suggestions = $response->json('suggestions');
        self::assertContains('Cours de mathematiques', $suggestions);
        self::assertContains('Devoir de mathematiques', $suggestions);
        self::assertNotContains('Cours de mathematiques du collegue', $suggestions);
        self::assertNotContains('Devoir de mathematiques du collegue', $suggestions);
    }

    // ──────────────── colonne `titre` des évaluations ────────────────

    /**
     * `'title'` n'étant pas une colonne d'`evaluations`, SQLite l'évaluait comme
     * la chaîne littérale « title » : `'title' LIKE '%itl%'` est VRAI, donc le
     * groupe OR devenait vrai pour toutes les lignes et un compte sans filtre de
     * rôle recevait l'intégralité des évaluations, quel que soit le terme cherché.
     */
    public function test_evaluation_search_filters_on_the_titre_column(): void
    {
        $this->createEvaluation(self::OWNER_KLASSCI_ENSEIGNANT_ID, 'Devoir de maths');

        $coordinator = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
        ]);
        Sanctum::actingAs($coordinator);

        // « itl » est un fragment du mot « title » mais d'aucune donnée insérée.
        $response = $this->getJson('/api/search?query=itl');

        $response->assertOk();
        self::assertSame(0, $response->json('categories.evaluations'));
    }

    // ───────────────────────── fixtures ─────────────────────────

    private function createTeacher(int $klassciEnseignantId): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_enseignant_id' => $klassciEnseignantId,
        ]);
    }

    private function seedOneLessonPerTeacher(): void
    {
        $this->createLesson($this->owner, 'Cours de mathematiques');
        $this->createLesson($this->colleague, 'Cours de mathematiques du collegue');
    }

    private function seedOneEvaluationPerTeacher(): void
    {
        $this->createEvaluation(self::OWNER_KLASSCI_ENSEIGNANT_ID, 'Devoir de mathematiques');
        $this->createEvaluation(self::COLLEAGUE_KLASSCI_ENSEIGNANT_ID, 'Devoir de mathematiques du collegue');
    }

    private function createLesson(User $teacher, string $title): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'title' => $title,
            // Description et contenu neutres : la recherche porte sur les trois
            // colonnes, on ne veut pas d'appariement accidentel via Faker.
            'description' => 'Support de cours interne',
            'content' => 'Contenu du support de cours interne',
        ]);
    }

    private function createEvaluation(?int $klassciEnseignantId, string $titre): Evaluation
    {
        return Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => $klassciEnseignantId,
            'titre' => $titre,
            'description' => 'Consignes internes',
        ]);
    }
}

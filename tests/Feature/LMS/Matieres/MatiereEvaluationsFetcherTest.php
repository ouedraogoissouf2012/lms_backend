<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereEvaluationsFetcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #269 — régression : 500 « Call to undefined method
 * App\Models\Evaluation::isLocked() » sur GET /api/lms/matieres/{id} quand la
 * matière possède une version en ligne (KLASSCI ↔ LMS).
 *
 * Cause : la branche « version en ligne » de `enrichKlassciEvaluation` appelait
 * `$quizLMS->isLocked()` / `->canBeEdited()` — méthodes inexistantes sur le
 * modèle. Le verrou est porté par {@see \App\Services\Evaluation\EvaluationStateService},
 * déjà injecté et utilisé correctement pour les évaluations LMS pures.
 *
 * §1.4 PRODUCTION_STANDARDS.md — le fetcher ne rappelle plus KLASSCI (`evaluations`
 * global, filtré côté PHP) : il lit `matiereData['evaluations']`, déjà rapatrié par
 * `MatiereInfoFetcher` (`matieres/{id}`) pour la même requête. Vérifié en direct sur
 * 3 matières réelles avant ce changement : mêmes ids, même compte, dans les deux
 * sources. `KlassciProxyService` n'est donc plus une dépendance de cette classe —
 * la preuve tient par construction : ce fichier ne le mocke plus nulle part, sauf
 * pour affirmer explicitement qu'il n'est jamais appelé.
 *
 * @see app/Services/Matiere/MatiereEvaluationsFetcher.php
 * @see app/Services/Evaluation/EvaluationStateService.php
 */
#[CoversClass(MatiereEvaluationsFetcher::class)]
final class MatiereEvaluationsFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const MATIERE_ID = 7;

    private Institution $institution;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);

        // Résout le tenant comme en production (ResolveInstitution middleware) :
        // sans ça la global scope BelongsToInstitution no-op + log un warning.
        app(TenantManager::class)->set($this->institution);

        $this->user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => 'enseignant',
            'klassci_id'     => 1234,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $embeddedEvaluations  Deja present
     *   dans matiereData['evaluations'], comme le rapatrie MatiereInfoFetcher.
     * @return array<string, mixed>
     */
    private function matiereDataWith(array $embeddedEvaluations): array
    {
        return ['evaluations' => $embeddedEvaluations];
    }

    /**
     * Forme RÉELLE d'une évaluation embarquée sous `matieres/{id}`, mesurée en
     * direct sur l'API KLASSCI (matière 3, 9 évaluations) :
     *   id, titre, description, type, status, classe, programmation, publication
     *
     * PAS de clé `matiere` — elle serait redondante puisque ces évaluations sont
     * déjà servies SOUS la matière. C'est la différence avec le catalogue global
     * `GET evaluations`, qui la portait (et où le filtre était indispensable).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function klassciEvaluation(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'     => $id,
            'titre'  => 'Devoir KLASSCI',
            'type'   => 'devoir',
            'status' => 'completed',
            'classe' => ['id' => 1, 'nom' => 'B2 COM'],
        ], $overrides);
    }

    public function test_online_version_unlocked_when_no_submissions(): void
    {
        $quiz = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 100,
            'klassci_matiere_id'    => self::MATIERE_ID,
            'is_locked'             => false,
        ]);

        $matiereData = $this->matiereDataWith([$this->klassciEvaluation(100)]);
        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere($matiereData, self::MATIERE_ID, $this->user);

        $online = $this->onlineVersionFor($result, 100);
        self::assertSame($quiz->id, $online['id']);
        self::assertFalse($online['is_locked'], 'Aucune soumission + flag false → non verrouillée.');
        self::assertTrue($online['can_be_edited'], 'Non verrouillée → éditable.');
    }

    public function test_online_version_locked_when_submissions_exist(): void
    {
        $quiz = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 200,
            'klassci_matiere_id'    => self::MATIERE_ID,
            'is_locked'             => false,
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id'  => $quiz->id,
            'institution_id' => $this->institution->id,
        ]);

        $matiereData = $this->matiereDataWith([$this->klassciEvaluation(200)]);
        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere($matiereData, self::MATIERE_ID, $this->user);

        $online = $this->onlineVersionFor($result, 200);
        self::assertTrue($online['is_locked'], 'Une soumission existe → verrouillée (même flag false).');
        self::assertFalse($online['can_be_edited'], 'Verrouillée → non éditable.');
    }

    public function test_online_version_locked_when_flag_set(): void
    {
        $quiz = Evaluation::factory()->locked()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 300,
            'klassci_matiere_id'    => self::MATIERE_ID,
        ]);

        $matiereData = $this->matiereDataWith([$this->klassciEvaluation(300)]);
        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere($matiereData, self::MATIERE_ID, $this->user);

        $online = $this->onlineVersionFor($result, 300);
        self::assertTrue($online['is_locked'], 'Flag is_locked → verrouillée sans soumission.');
        self::assertFalse($online['can_be_edited']);
        self::assertSame($quiz->id, $online['id']);
    }

    public function test_never_calls_klassci_the_evaluations_key_is_already_in_matiere_data(): void
    {
        // §1.4 — le point de ce correctif. Mock STRICT (aucune attente
        // configurée) : si le fetcher appelait encore requestWithUserToken,
        // Mockery ferait échouer le test au lieu de laisser passer un appel
        // silencieux non vérifié.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });

        $matiereData = $this->matiereDataWith([$this->klassciEvaluation(100)]);
        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere($matiereData, self::MATIERE_ID, $this->user);

        self::assertSame(1, $result['evaluations_raw_count']);
    }

    public function test_keeps_every_evaluation_that_has_no_matiere_key_the_real_embedded_shape(): void
    {
        // NON-REGRESSION du defaut trouve a l'ecran : les evaluations embarquees
        // n'ont PAS de cle `matiere` (deja scopees par l'endpoint). Le filtre
        // `matiere.id === $matiereId`, herite du catalogue global, les rejetait
        // TOUTES — « 0 Evaluations » affiche sur une matiere qui en a 9.
        // Mesure reelle KLASSCI matiere 3 : 9 evaluations, aucune avec `matiere`.
        $matiereData = $this->matiereDataWith([
            $this->klassciEvaluation(21),
            $this->klassciEvaluation(22),
            $this->klassciEvaluation(23),
        ]);

        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere($matiereData, self::MATIERE_ID, $this->user);

        self::assertSame(3, $result['evaluations_raw_count'], 'Aucune evaluation ne doit etre rejetee faute de cle `matiere`.');
        $ids = array_column($result['evaluations_enrichies'], 'id');
        self::assertSame([21, 22, 23], $ids);
    }

    public function test_still_excludes_a_foreign_matiere_when_the_key_is_present(): void
    {
        // Defense conservee : SI KLASSCI venait a porter la cle (changement de
        // contrat), une evaluation d'une autre matiere ne doit pas fuiter.
        $matiereData = $this->matiereDataWith([
            $this->klassciEvaluation(100),
            $this->klassciEvaluation(101, ['matiere' => ['id' => 999, 'nom' => 'Autre matière']]),
        ]);

        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere($matiereData, self::MATIERE_ID, $this->user);

        self::assertSame(1, $result['evaluations_raw_count']);
        $ids = array_column($result['evaluations_enrichies'], 'id');
        self::assertContains(100, $ids);
        self::assertNotContains(101, $ids);
    }

    public function test_absent_evaluations_key_yields_empty_list_not_an_error(): void
    {
        // matiereData sans clé 'evaluations' (forme dégradée, ou KLASSCI
        // omettant la clé) → liste vide, jamais une erreur.
        $result = app(MatiereEvaluationsFetcher::class)
            ->fetchEvaluationsForMatiere([], self::MATIERE_ID, $this->user);

        self::assertSame(0, $result['evaluations_raw_count']);
        self::assertSame([], $result['evaluations_enrichies']);
    }

    /**
     * Récupère le bloc `online_version` de l'évaluation KLASSCI d'`id` donné.
     *
     * @param  array{evaluations_enrichies: array<int, array<string, mixed>>, evaluations_raw_count: int}  $result
     * @return array<string, mixed>
     */
    private function onlineVersionFor(array $result, int $klassciId): array
    {
        foreach ($result['evaluations_enrichies'] as $eval) {
            if (($eval['id'] ?? null) === $klassciId) {
                self::assertTrue($eval['has_online'], 'La version en ligne doit être détectée.');
                self::assertIsArray($eval['online_version']);

                return $eval['online_version'];
            }
        }

        self::fail("Évaluation KLASSCI {$klassciId} absente du résultat enrichi.");
    }
}

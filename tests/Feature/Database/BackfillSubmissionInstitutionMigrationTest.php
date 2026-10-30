<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La migration `2026_10_29_200000` rattache à l'établissement de leur
 * évaluation les copies écrites sans lui.
 *
 * La propriété d'une copie porte l'établissement (`scopeOwnedBy`). Une copie
 * nulle n'appartient plus à personne. Une tentative ouverte ainsi orpheline
 * faisait même échouer `/start` sur l'index unique. Le dernier test le
 * reproduit, et montre que la migration le répare.
 *
 * @see database/migrations/2026_10_29_200000_backfill_institution_id_on_evaluation_submissions.php
 */
final class BackfillSubmissionInstitutionMigrationTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const KLASSCI_ID = 910001;

    private const CLASSE = 4242;

    /**
     * La migration est anonyme : on la charge par son chemin, comme le migrateur,
     * plutôt que d'en recopier la logique.
     */
    private function jouerLaMigration(): void
    {
        $migration = require database_path('migrations/2026_10_29_200000_backfill_institution_id_on_evaluation_submissions.php');

        $migration->up();
    }

    public function test_une_copie_sans_etablissement_recoit_celui_de_son_evaluation(): void
    {
        $ecole = Institution::factory()->create();
        $copie = $this->copieHeritee($this->evaluation($ecole->id));

        $this->jouerLaMigration();

        self::assertSame($ecole->id, $this->etablissementDe($copie));
    }

    public function test_une_copie_deja_rattachee_n_est_pas_touchee(): void
    {
        // Même incohérente avec son évaluation : la migration ne corrige que le nul.
        $ecole = Institution::factory()->create();
        $autre = Institution::factory()->create();
        $copie = $this->copieHeritee($this->evaluation($ecole->id), $autre->id);

        $this->jouerLaMigration();

        self::assertSame($autre->id, $this->etablissementDe($copie));
    }

    /** Limite DÉCLARÉE : une évaluation elle-même sans établissement ne donne rien à recopier. */
    public function test_une_copie_d_une_evaluation_orpheline_reste_sans_etablissement(): void
    {
        $copie = $this->copieHeritee($this->evaluation(null));

        $this->jouerLaMigration();

        self::assertNull($this->etablissementDe($copie));
    }

    /** Plusieurs lots de 500 : aucune copie n'est sautée d'un lot à l'autre. */
    public function test_elle_rattache_toutes_les_copies_sur_plusieurs_lots(): void
    {
        $ecole = Institution::factory()->create();
        $evaluation = $this->evaluation($ecole->id);
        $lignes = [];
        for ($i = 1; $i <= 1201; $i++) {
            $lignes[] = [
                'evaluation_id' => $evaluation->id, 'klassci_etudiant_id' => 500000 + $i, 'attempt' => 1,
                'status' => 'soumis', 'institution_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($lignes, 200) as $paquet) {
            DB::table('evaluation_submissions')->insert($paquet);
        }

        $this->jouerLaMigration();

        self::assertSame(0, DB::table('evaluation_submissions')->whereNull('institution_id')->count());
        self::assertSame(1201, DB::table('evaluation_submissions')->where('institution_id', $ecole->id)->count());
    }

    /** Une évaluation supprimée (soft delete) garde son école : sa copie la reçoit aussi. */
    public function test_une_copie_d_une_evaluation_supprimee_recoit_son_etablissement(): void
    {
        $ecole = Institution::factory()->create();
        $evaluation = $this->evaluation($ecole->id);
        $copie = $this->copieHeritee($evaluation);
        $evaluation->delete();

        $this->jouerLaMigration();

        self::assertNotNull(DB::table('evaluations')->where('id', $evaluation->id)->value('deleted_at'));
        self::assertSame($ecole->id, $this->etablissementDe($copie));
    }

    /**
     * SANS le rattrapage, la tentative héritée fait échouer `/start` sur l'index
     * unique. L'erreur de base remonte au gestionnaire global, qui la RAPPORTE :
     * le classement des échecs KLASSCI ne l'avale pas.
     */
    public function test_sans_rattrapage_l_erreur_d_index_est_rapportee_et_non_avalee(): void
    {
        Exceptions::fake();
        $this->disableKlassciMiddleware();
        $ecole = Institution::factory()->create();
        $eleve = User::factory()->create([
            'institution_id' => $ecole->id, 'role' => 'etudiant',
            'klassci_id' => self::KLASSCI_ID, 'klassci_token' => 'jeton-eleve',
        ]);
        $evaluation = $this->evaluation($ecole->id);
        $this->copieHeritee($evaluation);
        $this->klassciRendLaClasse();

        $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(500);

        // `assertReported` compare la classe EXACTE (`get_class`), avec une chaîne
        // comme avec une closure typée (ExceptionHandlerFake::assertReported).
        // Or le pilote lève une SOUS-classe de QueryException,
        // `UniqueConstraintViolationException`. On lit donc la liste du fake et
        // on teste par `instanceof`, ce qui vaut pour SQLite comme pour MySQL.
        // NE PAS « simplifier » en `Exceptions::assertReported(QueryException::class)` :
        // ce test redeviendrait rouge alors que l'erreur EST rapportée.
        $rapportee = collect(Exceptions::reported())->contains(
            static fn (\Throwable $e): bool => $e instanceof QueryException && str_contains($e->getMessage(), 'evaluation_submissions'),
        );
        self::assertTrue($rapportee, 'l erreur de base n a pas ete rapportee au gestionnaire global');
        self::assertSame(1, DB::table('evaluation_submissions')->count());
    }

    public function test_la_rejouer_ne_change_rien(): void
    {
        $ecole = Institution::factory()->create();
        $copie = $this->copieHeritee($this->evaluation($ecole->id));

        $this->jouerLaMigration();
        $this->jouerLaMigration();

        self::assertSame($ecole->id, $this->etablissementDe($copie));
        self::assertSame(1, DB::table('evaluation_submissions')->count());
    }

    /**
     * Le mode de panne réel : une tentative OUVERTE héritée, sans établissement.
     * Avant le rattrapage, la portée de propriété ne la voit pas : `/start`
     * voudrait créer la tentative n° 1, déjà prise, et l'index unique échoue.
     * Après, `/start` la reprend, sans 500 et sans seconde copie.
     */
    public function test_une_tentative_heritee_rattrapee_est_reprise_par_start(): void
    {
        $this->disableKlassciMiddleware();
        $ecole = Institution::factory()->create();
        $eleve = User::factory()->create([
            'institution_id' => $ecole->id, 'role' => 'etudiant',
            'klassci_id' => self::KLASSCI_ID, 'klassci_token' => 'jeton-eleve',
        ]);
        $evaluation = $this->evaluation($ecole->id);
        $tentative = $this->copieHeritee($evaluation);
        $this->klassciRendLaClasse();

        $this->jouerLaMigration();
        $reprise = $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start");

        $reprise->assertOk()->assertJsonPath('message', 'Reprise de la tentative en cours')->assertJsonPath('data.id', $tentative);
        self::assertSame(1, DB::table('evaluation_submissions')->count());
    }

    /** KLASSCI rend la classe de l'élève, et aucune fenêtre. */
    private function klassciRendLaClasse(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(static fn (string $jeton, string $endpoint): array => $endpoint === 'me/dashboard'
                    ? ['data' => ['classe' => ['id' => self::CLASSE, 'name' => 'L1']]]
                    : ['data' => []]);
        });
    }

    private function evaluation(?int $institutionId): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $institutionId,
            'klassci_classe_id' => self::CLASSE,
            'status' => 'planifiee',
            'is_published' => true,
            'is_online' => true,
            'max_attempts' => 3,
            'allow_retake' => true,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $institutionId,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    /** Une copie écrite comme avant l'ajout de la colonne : sans établissement (ou avec celui donné). */
    private function copieHeritee(Evaluation $evaluation, ?int $institutionId = null): int
    {
        return (int) DB::table('evaluation_submissions')->insertGetId([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => self::KLASSCI_ID,
            'attempt' => 1,
            'status' => 'en_cours',
            'started_at' => now(),
            'institution_id' => $institutionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function etablissementDe(int $copie): ?int
    {
        $valeur = DB::table('evaluation_submissions')->where('id', $copie)->value('institution_id');

        return $valeur === null ? null : (int) $valeur;
    }
}

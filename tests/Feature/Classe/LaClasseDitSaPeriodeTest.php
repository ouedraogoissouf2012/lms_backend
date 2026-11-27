<?php

declare(strict_types=1);

namespace Tests\Feature\Classe;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1785 (prérequis de front#707) — la ligne d'une classe LOCALE de `GET /proxy/classes` dit sa période,
 * `periode: {id, libelle} | null`, pour que l'écran filtre les classes (et les séances, par leur classe) par la
 * période en cours (#1651).
 *
 * - Lue sur `classes.training_session_id`, par le chargement groupé déjà en place : aucune requête par classe.
 * - Sans période, ou période supprimée : `null`, comme `is_active` (rien n'est inventé).
 * - École KLASSCI : la réponse de KLASSCI est relayée TELLE QUELLE (contrat de la route) ; aucune période LMS n'y est
 *   ajoutée.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class LaClasseDitSaPeriodeTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const LISTE = '/api/proxy/classes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_chaque_classe_dit_sa_periode_ou_null(): void
    {
        $ecole = $this->ecole();
        $periode = $this->periodeDe($ecole, 'Semestre 1');
        $this->classe($ecole, 'A rattachée')->forceFill(['training_session_id' => $periode->id])->save();
        $this->classe($ecole, 'B sans période');

        $data = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::LISTE)->assertOk()->json('data');

        self::assertSame(
            [['id' => $periode->id, 'libelle' => 'Semestre 1'], null],
            array_column($data, 'periode'),
        );
    }

    public function test_une_periode_supprimee_ne_se_dit_plus(): void
    {
        $ecole = $this->ecole();
        $periode = $this->periodeDe($ecole, 'Semestre 1');
        $this->classe($ecole, 'A')->forceFill(['training_session_id' => $periode->id])->save();
        $periode->delete();

        $this->assertPeriodeNulle($this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::LISTE)->assertOk()->json('data.0'));
    }

    public function test_une_periode_d_une_autre_ecole_ne_se_dit_pas(): void
    {
        $ecole = $this->ecole();
        $ailleurs = $this->periodeDe($this->ecole(), 'Autre école');
        $this->classe($ecole, 'A')->forceFill(['training_session_id' => $ailleurs->id])->save();

        $this->assertPeriodeNulle($this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::LISTE)->assertOk()->json('data.0'));
    }

    public function test_aucune_requete_par_classe(): void
    {
        $ecole = $this->ecole();
        foreach (['S1', 'S2', 'S3'] as $i => $libelle) {
            $periode = $this->periodeDe($ecole, $libelle);
            $this->classe($ecole, "Classe {$i}")->forceFill(['training_session_id' => $periode->id])->save();
        }
        $lecteur = $this->membre($ecole, 'coordinateur');
        $periodes = 0;
        DB::listen(static function (QueryExecuted $requete) use (&$periodes): void {
            if (str_contains(str_replace(['"', '`'], '', $requete->sql), 'from training_sessions')) {
                $periodes++;
            }
        });

        $this->asTenant($lecteur)->getJson(self::LISTE)->assertOk();

        self::assertSame(1, $periodes, 'Les périodes des classes se lisent en UNE requête.');
    }

    public function test_l_ecole_klassci_recoit_la_reponse_de_klassci_telle_quelle(): void
    {
        $classe = ['id' => 12, 'name' => 'B2 COM', 'libelle' => null];
        Http::fake(static fn () => Http::response(['success' => true, 'data' => [$classe]], 200));
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);

        $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::LISTE)
            ->assertOk()->assertExactJson(['success' => true, 'data' => [$classe]]);
    }

    /** La clé est PRÉSENTE et vaut null : une clé absente rendrait `null` aussi, sans rien prouver. */
    private function assertPeriodeNulle(mixed $ligne): void
    {
        self::assertIsArray($ligne);
        self::assertArrayHasKey('periode', $ligne);
        self::assertNull($ligne['periode']);
    }

    private function periodeDe(Institution $ecole, string $libelle): TrainingSession
    {
        return TrainingSession::factory()->create([
            'institution_id' => $ecole->id,
            'program_id' => Program::factory()->create(['institution_id' => $ecole->id])->id,
            'libelle' => $libelle,
            'status' => TrainingSessionStatus::Publiee,
        ]);
    }
}

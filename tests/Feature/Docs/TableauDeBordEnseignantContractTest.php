<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /proxy/me/teacher-dashboard`, le tableau de bord de
 * l'enseignant (R4) : même route, autre source (ADR-EI-01).
 *
 * - École en mode KLASSCI : la charge de KLASSCI, relayée TELLE QUELLE avec le
 *   jeton du compte. Sans jeton, le 401 motivé.
 * - École en mode autonome : la même forme, lue en local, sans jeton ni appel
 *   KLASSCI, marquée `meta.source: lms_local`, que la réponse KLASSCI ne porte
 *   pas. Les matières et les classes de l'enseignant (avec leur effectif),
 *   ses séances des 30 prochains jours, et ses heures. `evaluations` est ABSENT,
 *   et non vide : la fenêtre d'une évaluation locale n'est pas encore lue ici,
 *   et une liste vide affirmerait « aucune évaluation en cours ».
 *
 * Chaque réponse est lue contre `docs/openapi.yaml`. Vrai jeton porteur.
 */
final class TableauDeBordEnseignantContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const URL = '/api/proxy/me/teacher-dashboard';

    private const CHEMIN = '/proxy/me/teacher-dashboard';

    private const JETON = 'jeton-enseignant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Carbon::setTestNow('2026-11-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- École en mode KLASSCI : le relais, inchangé ----------------------

    public function test_ecole_klassci_la_charge_de_klassci_est_relayee_telle_quelle(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with(self::JETON, 'me/teacher-dashboard', 'GET')
                ->andReturn(self::chargeKlassci());
        });
        $enseignant = $this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant');
        $enseignant->forceFill(['klassci_token' => self::JETON])->save();

        $reponse = $this->asTenant($enseignant)->getJson(self::URL)->assertOk();

        // Exactement la charge relayée : aucune clé ajoutée, `meta.source` compris.
        $reponse->assertExactJson(self::chargeKlassci());
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    public function test_ecole_klassci_sans_jeton_le_401_motive_sans_appel(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        $enseignant = $this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant');

        $reponse = $this->asTenant($enseignant)->getJson(self::URL)->assertUnauthorized();

        $reponse->assertExactJson(MissingKlassciTokenException::payload());
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    // --- École en mode autonome : la source locale -------------------------

    public function test_ecole_autonome_le_tableau_de_bord_est_lu_en_local_sans_jeton_ni_appel_klassci(): void
    {
        $this->aucunAppelKlassci();
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $enseignant);
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'inactif');
        $demain = $this->seance($ecole, $enseignant, '2026-11-11 08:00:00', $classe);
        $this->seance($ecole, $enseignant, '2026-11-07 08:00:00', $classe);
        $this->seance($ecole, $collegue, '2026-11-11 10:00:00', $classe);

        $reponse = $this->asTenant($enseignant)->getJson(self::URL)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('success', true);
        // Le front distingue ainsi un vrai 0 d'un 0 fabriqué : les ids sont LOCAUX.
        $reponse->assertJsonPath('meta', ['source' => 'lms_local']);
        self::assertSame([$maths->id], array_column((array) $reponse->json('data.matieres'), 'id'));
        $reponse->assertJsonPath('data.classes', [[
            'id' => $classe->id,
            'libelle' => '6e A',
            'code' => $classe->code,
            'effectif' => null,
            'effectif_actuel' => 2,
        ]]);
        self::assertSame([$demain->id], array_column((array) $reponse->json('data.prochaines_seances'), 'id'));
        $reponse->assertJsonPath('data.statistiques.heures', [
            'total_seances' => 2,
            'seances_effectuees' => 1,
            'fin_estimee' => true,
        ]);
        $reponse->assertJsonMissingPath('data.evaluations');
        $reponse->assertJsonMissingPath('data.statistiques.evaluations');
    }

    /**
     * Une séance locale n'a pas d'heure de fin : elle est supposée durer deux
     * heures (`SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES`, lot 3d). Elle
     * ne compte pour « effectuée » qu'après cette fin estimée, et la réponse
     * le dit (`fin_estimee: true`).
     */
    public function test_ecole_autonome_une_seance_n_est_effectuee_qu_apres_sa_fin_estimee(): void
    {
        $this->aucunAppelKlassci();
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $this->seance($ecole, $enseignant, '2026-11-10 07:00:00');
        $this->seance($ecole, $enseignant, '2026-11-10 08:00:00');
        $this->seance($ecole, $enseignant, '2026-11-10 09:00:00');
        $this->seance($ecole, $enseignant, '2026-11-12 09:00:00');

        $this->asTenant($enseignant)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.statistiques.heures', [
                'total_seances' => 4,
                'seances_effectuees' => 2,
                'fin_estimee' => true,
            ]);
    }

    /** Une école basculée en mode autonome : le jeton gardé par le compte n'est pas lu. */
    public function test_ecole_autonome_un_compte_qui_garde_un_jeton_lit_la_source_locale(): void
    {
        $this->aucunAppelKlassci();
        $enseignant = $this->membre($this->ecole(), 'enseignant');
        $enseignant->forceFill(['klassci_token' => self::JETON])->save();

        $reponse = $this->asTenant($enseignant)->getJson(self::URL)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('data.matieres', [])
            ->assertJsonPath('data.classes', [])
            ->assertJsonPath('data.prochaines_seances', []);
    }

    /** Le coordinateur, que la route admet aussi, lit SES séances, comme un enseignant. */
    public function test_ecole_autonome_le_coordinateur_lit_ses_propres_seances(): void
    {
        $this->aucunAppelKlassci();
        $ecole = $this->ecole();
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $sienne = $this->seance($ecole, $coordinateur, '2026-11-11 08:00:00');
        $this->seance($ecole, $this->membre($ecole, 'enseignant'), '2026-11-11 10:00:00');

        $reponse = $this->asTenant($coordinateur)->getJson(self::URL)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        self::assertSame([$sienne->id], array_column((array) $reponse->json('data.prochaines_seances'), 'id'));
        $reponse->assertJsonPath('data.statistiques.heures.total_seances', 1);
    }

    private function aucunAppelKlassci(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
    }

    /** Une séance locale, telle que `LocalSeanceCreator` l'écrit. */
    private function seance(Institution $ecole, User $auteur, string $debut, ?Classe $classe = null): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'created_by' => $auteur->id,
            'classe_id' => $classe?->id,
            'date_seance' => $debut,
            'is_active' => true,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    /**
     * La forme réelle de `me/teacher-dashboard`, mesurée avec un jeton
     * enseignant (front, `utils/teacherStats.js`) : des classes sans effectif,
     * `prochaines_seances`, et deux blocs de statistiques.
     *
     * @return array<string, mixed>
     */
    private static function chargeKlassci(): array
    {
        return [
            'success' => true,
            'data' => [
                'matieres' => [['id' => 3, 'nom' => 'Anglais']],
                'classes' => [[
                    'id' => 4,
                    'name' => 'BTS Genie Civil',
                    'libelle' => 'BTS Genie Civil',
                    'filiere' => null,
                    'niveau' => ['id' => 1, 'nom' => 'BTS 1ere ANNEE', 'code' => '1A'],
                ]],
                'evaluations' => [],
                'prochaines_seances' => [],
                'statistiques' => [
                    'heures' => ['total_seances' => 105, 'seances_effectuees' => 0],
                    'evaluations' => ['total_programmees' => 27, 'a_corriger' => 0],
                ],
            ],
        ];
    }
}

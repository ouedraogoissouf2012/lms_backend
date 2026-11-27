<?php

declare(strict_types=1);

namespace Tests\Feature\Classe;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1709 — les classes de l'école, par leur id LOCAL, dans les deux volets.
 *
 * Elle peuple le filtre de classe du rapport de présences : elle est lue par qui peut
 * demander ce rapport (la gestion), et ne porte que `id` et `libelle`. Rien du code
 * d'inscription, que sert `GET /classes` à qui l'émet.
 *
 * Jeton Bearer RÉEL, par `asTenant()` : c'est le scope de l'école qui est éprouvé.
 */
final class ClassesDeLEtablissementTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ROUTE = '/api/lms/classes/etablissement';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_ecole_autonome_la_gestion_lit_ses_classes_par_libelle_et_rien_d_autre(): void
    {
        $ecole = $this->ecole();
        $b = $this->classe($ecole, 'Terminale B');
        $a = $this->classe($ecole, 'Terminale A');
        $this->classe($this->ecole(), 'Classe voisine');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::ROUTE)->assertOk();

        $this->assertSame([
            ['id' => $a->getKey(), 'libelle' => 'Terminale A'],
            ['id' => $b->getKey(), 'libelle' => 'Terminale B'],
        ], $reponse->json('data'));
        $this->assertSame(2, $reponse->json('meta.total'));
    }

    public function test_ecole_klassci_rend_l_id_local_des_classes_miroirs_jamais_leur_id_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $miroir = Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => 987654,
            'libelle' => 'L1 Info',
        ]);

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))->getJson(self::ROUTE)->assertOk();

        $this->assertSame([['id' => $miroir->getKey(), 'libelle' => 'L1 Info']], $reponse->json('data'));
        $this->assertNotSame(987654, $miroir->getKey());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesHorsGestion(): array
    {
        return ['enseignant' => ['enseignant'], 'etudiant' => ['etudiant']];
    }

    #[DataProvider('rolesHorsGestion')]
    public function test_hors_gestion_le_refus_est_un_403(string $role): void
    {
        $ecole = $this->ecole();
        $this->classe($ecole, 'Terminale A');

        $this->asTenant($this->membre($ecole, $role))->getJson(self::ROUTE)->assertForbidden();
    }

    public function test_sans_jeton_le_refus_est_un_401(): void
    {
        $this->getJson(self::ROUTE)->assertUnauthorized();
    }

    public function test_la_page_est_bornee_comme_les_autres_listes(): void
    {
        $ecole = $this->ecole();

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::ROUTE.'?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * `/lms/classes/{classeId}` n'a pas de contrainte numérique : déclarée après elle,
     * la route littérale serait capturée comme l'id « etablissement ».
     */
    public function test_le_segment_litteral_n_est_pas_capture_par_la_fiche_d_une_classe(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertSame(
            'lms.classes.etablissement',
            $routes->match(Request::create(self::ROUTE, 'GET'))->getName(),
        );
        $this->assertSame(
            'lms.classes.details',
            $routes->match(Request::create('/api/lms/classes/12', 'GET'))->getName(),
        );
    }

    public function test_la_reponse_suit_le_contrat(): void
    {
        $ecole = $this->ecole();
        $this->classe($ecole, 'Terminale A');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::ROUTE)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, '/lms/classes/etablissement', 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, '/lms/classes/etablissement', 'get', 'data');
    }
}

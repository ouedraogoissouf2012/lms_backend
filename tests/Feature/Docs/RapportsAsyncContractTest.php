<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Report\AsyncReportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * D10 — contrat des rapports : `POST /admin/reports/*`, le suivi et le
 * téléchargement asynchrones. Chaque réponse JSON RÉELLE suit ce que
 * `docs/openapi.yaml` en dit, et chaque schéma prouve qu'il mord. Un fichier se
 * vérifie par son statut et son type. Deux volets (école KLASSCI et école
 * autonome) ; le mode n'est pas lu. Vrai jeton porteur.
 *
 * @see app/Http/Controllers/API/ReportController.php
 */
final class RapportsAsyncContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const DATES = ['date_start' => '2026-10-01', 'date_end' => '2026-10-31'];

    private const SUIVI = '/admin/reports/async/{id}';

    private const TELECHARGEMENT = '/admin/reports/async/{id}/download';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Storage::fake('local');
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function rapports(): iterable
    {
        foreach (['école KLASSCI' => false, 'école autonome' => true] as $volet => $autonome) {
            foreach (['attendance', 'grades', 'activity'] as $rapport) {
                yield "$rapport, $volet" => [$autonome, $rapport];
            }
        }
    }

    #[DataProvider('rapports')]
    public function test_202_par_defaut_puis_le_suivi_jusqu_au_fichier(bool $autonome, string $rapport): void
    {
        $coordinateur = $this->compte($this->ecole($autonome), 'coordinateur');

        $accepte = $this->asTenant($coordinateur)->postJson("/api/admin/reports/$rapport", self::DATES)->assertStatus(202);
        $this->assertMatchesOpenApiResponse($accepte, "/admin/reports/$rapport", 'post');
        $this->assertOpenApiSchemaRefuseSans($accepte, "/admin/reports/$rapport", 'post', 'data');
        $this->assertLeSchemaExigeDansData($accepte, "/admin/reports/$rapport", 'post', 'id');
        $id = (string) $accepte->json('data.id');
        self::assertSame("/api/admin/reports/async/$id", $accepte->json('data.status_url'));

        $this->suivi($coordinateur, $id, 'pending');
        $this->assertMatchesOpenApiResponse(
            $this->asTenant($coordinateur)->getJson("/api/admin/reports/async/$id/download")->assertStatus(409),
            self::TELECHARGEMENT,
            'get',
        );

        $this->app->make(AsyncReportStore::class)->running($id);
        $this->suivi($coordinateur, $id, 'running');

        Artisan::call('queue:work', ['--once' => true, '--queue' => 'low', '--stop-when-empty' => true]);
        self::assertNotNull($this->suivi($coordinateur, $id, 'completed')->json('data.filename'));

        $fichier = $this->asTenant($coordinateur)->get("/api/admin/reports/async/$id/download")->assertOk();
        self::assertSame('application/pdf', $fichier->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $fichier->headers->get('Content-Disposition'));
        $this->assertTypeDeclareParLaSpec($fichier, self::TELECHARGEMENT, 'get');
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    #[DataProvider('volets')]
    public function test_un_echec_se_lit_dans_le_suivi(bool $autonome): void
    {
        $coordinateur = $this->compte($this->ecole($autonome), 'coordinateur');
        $id = (string) $this->asTenant($coordinateur)->postJson('/api/admin/reports/grades', self::DATES)->json('data.id');

        $this->app->make(AsyncReportStore::class)->failed($id, 'report_generation_failed');

        self::assertSame('report_generation_failed', $this->suivi($coordinateur, $id, 'failed')->json('data.message'));
    }

    /**
     * Un rapport d'un autre utilisateur, un identifiant inconnu et un rapport
     * expiré rendent le MÊME 404, au suivi comme au téléchargement.
     */
    #[DataProvider('volets')]
    public function test_404_le_meme_refus_pour_ce_qui_n_est_pas_a_soi(bool $autonome): void
    {
        $ecole = $this->ecole($autonome);
        $demandeur = $this->compte($ecole, 'coordinateur');
        $id = (string) $this->asTenant($demandeur)->postJson('/api/admin/reports/grades', self::DATES)->json('data.id');

        $voisin = $this->asTenant($this->compte($ecole, 'coordinateur'))->getJson("/api/admin/reports/async/$id")->assertNotFound();
        $inconnu = $this->asTenant($demandeur)->getJson('/api/admin/reports/async/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->travel(25)->hours();
        $expire = $this->asTenant($demandeur)->getJson("/api/admin/reports/async/$id")->assertNotFound();
        $this->travelBack();

        $this->assertMatchesOpenApiResponse($voisin, self::SUIVI, 'get');
        $this->assertOpenApiSchemaRefuseSans($voisin, self::SUIVI, 'get', 'message');
        self::assertSame([$voisin->getContent(), $voisin->getContent()], [$inconnu->getContent(), $expire->getContent()]);
    }

    public function test_404_un_fichier_disparu(): void
    {
        $coordinateur = $this->compte($this->ecole(true), 'coordinateur');
        $id = (string) $this->asTenant($coordinateur)->postJson('/api/admin/reports/grades', self::DATES)->json('data.id');
        $this->app->make(AsyncReportStore::class)->completed($id, 'reports/async/absent.pdf', 'rapport.pdf');

        $reponse = $this->asTenant($coordinateur)->getJson("/api/admin/reports/async/$id/download")->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::TELECHARGEMENT, 'get');
        $reponse->assertExactJson(['success' => false, 'message' => 'Fichier introuvable']);
    }

    #[DataProvider('volets')]
    public function test_200_synchrone_sur_demande_et_l_asynchrone_l_emporte(bool $autonome): void
    {
        $coordinateur = $this->compte($this->ecole($autonome), 'coordinateur');

        $pdf = $this->asTenant($coordinateur)->post('/api/admin/reports/grades?sync=1', self::DATES)->assertOk();
        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertTypeDeclareParLaSpec($pdf, '/admin/reports/grades', 'post');

        $this->assertMatchesOpenApiResponse(
            $this->asTenant($coordinateur)->postJson('/api/admin/reports/grades?sync=1&async=1', self::DATES)->assertStatus(202),
            '/admin/reports/grades',
            'post',
        );
    }

    /** Une séance : toujours synchrone, en PDF ou en tableur, même pour son enseignant. */
    #[DataProvider('volets')]
    public function test_200_la_seance_de_l_enseignant(bool $autonome): void
    {
        $ecole = $this->ecole($autonome);
        $enseignant = $this->compte($ecole, 'enseignant');
        $seance = Seance::query()->create(['titre' => 'Séance', 'date_seance' => '2026-10-10 08:00:00', 'created_by' => $enseignant->id,
            'institution_id' => $ecole->id, 'is_active' => true]);

        $pdf = $this->asTenant($enseignant)->post('/api/admin/reports/attendance', ['seance_id' => $seance->id])->assertOk();
        $tableur = $this->asTenant($enseignant)->post('/api/admin/reports/attendance', ['seance_id' => $seance->id, 'format' => 'excel'])->assertOk();

        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        self::assertSame('application/vnd.ms-excel', $tableur->headers->get('Content-Type'));
        $this->assertTypeDeclareParLaSpec($pdf, '/admin/reports/attendance', 'post');
        $this->assertTypeDeclareParLaSpec($tableur, '/admin/reports/attendance', 'post');
    }

    /** @return iterable<string, array{0: bool, 1: string, 2: string}> */
    public static function refus403(): iterable
    {
        foreach (['école KLASSCI' => false, 'école autonome' => true] as $volet => $autonome) {
            yield "enseignant, présences sans séance (requête), $volet" => [$autonome, 'POST', '/api/admin/reports/attendance'];
            yield "enseignant, notes (route), $volet" => [$autonome, 'POST', '/api/admin/reports/grades'];
            yield "enseignant, suivi (route), $volet" => [$autonome, 'GET', '/api/admin/reports/async/00000000-0000-0000-0000-000000000000'];
        }
    }

    #[DataProvider('refus403')]
    public function test_403_un_role_non_admis(bool $autonome, string $methode, string $url): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole($autonome), 'enseignant'))->json($methode, $url, self::DATES)->assertForbidden();

        $chemin = str_contains($url, '/async/') ? self::SUIVI : (string) preg_replace('~^/api~', '', $url);
        $this->assertMatchesOpenApiResponse($reponse, $chemin, strtolower($methode));
        $this->assertOpenApiSchemaRefuseSans($reponse, $chemin, strtolower($methode), 'success');
    }

    #[DataProvider('volets')]
    public function test_422_des_dates_inversees(bool $autonome): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole($autonome), 'coordinateur'))
            ->postJson('/api/admin/reports/grades', ['date_start' => '2026-10-31', 'date_end' => '2026-10-01'])->assertUnprocessable();

        $this->assertMatchesOpenApiResponse($reponse, '/admin/reports/grades', 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, '/admin/reports/grades', 'post', 'errors');
    }

    public function test_401_sans_jeton(): void
    {
        $poste = $this->postJson('/api/admin/reports/grades', self::DATES)->assertUnauthorized();
        $suivi = $this->getJson('/api/admin/reports/async/00000000-0000-0000-0000-000000000000')->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($poste, '/admin/reports/grades', 'post');
        $this->assertMatchesOpenApiResponse($suivi, self::SUIVI, 'get');
        $this->assertOpenApiSchemaRefuseSans($suivi, self::SUIVI, 'get', 'message');
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function suivi(User $demandeur, string $id, string $statut): TestResponse
    {
        $reponse = $this->asTenant($demandeur)->getJson("/api/admin/reports/async/$id")->assertOk()->assertJsonPath('data.status', $statut);
        $this->assertMatchesOpenApiResponse($reponse, self::SUIVI, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::SUIVI, 'get', 'data');
        $this->assertLeSchemaExigeDansData($reponse, self::SUIVI, 'get', 'meta');

        return $reponse;
    }

    /**
     * Le schéma mord AUSSI dans `data` : la réponse réelle privée d'une clé de
     * `data` est refusée. Sans cette preuve, un `required` imbriqué retiré de la
     * spec passerait en silence (mesuré : falsification « meta retiré »).
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $reponse
     */
    private function assertLeSchemaExigeDansData(TestResponse $reponse, string $chemin, string $methode, string $cle): void
    {
        $spec = OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $schema = $spec->schemaDeReponse($chemin, $methode, $reponse->status());
        self::assertNotNull($schema);
        $corps = json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(isset($corps->data) && property_exists($corps->data, $cle), "la réponse n'a pas de data.$cle à retirer");
        unset($corps->data->$cle);

        self::assertNotSame([], $spec->erreurs($corps, $schema), "le schéma admet la réponse sans data.$cle");
    }

    /**
     * Un FICHIER suit la spec par son type : le Content-Type rendu est l'un des
     * types que la spec déclare sous `content` pour ce chemin, cette méthode et ce
     * statut. Sur une spec qui ne décrit pas la route, il échoue.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $reponse
     */
    private function assertTypeDeclareParLaSpec(TestResponse $reponse, string $chemin, string $methode): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $statut = (string) $reponse->baseResponse->getStatusCode();
        $declares = array_keys((array) ($spec['paths'][$chemin][$methode]['responses'][$statut]['content'] ?? []));
        $rendu = trim(explode(';', (string) $reponse->headers->get('Content-Type'))[0]);

        self::assertContains($rendu, $declares, "$methode $chemin $statut : le type « $rendu » n'est pas déclaré par la spec");
    }

    private function ecole(bool $autonome): Institution
    {
        return $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat front/serveur de « Mes classes », de la fiche d'une classe par ses deux
 * portes, et de ses élèves (lot 2 : la source locale d'une école créée depuis le
 * LMS).
 *
 * `docs/openapi.yaml` décrit les clés que le front LIT (`TeacherClassCard`,
 * `ClasseDetailsHeader` et les onglets). Les DEUX sources doivent les rendre :
 * une source qui en perdrait une casserait l'écran en 200, sans erreur. Les
 * échecs KLASSCI de la porte historique suivent la règle des élèves de la
 * classe : 4xx relayé, 503 avec `Retry-After`.
 */
final class ClassesContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MES_CLASSES = '/lms/teacher/classes';

    private const FICHE = '/lms/classes/{classeId}';

    private const FICHE_LOCALE = '/lms/classes/local/{classeId}';

    private const ELEVES = '/lms/classes/{classeId}/etudiants';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── source locale

    public function test_source_locale_mes_classes_suit_le_contrat(): void
    {
        [, $moi] = $this->classeEnseignee();

        $reponse = $this->asTenant($moi)->getJson('/api'.self::MES_CLASSES)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::MES_CLASSES, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::MES_CLASSES, 'get', 'data');
    }

    public function test_source_locale_fiche_par_ses_deux_portes_suit_le_contrat(): void
    {
        [$classe, $moi] = $this->classeEnseignee();

        $historique = $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey())->assertOk();
        $locale = $this->asTenant($moi)->getJson('/api/lms/classes/local/'.$classe->getKey())->assertOk();

        $this->assertMatchesOpenApiResponse($historique, self::FICHE, 'get');
        $this->assertOpenApiSchemaRefuseSans($historique, self::FICHE, 'get', 'data');
        $this->assertMatchesOpenApiResponse($locale, self::FICHE_LOCALE, 'get');
    }

    public function test_source_locale_404_suit_le_contrat(): void
    {
        [$classe] = $this->classeEnseignee();
        $etranger = $this->membre(Institution::query()->findOrFail($classe->institution_id), 'enseignant');

        $reponse = $this->asTenant($etranger)->getJson('/api/lms/classes/local/'.$classe->getKey())->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::FICHE_LOCALE, 'get');
    }

    public function test_source_locale_eleves_suivent_le_contrat(): void
    {
        [$classe, $moi] = $this->classeEnseignee();

        $reponse = $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey().'/etudiants')->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::ELEVES, 'get');
        $reponse->assertJsonPath('data.total', 1);
    }

    public function test_source_locale_eleves_404_suit_le_contrat(): void
    {
        [$classe] = $this->classeEnseignee();
        $etranger = $this->membre(Institution::query()->findOrFail($classe->institution_id), 'enseignant');

        $reponse = $this->asTenant($etranger)->getJson('/api/lms/classes/'.$classe->getKey().'/etudiants')->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::ELEVES, 'get');
        $reponse->assertExactJson(['success' => false, 'message' => 'Classe non trouvée']);
    }

    // ───────────────────────── source KLASSCI

    public function test_source_klassci_fiche_suit_le_contrat(): void
    {
        // Forme RÉELLE de l'enveloppe `classes/{id}` (ClasseEnvelope) : la
        // classe, le roster sans statut, les matières et l'emploi du temps.
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $coordinateur = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'coordinateur',
            'klassci_token' => 'jeton-coordinateur',
        ]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton-coordinateur', 'classes/12?with=filiere,niveau', 'GET')
                ->andReturn(['data' => [
                    'classe' => ['id' => 12, 'name' => 'B2 COM', 'code' => 'B2C', 'filiere' => ['nom' => 'Commerce'], 'niveau' => ['nom' => 'B2']],
                    'etudiants' => [['id' => 5, 'matricule' => 'M5', 'nom_complet' => 'Élève Cinq', 'email' => 'e5@ecole.test']],
                    'matieres' => [['id' => 3, 'nom' => 'Anglais', 'code' => 'ANG', 'coefficient' => 2]],
                    'emploi_temps_semaine' => [],
                ]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });

        $reponse = $this->asTenant($coordinateur)->getJson('/api/lms/classes/12')->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::FICHE, 'get');
    }

    public function test_source_klassci_porte_locale_garde_son_409_documente(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $locale = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $compte = User::factory()->create(['institution_id' => $ecole->getKey(), 'role' => 'coordinateur']);

        $reponse = $this->asTenant($compte)->getJson('/api/lms/classes/local/'.$locale->getKey())->assertStatus(409);

        $this->assertMatchesOpenApiResponse($reponse, self::FICHE_LOCALE, 'get');
    }

    /** @return iterable<string, array{0: int, 1: array<string, mixed>|string, 2: int}> */
    public static function echecsDeKlassci(): iterable
    {
        yield 'refus 403, relayé' => [403, ['success' => false, 'message' => 'refus amont'], 403];
        yield 'refus 404, relayé' => [404, ['success' => false, 'message' => 'introuvable'], 404];
        yield 'panne, 503' => [503, ['message' => 'panne'], 503];
        yield 'corps illisible, 503' => [503, '<html>maintenance</html>', 200];
    }

    /**
     * Les refus et les pannes ont un schéma FERMÉ : une clé non décrite le fait
     * échouer.
     *
     * @param  array<string, mixed>|string  $corps
     */
    #[DataProvider('echecsDeKlassci')]
    public function test_source_klassci_fiche_echecs_suivent_le_contrat(int $attendu, array|string $corps, int $statut): void
    {
        $reponse = $this->lireLaFicheKlassci($corps, $statut)->assertStatus($attendu);

        $this->assertMatchesOpenApiResponse($reponse, self::FICHE, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::FICHE, 'get', 'message');
    }

    /** Le 401 suit `RefusKlassci`, ouvert (un 401 Sanctum ne porte pas de motif) : son motif est prouvé à part. */
    public function test_source_klassci_fiche_session_refusee_suit_le_contrat(): void
    {
        $reponse = $this->lireLaFicheKlassci(['message' => 'Unauthenticated.'], 401)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::FICHE, 'get');
        $reponse->assertJsonPath('reason', 'klassci_session_expired');
    }

    // ───────────────────────── harnais

    /**
     * La fiche KLASSCI 12, lue par un enseignant d'une école créée depuis
     * KLASSCI. UNE closure : `classes/12` rend le corps donné, tout le reste une
     * enveloppe vide.
     *
     * @param  array<string, mixed>|string  $corps
     */
    private function lireLaFicheKlassci(array|string $corps, int $statut): TestResponse
    {
        Http::fake(static function ($requete) use ($corps, $statut) {
            if (! str_contains($requete->url(), '/classes/12')) {
                return Http::response(['success' => true, 'data' => []]);
            }

            return Http::response($corps, $statut, is_string($corps) ? ['Content-Type' => 'text/html'] : []);
        });
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);
        $compte = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'enseignant',
            'klassci_token' => 'jeton-klassci',
            'klassci_tenant_url' => 'https://ecole.klassci.test/api/lms',
        ]);

        return $this->asTenant($compte)->getJson('/api/lms/classes/12');
    }

    /**
     * @return array{0: Classe, 1: User}
     */
    private function classeEnseignee(): array
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $this->programme($classe, $this->matiere($ecole, 'Maths'), $moi);
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');

        return [$classe, $moi];
    }
}

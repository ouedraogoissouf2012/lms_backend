<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * Chaque entrée du journal d'audit (`GET /admin/audit-log`) porte son école,
 * `institution: {id, name}`, pour que le supradmin diagnostique sans
 * rapprocher les identifiants à la main (D127). La lecture est plateforme
 * (D51) : les écoles de toutes les institutions sont lues.
 *
 * - une école supprimée garde son nom : c'est précisément le cas qu'on
 *   diagnostique ;
 * - une entrée sans école rend `institution: null` ;
 * - le chargement est groupé : le nombre de requêtes ne dépend pas du nombre
 *   d'entrées.
 *
 * Vrai jeton porteur ; chaque réponse suit `docs/openapi.yaml`.
 */
final class AuditLogInstitutionNameTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/admin/audit-log';

    private User $supradmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
    }

    public function test_chaque_entree_porte_le_nom_de_son_ecole_dans_les_deux_types_d_ecole(): void
    {
        $klassci = Institution::factory()->create(['name' => 'Lycée créé depuis KLASSCI']);
        $lms = Institution::factory()->standalone()->create(['name' => 'Collège créé depuis le LMS']);
        $deKlassci = $this->entree($klassci);
        $deLms = $this->entree($lms);

        $reponse = $this->lire();

        self::assertSame(['id' => $klassci->id, 'name' => 'Lycée créé depuis KLASSCI'], $this->ecoleDe($reponse, $deKlassci));
        self::assertSame(['id' => $lms->id, 'name' => 'Collège créé depuis le LMS'], $this->ecoleDe($reponse, $deLms));
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    public function test_une_ecole_supprimee_garde_son_nom(): void
    {
        $fermee = Institution::factory()->create(['name' => 'École fermée']);
        $entree = $this->entree($fermee);
        $fermee->delete();
        self::assertSoftDeleted($fermee);

        self::assertSame(['id' => $fermee->id, 'name' => 'École fermée'], $this->ecoleDe($this->lire(), $entree));
    }

    public function test_une_entree_sans_ecole_porte_une_ecole_nulle(): void
    {
        $sansEcole = AuditLog::factory()->create(['institution_id' => null, 'user_id' => null, 'action' => 'login_failed']);

        $reponse = $this->lire();

        $ligne = collect((array) $reponse->json('data.data'))->firstWhere('id', $sansEcole->id);
        self::assertIsArray($ligne);
        self::assertArrayHasKey('institution', $ligne);
        self::assertNull($ligne['institution']);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    /**
     * Le schéma mord, à la racine comme sur une entrée : privé d'une clé, ou
     * augmenté d'une clé non décrite, il refuse.
     */
    public function test_le_schema_d_une_entree_mord(): void
    {
        $this->entree(Institution::factory()->create());
        $reponse = $this->lire();
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'data');

        $spec = OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $schema = $spec->schemaDeReponse(self::CHEMIN, 'get', 200);
        self::assertNotNull($schema);
        $corps = fn (): mixed => json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR);

        $sansEcole = $corps();
        unset($sansEcole->data->data[0]->institution);
        self::assertNotSame([], $spec->erreurs($sansEcole, $schema), "Une entrée sans la clé « institution » doit sortir de la spec.");

        $ecoleAugmentee = $corps();
        $ecoleAugmentee->data->data[0]->institution->slug = 'non-decrit';
        self::assertNotSame([], $spec->erreurs($ecoleAugmentee, $schema), "Une école augmentée d'une clé non décrite doit sortir de la spec.");
    }

    public function test_le_chargement_des_ecoles_est_groupe(): void
    {
        $ecoles = Institution::factory()->count(3)->create();
        $this->entree($ecoles[0]);
        $this->entree($ecoles[1]);
        $avecDeux = $this->requetesPour(fn () => $this->lire());

        $this->entree($ecoles[2]);
        $this->entree($ecoles[0]);
        $this->entree($ecoles[1]);
        $this->entree($ecoles[2]);
        $avecSix = $this->requetesPour(fn () => $this->lire());

        self::assertSame($avecDeux, $avecSix, "Le nombre de requêtes dépend du nombre d'entrées : {$avecDeux} pour 2, {$avecSix} pour 6.");
    }

    private function entree(Institution $ecole): AuditLog
    {
        $auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur']);

        return AuditLog::factory()->create(['institution_id' => $ecole->id, 'user_id' => $auteur->id, 'action' => 'update']);
    }

    private function lire(): TestResponse
    {
        return $this->asTenant($this->supradmin)->getJson('/api'.self::CHEMIN.'?per_page=50')->assertOk();
    }

    /** @return mixed l'objet `institution` de l'entrée, tel que servi */
    private function ecoleDe(TestResponse $reponse, AuditLog $entree): mixed
    {
        $ligne = collect((array) $reponse->json('data.data'))->firstWhere('id', $entree->id);
        self::assertIsArray($ligne, "Entrée {$entree->id} absente de la réponse.");

        return $ligne['institution'] ?? 'clé absente';
    }

    private function requetesPour(callable $lecture): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $lecture();
        $nombre = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $nombre;
    }
}

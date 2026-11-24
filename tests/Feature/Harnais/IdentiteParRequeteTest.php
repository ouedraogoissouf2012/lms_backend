<?php

declare(strict_types=1);

namespace Tests\Feature\Harnais;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #691 — chaque requête de test est servie sous l'identité qu'elle ENVOIE.
 *
 * Le garde Sanctum mémoïse l'utilisateur du premier appel d'un test. Une requête
 * suivante, anonyme ou d'un autre compte, était servie comme le premier, et un test
 * « X ne peut pas » restait vert sans rien prouver (mesuré : 12 tests, #691).
 * `Tests\TestCase::call()` purge désormais le garde quand l'en-tête `Authorization`
 * change de COMPTE. `Sanctum::actingAs()` et un jeton neuf du même compte ne
 * purgent rien.
 */
final class IdentiteParRequeteTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const QUI = '/api/__test/qui-suis-je';

    private const PROTEGEE = '/api/__test/tenant-scope-probe';

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('auth:sanctum')->get(self::QUI, static fn () => ['id' => auth()->id()]);
    }

    /** Un anonyme après une requête authentifiée est refusé : les en-têtes purgés suffisent. */
    public function test_un_anonyme_apres_une_requete_authentifiee_est_refuse(): void
    {
        $this->withToken($this->tokenFor($this->compte()))->getJson(self::PROTEGEE)->assertOk();

        $this->flushHeaders();

        $this->getJson(self::PROTEGEE)->assertUnauthorized();
    }

    /** Un second compte est servi comme lui-même, sans `forgetGuards()` dans le test. */
    public function test_un_second_compte_est_servi_comme_lui_meme(): void
    {
        [$premier, $second] = [$this->compte(), $this->compte()];

        $this->withToken($this->tokenFor($premier))->getJson(self::QUI)->assertJsonPath('id', $premier->id);

        $this->withToken($this->tokenFor($second))->getJson(self::QUI)->assertJsonPath('id', $second->id);
    }

    /** Verrou : `Sanctum::actingAs()` pose un utilisateur sans en-tête ; il persiste. */
    public function test_acting_as_persiste_d_une_requete_a_l_autre(): void
    {
        $compte = $this->compte();
        Sanctum::actingAs($compte);

        $this->getJson(self::QUI)->assertJsonPath('id', $compte->id);
        $this->getJson(self::QUI)->assertJsonPath('id', $compte->id);
    }

    /** Verrou : un jeton neuf du MÊME compte ne purge pas le garde. */
    public function test_un_jeton_neuf_du_meme_compte_ne_purge_pas_le_garde(): void
    {
        $compte = $this->compte();
        $this->withToken($this->tokenFor($compte))->getJson(self::QUI)->assertJsonPath('id', $compte->id);
        $garde = $this->app['auth']->guard('sanctum');

        $this->withToken($this->tokenFor($compte))->getJson(self::QUI)->assertJsonPath('id', $compte->id);

        self::assertSame($garde, $this->app['auth']->guard('sanctum'), 'Le garde a été purgé pour un même compte.');
    }

    /** `anonyme()` : la requête suivante part sans identité. */
    public function test_anonyme_retire_l_identite(): void
    {
        $this->asTenant($this->compte())->getJson(self::PROTEGEE)->assertOk();

        $this->anonyme()->getJson(self::PROTEGEE)->assertUnauthorized();
    }

    /**
     * Verrou : le harnais ne garde rien dans une statique. La table jeton → compte est
     * une propriété de CHAQUE test, remplie par un écouteur posé sur SON application :
     * rien ne survit d'une classe de test à l'autre (#1356).
     */
    public function test_le_harnais_ne_declare_aucune_propriete_statique(): void
    {
        $statiques = array_filter(
            (new \ReflectionClass(TestCase::class))->getProperties(\ReflectionProperty::IS_STATIC),
            static fn (\ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === TestCase::class,
        );

        self::assertSame([], array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $statiques));
    }

    private function compte(): User
    {
        return User::factory()->for(Institution::factory()->create())->create(['role' => 'enseignant']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * D9 — contrat de `POST /lms/seances` : chaque réponse RÉELLE suit ce que
 * `docs/openapi.yaml` en dit, statut et corps. Chaque cas tourne dans les DEUX
 * volets (école KLASSCI et école autonome) ; le mode n'est pas lu. Vrai jeton
 * porteur.
 *
 * @see app/Http/Controllers/API/LMS/LMSSeanceCrudController.php
 */
final class SeanceStoreContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/seances';

    private const URL = '/api/lms/seances';

    private const MINIMAL = ['titre' => 'Séance minimale', 'date_seance' => '2026-11-04'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    #[DataProvider('volets')]
    public function test_201_une_seance_locale_de_l_etablissement_de_l_auteur(bool $autonome): void
    {
        $ecole = $this->ecole($autonome);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $autonome ? null : 7001]);

        $reponse = $this->asTenant($this->compte($ecole, 'enseignant'))->postJson(self::URL, [
            'titre' => 'Comptabilité générale, séance 3', 'date_seance' => '2026-11-03 08:30:00',
            'matiere_nom' => 'Comptabilité générale', 'classe_nom' => 'BTS Comptabilité', 'classe_id' => $classe->id,
        ])->assertCreated();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'success');
        $this->assertDatabaseHas('seances', ['id' => $reponse->json('data.id'), 'institution_id' => $ecole->id,
            'classe_id' => $classe->id, 'klassci_seance_id' => null, 'klassci_classe_id' => null]);
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function rolesAdmis(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            yield "coordinateur, $volet" => [$autonome, 'coordinateur'];
            yield "alias coordinator, $volet" => [$autonome, 'coordinator'];
            yield "alias teacher, $volet" => [$autonome, 'teacher'];
            yield "superAdmin, $volet" => [$autonome, 'superAdmin'];
        }
    }

    #[DataProvider('rolesAdmis')]
    public function test_201_un_corps_minimal(bool $autonome, string $role): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole($autonome), $role))->postJson(self::URL, self::MINIMAL)
            ->assertCreated();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function rolesRefuses(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            yield "étudiant (rôle de la route), $volet" => [$autonome, 'etudiant'];
            yield "admin (rôle de la route), $volet" => [$autonome, 'admin'];
            yield "variante de casse (autorisation de la requête), $volet" => [$autonome, 'Enseignant'];
        }
    }

    #[DataProvider('rolesRefuses')]
    public function test_403_un_role_non_admis(bool $autonome, string $role): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole($autonome), $role))->postJson(self::URL, self::MINIMAL)
            ->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'success');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    public function test_401_sans_jeton(): void
    {
        $reponse = $this->postJson(self::URL, self::MINIMAL)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
    }

    /** Le jeton révoqué d'un enseignant de chaque volet : la même réponse que sans jeton. */
    #[DataProvider('volets')]
    public function test_401_un_jeton_revoque(bool $autonome): void
    {
        $enseignant = $this->compte($this->ecole($autonome), 'enseignant');
        $jeton = $enseignant->createToken('test')->plainTextToken;
        $enseignant->tokens()->delete();

        $reponse = $this->withToken($jeton)->postJson(self::URL, self::MINIMAL)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /**
     * Une classe d'une AUTRE école et une classe qui n'existe pas rendent le
     * MÊME 404, octet pour octet, et rien n'est écrit. Avant D9, les deux
     * rendaient un 500 : le contrôleur n'attrapait pas le refus du service.
     */
    #[DataProvider('volets')]
    public function test_404_une_classe_hors_de_l_etablissement_meme_corps_qu_une_classe_absente(bool $autonome): void
    {
        $etrangere = Classe::factory()->create(['institution_id' => $this->ecole(! $autonome)->id]);
        $enseignant = $this->compte($this->ecole($autonome), 'enseignant');

        $autreEcole = $this->asTenant($enseignant)->postJson(self::URL, self::MINIMAL + ['classe_id' => $etrangere->id])->assertNotFound();
        $absente = $this->asTenant($enseignant)->postJson(self::URL, self::MINIMAL + ['classe_id' => (int) Classe::query()->withoutGlobalScopes()->max('id') + 1])
            ->assertNotFound();

        $this->assertMatchesOpenApiResponse($autreEcole, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($autreEcole, self::CHEMIN, 'post', 'message');
        $autreEcole->assertExactJson(['success' => false, 'message' => 'Classe introuvable dans cet établissement.']);
        self::assertSame($autreEcole->getContent(), $absente->getContent(), 'le refus ne dit pas quelle classe existe ailleurs');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /** @return iterable<string, array{0: bool, 1: array<string, mixed>}> */
    public static function chargesInvalides(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            yield "corps vide, $volet" => [$autonome, []];
            yield "titre trop court, date illisible, classe 0, $volet" => [$autonome, ['titre' => 'ab', 'date_seance' => 'pas une date', 'classe_id' => 0]];
        }
    }

    /** @param  array<string, mixed>  $charge */
    #[DataProvider('chargesInvalides')]
    public function test_422_une_charge_invalide(bool $autonome, array $charge): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole($autonome), 'enseignant'))->postJson(self::URL, $charge)
            ->assertUnprocessable();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'errors');
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

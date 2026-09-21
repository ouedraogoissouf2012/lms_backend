<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #760 — la porte `GET /lms/classes/local/{id}` parle l'espace LOCAL, et le
 * prouve à l'endroit où ça compte : **l'URL envoyée à KLASSCI**.
 *
 * ## Ce que ces tests mesurent, et pourquoi là
 *
 * Asserter le code HTTP ne prouverait rien : la porte fautive rend 200 elle
 * aussi, avec la fiche d'une autre classe. Le seul témoin honnête est
 * l'identifiant transmis en aval — d'où la feinte de {@see KlassciProxyService}
 * avec l'URL attendue en argument exact.
 *
 * ## Deux pièges de mesure, tous deux payés dans ce dépôt
 *
 * 1. **Aucun identifiant n'est écrit en dur.** Un test qui suppose `id = 1` est
 *    vert sous SQLite et rouge sous MySQL dès qu'il n'ouvre plus la suite. Les
 *    identifiants locaux sont donc CRÉÉS puis RELUS.
 * 2. **Jamais `Sanctum::actingAs`.** Il n'émet aucun jeton porteur, donc
 *    `ResolveInstitution` ne s'exécute pas et le bornage tenant s'efface : un
 *    test d'isolation écrit ainsi est un faux négatif. Un vrai jeton est émis.
 */
final class ClasseLocalDetailsDoorTest extends TestCase
{
    use RefreshDatabase;

    private const JETON = 'jeton-enseignant-760';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
    }

    /**
     * Le cœur : une collision réelle, et la porte doit traduire.
     *
     * `$visee` est désignée par son identifiant LOCAL. `$leurre` porte ce même
     * nombre en `klassci_id` — c'est le cas nominal, les deux numérotations
     * étant indépendantes (7 classes sur 21 en production le 19/09/2026).
     *
     * La porte doit interroger KLASSCI avec le `klassci_id` de `$visee`, jamais
     * avec le nombre reçu.
     */
    public function test_un_identifiant_local_est_traduit_avant_d_atteindre_klassci(): void
    {
        $visee = $this->classe(klassciId: 7001);
        $leurre = $this->classe(klassciId: $visee->id);

        self::assertNotSame($visee->id, $leurre->id, 'sans deux lignes distinctes il n y a pas de collision');

        $this->attendKlassci('classes/7001?with=filiere,niveau');

        $this->appelPorteLocale($visee->id)->assertStatus(200);
    }

    /**
     * La preuve par contraste : la porte historique, elle, transmet le nombre
     * TEL QUEL. Les deux portes sont donc bien distinctes — et c'est pourquoi
     * l'appelant qui parle local avait besoin de la sienne.
     */
    public function test_la_porte_historique_transmet_l_identifiant_sans_le_traduire(): void
    {
        $visee = $this->classe(klassciId: 7001);

        $this->attendKlassci("classes/{$visee->id}?with=filiere,niveau");

        $this->getJson("/api/lms/classes/{$visee->id}", $this->entetes())->assertStatus(200);
    }

    /**
     * Une classe créée localement n'a aucune fiche KLASSCI : 409, et surtout
     * **aucun appel sortant**. Répondre 404 mentirait, la classe existe.
     */
    public function test_une_classe_purement_locale_rend_409_sans_appeler_klassci(): void
    {
        $classe = $this->classe(klassciId: null);

        $this->attendAucunAppelKlassci();

        $this->appelPorteLocale($classe->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    /**
     * Le bornage tenant, sur un vrai jeton : la classe d'un autre
     * établissement est introuvable, et rien ne part chez KLASSCI.
     */
    public function test_la_classe_d_une_autre_institution_est_introuvable(): void
    {
        $autre = Institution::factory()->create();
        $etrangere = Classe::factory()->create([
            'institution_id' => $autre->id,
            'klassci_id' => 7001,
        ]);

        $this->attendAucunAppelKlassci();

        $this->appelPorteLocale($etrangere->id)->assertStatus(404);
    }

    private function classe(?int $klassciId): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
        ]);
    }

    private function attendKlassci(string $url): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($url): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::JETON, $url, 'GET')
                ->once()
                ->andReturn(['data' => ['classe' => ['id' => 1, 'nom' => 'B2 COM'], 'etudiants' => []]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
    }

    private function attendAucunAppelKlassci(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
    }

    private function appelPorteLocale(int $classeId): TestResponse
    {
        return $this->getJson("/api/lms/classes/local/{$classeId}", $this->entetes());
    }

    /**
     * Un VRAI jeton porteur : c'est lui qui déclenche `ResolveInstitution`,
     * donc le seul moyen que le bornage tenant soit réellement exercé.
     *
     * @return array<string, string>
     */
    private function entetes(): array
    {
        $user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_token' => self::JETON,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }
}

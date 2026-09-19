<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #709 puis #802 — la cohabitation des deux mondes, rendue FALSIFIABLE.
 *
 * ## Ce que ce harnais ne prouvait pas
 *
 * Il déclarait deux jambes, `klassci` et `standalone`, et les faisait exécuter
 * le MÊME corps. La jambe dite autonome recevait une URL factice mais NON NULLE
 * (`https://standalone.invalid/local`) : une institution liée, pas autonome. Le
 * mode n'était posé nulle part, donc les deux jambes valaient `klassci`. Et la
 * sonde comptait des `Classe` sans jamais lire `klassci_api_url`.
 *
 * Le paramètre n'irriguait que le suffixe d'un slug. Casser l'isolation des
 * deux mondes n'aurait fait rougir aucune des deux jambes.
 *
 * ## Ce qu'il prouve désormais, et par quel montage
 *
 *   1. le scope tenant masque les classes d'autrui — dans les DEUX modes, et
 *      c'est la seule propriété que le provider a le droit de couvrir, parce
 *      que c'est la seule où l'identité des deux jambes EST la démonstration ;
 *   2. une école liée émet vers SA cible, avec SON jeton ;
 *   3. une école autonome n'émet RIEN.
 *
 * **Le test 2 est l'expérience de contrôle du test 3**, et les deux doivent
 * rester ensemble. `Http::assertNothingSent()` est vrai aussi quand le faux
 * client n'est pas branché, ou quand la requête s'arrête avant le résolveur —
 * sur un 401, un 403 de rôle ou un throttle. Sans une jambe qui, elle, capture
 * un appel réel sur le MÊME chemin, « zéro requête » ne distingue pas
 * « la garde a tenu » de « on n'est jamais arrivé jusqu'à elle ».
 *
 * ## Pourquoi `setUp()` pose une cible globale VALIDE
 *
 * `phpunit.xml` ne définit aucun `KLASSCI_API_URL`. Sans ces deux lignes, la
 * jambe autonome passerait par ABSENCE DE CONFIGURATION DU RUNNER, et non par
 * la garde qu'elle prétend éprouver — le faux vert que l'ADR de #792 nomme
 * lui-même. Retirer ce `setUp` doit rendre la mutation M1 invisible ; c'est
 * vérifié à la main et consigné dans la PR.
 *
 * ## Pourquoi le middleware n'est plus désactivé
 *
 * `disableKlassciMiddleware()` était ici un no-op : la sonde ne porte pas
 * `EnsureKlassciSync`. Sur `/api/proxy/*`, en revanche, il est réel — le
 * neutraliser affaiblirait le test. Son silence s'obtient par la DONNÉE :
 * `last_klassci_sync = now()` rend les données fraîches, et la porte de
 * synchronisation ne déclenche aucun appel.
 *
 * @see docs/adr/2026-09-17-792-01-null-absorbant-du-tenant.md
 */
final class TenantModeIsolationHarnessTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** La cible du serveur : celle qu'aucune des deux jambes ne doit emprunter. */
    private const CIBLE_GLOBALE = 'https://serveur-global.klassci.test';

    private const JETON_GLOBAL = 'jeton-systeme-global';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.klassci.url', self::CIBLE_GLOBALE);
        Config::set('services.klassci.token', self::JETON_GLOBAL);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return [
            'klassci' => ['klassci'],
            'standalone' => ['standalone'],
        ];
    }

    /**
     * Le scope tenant est INDÉPENDANT du mode — et c'est tout ce que ce test
     * démontre. Il ne dit rien de la cohabitation réseau : c'est l'objet des
     * deux suivants.
     */
    #[DataProvider('modes')]
    public function test_le_scope_tenant_masque_les_classes_d_autrui_dans_les_deux_modes(string $mode): void
    {
        $notre = $this->ecole($mode, 'notre');
        $autre = $this->ecole($mode, 'autre');
        Classe::factory()->create(['institution_id' => $notre->id]);
        Classe::factory()->create(['institution_id' => $autre->id]);

        $this->asTenant($this->membreDe($notre))
            ->getJson('/api/__test/tenant-scope-probe')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    /**
     * L'expérience de contrôle : un appel RÉEL part, et il porte la cible et le
     * jeton du tenant — jamais ceux du serveur.
     */
    public function test_une_ecole_liee_emet_vers_sa_propre_cible_avec_son_propre_jeton(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $liee = Institution::factory()->create([
            'slug' => 'ecole-liee',
            'klassci_api_url' => 'https://ecole-liee.klassci.test/api/lms',
            'klassci_api_token' => 'jeton-de-l-ecole-liee',
        ]);

        $this->asTenant($this->membreDe($liee))->getJson('/api/proxy/structure');

        Http::assertSent(static function ($requete): bool {
            return str_starts_with($requete->url(), 'https://ecole-liee.klassci.test')
                && $requete->hasHeader('Authorization', 'Bearer jeton-de-l-ecole-liee');
        });

        Http::assertNotSent(static fn ($requete): bool => str_contains($requete->url(), 'serveur-global'));
    }

    /**
     * Une école autonome n'emprunte ni la cible ni le jeton du serveur, et
     * n'émet donc rien du tout.
     *
     * Le `503` n'est PAS l'invariant : c'est aujourd'hui la signature d'une
     * configuration KLASSCI manquante, et une prochaine tranche de #805 pourra
     * légitimement y substituer une réponse locale. L'invariant durable est
     * « zéro requête sortante ». Le statut n'est asserté que comme SONDE
     * D'ATTEINTE de la garde : il distingue « arrêtée par le résolveur » de
     * « arrêtée par l'authentification ou le débit », qui rendraient eux aussi
     * zéro requête.
     */
    public function test_une_ecole_autonome_n_emet_rien(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $autonome = Institution::factory()->standalone()->create(['slug' => 'ecole-autonome']);

        $reponse = $this->asTenant($this->membreDe($autonome))->getJson('/api/proxy/structure');

        Http::assertNothingSent();

        self::assertSame(503, $reponse->getStatusCode(), 'Sonde d\'atteinte de la garde, pas l\'invariant.');
        self::assertSame('30', $reponse->headers->get('Retry-After'));
    }

    private function ecole(string $mode, string $slug): Institution
    {
        $fabrique = $mode === 'standalone'
            ? Institution::factory()->standalone()
            : Institution::factory();

        return $fabrique->create(['slug' => $slug.'-'.$mode]);
    }

    /**
     * Un membre dont le compte ne porte AUCUNE liaison personnelle.
     *
     * Les deux `null` sont load-bearing : avec un jeton personnel et une URL de
     * tenant, la priorité 1 du résolveur court-circuiterait entièrement
     * l'institution, et le harnais mesurerait le compte au lieu de l'école.
     * Aujourd'hui `UserFactory` ne pose pas `klassci_tenant_url` — l'édifice
     * tiendrait donc par ACCIDENT à une absence dans une factory partagée. Le
     * dire ici rend le test indépendant d'elle.
     *
     * `last_klassci_sync = now()` fait taire `EnsureKlassciSync` par la donnée,
     * sans avoir à retirer le middleware de la pile.
     */
    private function membreDe(Institution $institution): User
    {
        return User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
            'klassci_token' => null,
            'klassci_tenant_url' => null,
            'last_klassci_sync' => now(),
        ]);
    }
}

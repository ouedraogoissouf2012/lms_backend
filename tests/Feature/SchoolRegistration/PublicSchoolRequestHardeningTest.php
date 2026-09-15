<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolRegistration;

use App\Models\SchoolRegistrationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * #812 — durcissement de la seule écriture NON authentifiée du système.
 *
 * Deux défauts relevés en revue adversariale, et confirmés sur la branche
 * principale après fusion.
 *
 * 1. L'adresse, jamais vérifiée, servait de preuve de propriété : la demande en
 *    attente d'un tiers était réécrite sur place par `fill()->save()`, sans
 *    trace. Depuis #815, ce sont ces champs qui deviennent l'Institution et son
 *    premier compte `superAdmin`.
 * 2. La borne de débit était indexée sur `$request->ip()` alors que
 *    `trustProxies(at: '*')` rend l'en-tête `X-Forwarded-For` fourni par
 *    l'appelant — et son seau anonyme était partagé avec `/auth/login`.
 */
final class PublicSchoolRequestHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/school-requests';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('school-requests');
    }

    public function test_un_second_depot_ne_reecrit_pas_la_demande_d_un_tiers(): void
    {
        $this->postJson(self::ROUTE, $this->demande([
            'nom_demandeur' => 'Directrice legitime',
            'nom_ecole' => 'Ecole des Palmiers',
            'telephone_demandeur' => '70000001',
        ]))->assertCreated();

        // Même adresse — publiée sur le site de l'école — mais tout le reste
        // choisi par quelqu'un d'autre.
        $this->postJson(self::ROUTE, $this->demande([
            'nom_demandeur' => 'Mallory',
            'nom_ecole' => 'Ecole detournee',
            'telephone_demandeur' => '79999999',
        ]))->assertCreated();

        $demande = SchoolRegistrationRequest::query()->firstOrFail();

        self::assertSame('Directrice legitime', $demande->nom_demandeur);
        self::assertSame('Ecole des Palmiers', $demande->nom_ecole);
        self::assertSame('70000001', $demande->telephone_demandeur);
    }

    public function test_le_second_depot_ne_cree_pas_de_ligne_concurrente(): void
    {
        // Le premier dépôt fait foi, et il reste seul : ni écrasement, ni
        // doublon qui ferait arbitrer le supradmin sur deux versions.
        $this->postJson(self::ROUTE, $this->demande())->assertCreated();
        $this->postJson(self::ROUTE, $this->demande(['nom_ecole' => 'Autre']))->assertCreated();

        self::assertSame(1, SchoolRegistrationRequest::query()->count());
    }

    public function test_la_reponse_ne_dit_pas_si_l_adresse_etait_deja_connue(): void
    {
        // Sinon l'endpoint devient un oracle : « cette école a-t-elle déjà
        // demandé ? » se lirait au code de statut.
        $premiere = $this->postJson(self::ROUTE, $this->demande());
        $seconde = $this->postJson(self::ROUTE, $this->demande(['nom_ecole' => 'Autre']));

        self::assertSame($premiere->getStatusCode(), $seconde->getStatusCode());
        self::assertSame($premiere->json('message'), $seconde->json('message'));
    }

    public function test_le_debit_ne_partage_plus_son_seau_avec_le_login(): void
    {
        // Cinq dépôts épuisent le budget de CETTE route. Une école derrière une
        // seule IP ne doit pas voir ses connexions refusées pour autant.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::ROUTE, $this->demande(['email_demandeur' => "d{$i}@ecole.ci"]));
        }

        $this->postJson(self::ROUTE, $this->demande(['email_demandeur' => 'de-trop@ecole.ci']))
            ->assertStatus(429);

        // Le login répond sur ses propres mérites. Peu importe lesquels : ce qui
        // compte est qu'il ne soit PAS refusé pour un budget qu'il n'a pas
        // consommé.
        $login = $this->postJson('/api/auth/login', ['username' => 'inconnu', 'password' => 'x']);

        self::assertNotSame(429, $login->getStatusCode());
    }

    public function test_une_seconde_borne_ne_depend_pas_de_l_adresse_annoncee(): void
    {
        // `trustProxies(at: '*')` rend `X-Forwarded-For` choisi par l'appelant :
        // une boucle qui le fait tourner tombe à chaque fois dans un compteur
        // neuf. Éprouver le plafond journalier par le trafic demanderait des
        // centaines de requêtes ; on vérifie donc le contrat du limiteur —
        // qu'il existe une borne dont la clé ne vient PAS de la requête.
        $limiteur = RateLimiter::limiter('school-requests');

        self::assertNotNull($limiteur, 'le limiteur nommé « school-requests » doit exister');

        $bornes = $limiteur(request());

        self::assertIsArray($bornes, 'deux bornes sont attendues, pas une seule');
        self::assertCount(2, $bornes);

        $cles = array_map(static fn ($borne) => $borne->key, $bornes);

        self::assertContains('school-requests-global', $cles);
    }

    /**
     * @param  array<string, string>  $remplace
     * @return array<string, string>
     */
    private function demande(array $remplace = []): array
    {
        return array_merge([
            'nom_demandeur' => 'Awa Traore',
            'email_demandeur' => 'direction@ecole.ci',
            'telephone_demandeur' => '70000000',
            'nom_ecole' => 'Ecole des Palmiers',
            'usage_prevu' => 'Formation continue pour une trentaine de stagiaires.',
        ], $remplace);
    }
}

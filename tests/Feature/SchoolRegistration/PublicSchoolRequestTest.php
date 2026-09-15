<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolRegistration;

use App\Models\Institution;
use App\Models\SchoolRegistrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * #803 — la porte d'entrée du monde autonome.
 *
 * ## Ce que ce fichier garde
 *
 * ADR-803-01 tranche que l'endpoint public écrit **une ligne inerte**, et rien
 * d'autre. C'est la seule écriture non authentifiée du système : tout ce qu'il
 * pourrait créer en plus serait une surface offerte à un inconnu.
 *
 * Trois interdits, prouvés un par un ci-dessous :
 *
 *   - aucun `User` — pas de compte, pas de jeton, pas de session ;
 *   - aucune `Institution` — pas de tenant, même inactif ;
 *   - aucun `slug` réservé — sinon n'importe qui squatte « esbtp » sans rien
 *     prouver.
 *
 * Le troisième est le moins évident et le plus coûteux à découvrir tard. Il se
 * prouve en créant, APRÈS la demande, une institution portant le slug souhaité :
 * si la demande l'avait réservé, cette création échouerait.
 *
 * ## Pourquoi pas `institution.header`
 *
 * `/forgot-password` et `/reset-password` exigent cet en-tête
 * (`routes/api/core.php:49-51`) parce qu'ils agissent DANS un établissement.
 * Ici il n'y en a pas encore : l'exiger rendrait la route inatteignable pour
 * celui à qui elle est destinée. `/login` ne l'exige pas non plus.
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
final class PublicSchoolRequestTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/school-requests';

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function demandeValide(array $surcharges = []): array
    {
        return array_merge([
            'nom_demandeur' => 'Awa Kouassi',
            'email_demandeur' => 'awa.kouassi@cabinet-kf.ci',
            'telephone_demandeur' => '+225 07 00 00 00 00',
            'nom_ecole' => 'Cabinet Kouassi Formation',
            'slug_souhaite' => 'cabinet-kouassi',
            'usage_prevu' => 'Formations comptabilite OHADA, promotions de 25 stagiaires.',
        ], $surcharges);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Le compteur de debit est par IP et survit d'un test a l'autre dans le
        // meme processus : sans purge, un test echouerait pour la mauvaise raison.
        RateLimiter::clear('');
    }

    public function test_un_inconnu_depose_une_demande_et_rien_d_autre_n_est_cree(): void
    {
        $this->postJson(self::ROUTE, $this->demandeValide())->assertStatus(201);

        $this->assertDatabaseCount('school_registration_requests', 1);
        $this->assertDatabaseHas('school_registration_requests', [
            'email_demandeur' => 'awa.kouassi@cabinet-kf.ci',
            'statut' => 'en_attente',
            'institution_id' => null,
        ]);

        // Les trois interdits d'ADR-803-01.
        $this->assertSame(0, User::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Institution::query()->withoutGlobalScopes()->count());
    }

    public function test_la_demande_ne_reserve_aucun_slug(): void
    {
        $this->postJson(self::ROUTE, $this->demandeValide(['slug_souhaite' => 'esbtp']))
            ->assertStatus(201);

        // Si la demande avait reserve le slug, cette creation echouerait sur
        // l'unique de `institutions.slug`. Elle doit passer.
        $institution = Institution::factory()->create(['slug' => 'esbtp']);

        $this->assertDatabaseHas('institutions', ['id' => $institution->id, 'slug' => 'esbtp']);
    }

    public function test_une_seconde_demande_du_meme_email_ne_duplique_ni_n_ecrase(): void
    {
        // Ce test affirmait l'inverse : que la SECONDE valeur l'emportait. Il
        // documentait donc au vert une primitive d'écrasement — l'endpoint est
        // anonyme et l'adresse n'est jamais vérifiée, si bien que n'importe qui
        // pouvait réécrire la demande d'une école dont l'adresse est publiée.
        // Le premier dépôt fait foi (#812).
        $premier = $this->demandeValide();

        $this->postJson(self::ROUTE, $premier)->assertStatus(201);
        $this->postJson(self::ROUTE, $this->demandeValide([
            'nom_ecole' => 'Cabinet Kouassi Formation SARL',
        ]))->assertStatus(201);

        $this->assertDatabaseCount('school_registration_requests', 1);
        $this->assertDatabaseHas('school_registration_requests', [
            'email_demandeur' => 'awa.kouassi@cabinet-kf.ci',
            'nom_ecole' => $premier['nom_ecole'],
        ]);
    }

    /**
     * @dataProvider champsObligatoires
     */
    public function test_un_champ_obligatoire_manquant_est_refuse(string $champ): void
    {
        $charge = $this->demandeValide();
        unset($charge[$champ]);

        $this->postJson(self::ROUTE, $charge)
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);

        $this->assertDatabaseCount('school_registration_requests', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function champsObligatoires(): array
    {
        return [
            'nom du demandeur' => ['nom_demandeur'],
            'email du demandeur' => ['email_demandeur'],
            'nom de l ecole' => ['nom_ecole'],
            'usage prevu' => ['usage_prevu'],
        ];
    }

    public function test_le_statut_n_est_jamais_dicte_par_le_client(): void
    {
        $this->postJson(self::ROUTE, $this->demandeValide([
            'statut' => 'validee',
            'institution_id' => 999,
        ]))->assertStatus(201);

        // Une valeur client n'est jamais autoritaire.
        $this->assertDatabaseHas('school_registration_requests', [
            'statut' => 'en_attente',
            'institution_id' => null,
        ]);
    }

    public function test_la_reponse_ne_divulgue_rien_d_exploitable(): void
    {
        $reponse = $this->postJson(self::ROUTE, $this->demandeValide())->assertStatus(201);

        // La demande n'est pas une ressource consultable : lui rendre son id
        // offrirait un identifiant a enumerer sur un endpoint anonyme.
        $reponse->assertJsonMissingPath('data.id');
        $reponse->assertJsonMissingPath('data.statut');
    }

    public function test_la_route_est_limitee_en_debit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::ROUTE, $this->demandeValide([
                'email_demandeur' => "demandeur{$i}@exemple.ci",
            ]))->assertStatus(201);
        }

        $this->postJson(self::ROUTE, $this->demandeValide([
            'email_demandeur' => 'demandeur6@exemple.ci',
        ]))->assertStatus(429);
    }

    /**
     * Deux depots simultanes de la meme adresse : la course est perdue, pas l'utilisateur.
     *
     * Sans rattrapage, la seconde requete franchit la lecture, echoue sur
     * l'unicite partielle et remonte un 500 — sur un endpoint public ou le
     * double-clic est le comportement le plus banal qui soit.
     *
     * La course est simulee de facon deterministe : un ecouteur `creating`
     * insere la ligne jumelle juste avant notre ecriture, exactement la fenetre
     * qu'un test sequentiel ne peut pas produire autrement.
     */
    public function test_deux_depots_simultanes_ne_produisent_pas_une_erreur_serveur(): void
    {
        $jumelleInseree = false;

        SchoolRegistrationRequest::creating(function () use (&$jumelleInseree): void {
            if ($jumelleInseree) {
                return;
            }
            $jumelleInseree = true;

            DB::table('school_registration_requests')->insert([
                'nom_demandeur' => 'Jumelle',
                'email_demandeur' => 'awa.kouassi@cabinet-kf.ci',
                'nom_ecole' => 'Depot concurrent',
                'usage_prevu' => 'Requete jumelle arrivee entre la lecture et l ecriture.',
                'statut' => 'en_attente',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson(self::ROUTE, $this->demandeValide())->assertStatus(201);

        SchoolRegistrationRequest::flushEventListeners();

        // Une seule ligne : la jumelle, mise a jour par notre charge utile.
        $this->assertDatabaseCount('school_registration_requests', 1);
        $this->assertDatabaseHas('school_registration_requests', [
            'email_demandeur' => 'awa.kouassi@cabinet-kf.ci',
            'nom_ecole' => 'Cabinet Kouassi Formation',
            'statut' => 'en_attente',
        ]);
    }

    public function test_la_table_reste_hors_du_perimetre_multi_tenant(): void
    {
        // ADR-803-01 : la ligne est inerte. Si la table entrait dans
        // config/tenancy.php, un scope global la filtrerait par institution — or
        // une demande n'appartient a aucune institution, et disparaitrait.
        $tables = config('tenancy.institution_scoped_tables');

        // Denominateur : sans lui, une cle renommee rendrait null, et
        // l'assertion d'absence passerait a vide — un faux negatif.
        $this->assertIsArray($tables, 'Cle config/tenancy.php introuvable : le test ne prouverait rien.');
        $this->assertGreaterThan(20, count($tables), 'Liste des tables scopees anormalement courte.');

        $this->assertNotContains(
            'school_registration_requests',
            $tables,
            'La table des demandes ne doit PAS etre inscrite dans config/tenancy.php.'
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Admission;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Admission\DroitDInscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1659, PR 1 : une famille dépose une demande d'inscription auprès d'une école autonome ; la gestion l'examine ou la
 * rejette. Porte publique sans jeton ; gestion sous vrai jeton.
 *
 * - La porte publique ne confirme RIEN : école inconnue, inactive, KLASSCI ou autonome, adresse connue ou non, la
 *   réponse est le MÊME 202. Seule une école autonome et active enregistre la demande. Le consentement est obligatoire
 *   (422, qui ne dit rien de l'école). Limite par IP, et plafond journalier par école SILENCIEUX (même 202).
 * - Circuit : recue → en_examen → (acceptee → inscrite, PR 2) ou rejetee avec motif. Hors circuit : 409.
 * - Liste sur la forme commune (#1647), compteurs par état MESURÉS. Autre école : 404 ; inscription tenue par
 *   KLASSCI : 403 `enrolment_managed_by_klassci`. Une admission suit le droit d'INSCRIPTION, pas celui du catalogue.
 * - Rétention (loi 2013-450) : 90 jours après la décision, 365 jours sans traitement ; purge planifiée.
 */
final class DemandesInscriptionTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const DEMANDE = [
        'type' => 'nouvelle', 'nom' => 'Konan', 'prenoms' => 'Awa', 'date_naissance' => '2012-03-14', 'genre' => 'F',
        'declarant_nom' => 'Adjoua Konan', 'declarant_relation' => 'mere', 'declarant_telephone' => '+2250700000009',
        'declarant_courriel' => 'adjoua@exemple.test', 'classe_souhaitee' => '6e', 'diplome_entree' => 'CEPE',
        'consentement' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->travelTo('2026-11-27 10:00:00');
    }

    public function test_une_famille_depose_une_demande_dans_une_ecole_autonome(): void
    {
        $ecole = $this->ecole();
        $ecole->forceFill(['slug' => 'ecole-a', 'is_active' => true])->save();

        $reponse = $this->deposer('ecole-a');

        $reponse->assertStatus(202);
        $this->assertMatchesOpenApiResponse($reponse, '/demandes-inscription', 'post');
        $ligne = DB::table('demandes_inscription')->first();
        self::assertSame([$ecole->id, 'recue', 'Konan', '2026-11-27'], [(int) $ligne?->institution_id, $ligne?->etat, $ligne?->nom, substr((string) $ligne?->consentement_le, 0, 10)]);
    }

    /** Rien ne confirme qu'une école existe : le même 202, octet pour octet, et rien d'écrit hors d'une école ouverte. */
    public function test_la_porte_publique_ne_confirme_rien(): void
    {
        $this->ecole()->forceFill(['slug' => 'ouverte', 'is_active' => true])->save();
        $this->ecole(InstitutionMode::Klassci)->forceFill(['slug' => 'klassci', 'is_active' => true])->save();
        $this->ecole()->forceFill(['slug' => 'fermee', 'is_active' => false])->save();

        $reference = $this->deposer('ouverte');
        foreach (['klassci', 'fermee', 'inconnue-xyz'] as $slug) {
            $reponse = $this->deposer($slug);
            self::assertSame(202, $reponse->getStatusCode(), $slug);
            self::assertSame($reference->getContent(), $reponse->getContent(), $slug);
        }

        self::assertSame(1, DB::table('demandes_inscription')->count());
    }

    public function test_le_consentement_est_obligatoire(): void
    {
        $this->ecole()->forceFill(['slug' => 'ecole-a', 'is_active' => true])->save();

        $reponse = $this->postJson('/api/demandes-inscription', ['ecole' => 'ecole-a'] + array_diff_key(self::DEMANDE, ['consentement' => 1]));

        $reponse->assertUnprocessable()->assertJsonValidationErrors('consentement');
        $this->assertMatchesOpenApiResponse($reponse, '/demandes-inscription', 'post');
        self::assertSame(0, DB::table('demandes_inscription')->count());
    }

    public function test_le_plafond_journalier_de_l_ecole_est_silencieux(): void
    {
        config(['admissions.plafond_journalier' => 2]);
        $this->ecole()->forceFill(['slug' => 'ecole-a', 'is_active' => true])->save();

        $reference = $this->deposer('ecole-a', '10.0.0.1');
        $this->deposer('ecole-a', '10.0.0.2');
        $troisieme = $this->deposer('ecole-a', '10.0.0.3');

        self::assertSame([202, $reference->getContent()], [$troisieme->getStatusCode(), $troisieme->getContent()]);
        self::assertSame(2, DB::table('demandes_inscription')->count());
    }

    public function test_la_limite_par_adresse(): void
    {
        $this->ecole()->forceFill(['slug' => 'ecole-a', 'is_active' => true])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->deposer('ecole-a')->assertStatus(202);
        }

        $this->deposer('ecole-a')->assertStatus(429);
    }

    public function test_la_gestion_liste_avec_compteurs_examine_et_rejette(): void
    {
        [$ecole, $admin] = $this->ecoleEtAdmin();
        [$a, $b] = [$this->demande($ecole), $this->demande($ecole, ['type' => 'reinscription'])];

        $liste = $this->asTenant($admin)->getJson('/api/demandes-inscription?a_traiter=1');
        $liste->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('meta.counts.recue', 2)->assertJsonPath('meta.counts.rejetee', 0);
        $this->assertMatchesOpenApiResponse($liste, '/demandes-inscription', 'get');

        $examen = $this->asTenant($admin)->postJson("/api/demandes-inscription/{$a}/examiner");
        $examen->assertOk()->assertJsonPath('data.etat', 'en_examen');
        $this->assertMatchesOpenApiResponse($examen, '/demandes-inscription/{demande}/examiner', 'post');

        $this->asTenant($admin)->postJson("/api/demandes-inscription/{$b}/rejeter", [])->assertUnprocessable()->assertJsonValidationErrors('motif');
        $rejet = $this->asTenant($admin)->postJson("/api/demandes-inscription/{$b}/rejeter", ['motif' => 'Classe complète']);
        $rejet->assertOk()->assertJsonPath('data.etat', 'rejetee')->assertJsonPath('data.motif_rejet', 'Classe complète');
        self::assertSame($admin->id, (int) DB::table('demandes_inscription')->where('id', $b)->value('traite_par'));

        $this->asTenant($admin)->getJson('/api/demandes-inscription?type=reinscription')->assertJsonPath('data.total', 1);
        $this->asTenant($admin)->getJson("/api/demandes-inscription/{$a}")->assertOk()->assertJsonPath('data.declarant_nom', 'Adjoua Konan');
    }

    public function test_une_transition_hors_circuit_est_refusee(): void
    {
        [$ecole, $admin] = $this->ecoleEtAdmin();
        $id = $this->demande($ecole, ['etat' => 'rejetee']);

        $refus = $this->asTenant($admin)->postJson("/api/demandes-inscription/{$id}/examiner");

        $refus->assertStatus(409)->assertJsonPath('reason', 'etat_de_la_demande');
        $this->assertMatchesOpenApiResponse($refus, '/demandes-inscription/{demande}/examiner', 'post');
    }

    /** L'isolation : la demande d'une autre école est introuvable, du même corps qu'un id absent. */
    public function test_404_une_demande_d_une_autre_ecole(): void
    {
        [, $admin] = $this->ecoleEtAdmin();
        [$ailleurs] = $this->ecoleEtAdmin();
        $id = $this->demande($ailleurs);

        $absente = $this->asTenant($admin)->getJson('/api/demandes-inscription/999999')->assertNotFound();
        self::assertSame($absente->json(), $this->asTenant($admin)->getJson("/api/demandes-inscription/{$id}")->assertNotFound()->json());
        $this->asTenant($admin)->postJson("/api/demandes-inscription/{$id}/examiner")->assertNotFound();
        $this->asTenant($admin)->getJson('/api/demandes-inscription')->assertJsonPath('data.total', 0);
    }

    public function test_403_hors_de_la_gestion_et_en_ecole_klassci(): void
    {
        [$ecole] = $this->ecoleEtAdmin();
        $this->asTenant($this->membre($ecole, 'enseignant'))->getJson('/api/demandes-inscription')->assertForbidden();

        $klassci = $this->ecole(InstitutionMode::Klassci);
        $this->asTenant($this->membre($klassci, 'admin'))->getJson('/api/demandes-inscription')
            ->assertForbidden()->assertJsonPath('reason', 'enrolment_managed_by_klassci');
    }

    /**
     * Une admission EST une inscription : elle suit le droit d'INSCRIPTION (`allowsLocalEnrolment`), jamais celui du
     * catalogue. Les deux valent pareil aujourd'hui ; une doublure du droit d'inscription les fait DIVERGER, sans quoi
     * rien ne distinguerait les deux portes.
     */
    public function test_inscription_fermee_et_catalogue_ouvert_les_admissions_sont_fermees(): void
    {
        $ecole = $this->ecole();
        $ecole->forceFill(['slug' => 'ecole-a', 'is_active' => true])->save();
        $this->droitDInscription(false);

        $this->deposer('ecole-a')->assertStatus(202);
        self::assertSame(0, DB::table('demandes_inscription')->count(), 'Le dépôt reste muet, et n\'écrit rien.');
        $this->asTenant($this->membre($ecole, 'admin'))->getJson('/api/demandes-inscription')
            ->assertForbidden()->assertJsonPath('reason', 'enrolment_managed_by_klassci');
    }

    public function test_inscription_ouverte_et_catalogue_ferme_les_admissions_sont_ouvertes(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $ecole->forceFill(['slug' => 'ecole-k', 'is_active' => true])->save();
        $this->droitDInscription(true);

        $this->deposer('ecole-k')->assertStatus(202);
        self::assertSame(1, DB::table('demandes_inscription')->count());
        $this->asTenant($this->membre($ecole, 'admin'))->getJson('/api/demandes-inscription')
            ->assertOk()->assertJsonPath('meta.counts.recue', 1);
    }

    public function test_la_purge_planifiee_suit_les_delais(): void
    {
        [$ecole] = $this->ecoleEtAdmin();
        $this->demande($ecole, ['etat' => 'rejetee', 'traite_le' => now()->subDays(91)]);
        $rejeteeRecente = $this->demande($ecole, ['etat' => 'rejetee', 'traite_le' => now()->subDays(30)]);
        $this->demande($ecole, ['created_at' => now()->subDays(366)]);
        $enCours = $this->demande($ecole, ['etat' => 'en_examen', 'created_at' => now()->subDays(200)]);

        Artisan::call('admissions:purge', ['--apply' => true]);

        // Restent : la rejetée récente, et celle en examen depuis 200 jours. Partent la rejetée de 91 jours et la
        // demande oubliée depuis 366 jours.
        self::assertSame([$rejeteeRecente, $enCours], DB::table('demandes_inscription')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    // ───────────────────────── harnais

    private function deposer(string $ecole, string $ip = '10.0.0.9'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/demandes-inscription', ['ecole' => $ecole] + self::DEMANDE);
    }

    private function droitDInscription(bool $ouvert): void
    {
        $this->app->instance(DroitDInscription::class, new class($ouvert) implements DroitDInscription
        {
            public function __construct(private readonly bool $ouvert) {}

            public function ouvertPour(Institution $ecole): bool
            {
                return $this->ouvert;
            }
        });
    }

    /** @return array{0: Institution, 1: User} */
    private function ecoleEtAdmin(): array
    {
        $ecole = $this->ecole();

        return [$ecole, $this->membre($ecole, 'admin')];
    }

    /** @param  array<string, mixed>  $plus */
    private function demande(Institution $ecole, array $plus = []): int
    {
        $champs = array_diff_key(self::DEMANDE, ['consentement' => 1]);

        return (int) DB::table('demandes_inscription')->insertGetId($plus + $champs + [
            'institution_id' => $ecole->id, 'etat' => 'recue', 'consentement_le' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

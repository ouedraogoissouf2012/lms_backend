<?php

declare(strict_types=1);

namespace Tests\Feature\Eleve;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1657 : les tuteurs d'un élève, dans une école autonome. Sous vrai jeton.
 *
 * - `POST /api/eleves/{eleve}/tuteurs` : un tuteur neuf (nom, téléphone, courriel, profession, adresse), ou un tuteur
 *   EXISTANT de l'école (`tuteur_id`), avec la relation (`pere`, `mere`, `tuteur`, `autre`). Un tuteur se partage entre
 *   frères et sœurs, dans la même école seulement. Il faut un dossier d'abord : 409 `dossier_absent`.
 * - `PUT /api/tuteurs/{tuteur}` : ses coordonnées. `DELETE /api/eleves/{eleve}/tuteurs/{tuteur}` : le LIEN ; un
 *   tuteur sans plus aucun élève est retiré (donnée inutile, loi 2013-450).
 * - La purge d'un compte emporte son dossier, ses liens et les tuteurs restés sans élève.
 * - Pas de champ libre. Hors de l'école : 404. École KLASSCI : 403 `catalogue_managed_by_klassci`.
 */
final class TuteursDeLEleveTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const TUTEUR = [
        'relation' => 'mere', 'nom' => 'Adjoua Konan', 'telephone' => '+2250700000009',
        'courriel' => 'adjoua@exemple.test', 'profession' => 'Commerçante', 'adresse' => 'Rue 12',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_la_gestion_ajoute_un_tuteur_et_le_dossier_le_montre(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();

        $reponse = $this->ajouter($admin, $awa, self::TUTEUR);

        $reponse->assertCreated()->assertJsonPath('data.relation', 'mere')->assertJsonPath('data.nom', 'Adjoua Konan');
        $this->assertMatchesOpenApiResponse($reponse, '/eleves/{eleve}/tuteurs', 'post');
        $this->asTenant($admin)->getJson("/api/eleves/{$awa->id}/dossier")
            ->assertJsonPath('data.dossier.tuteurs.0.nom', 'Adjoua Konan')
            ->assertJsonPath('data.dossier.tuteurs.0.relation', 'mere');
    }

    public function test_un_tuteur_se_partage_entre_freres_et_soeurs(): void
    {
        [$admin, $awa, $ecole] = $this->eleveAvecDossier();
        $tuteurId = $this->ajouter($admin, $awa, self::TUTEUR)->json('data.id');
        $yao = $this->membre($ecole, 'etudiant');
        $this->dossier($admin, $yao, 'Yao');

        $this->ajouter($admin, $yao, ['tuteur_id' => $tuteurId, 'relation' => 'mere'])->assertCreated()->assertJsonPath('data.id', $tuteurId);

        self::assertSame(1, DB::table('tuteurs')->count());
        self::assertSame(2, DB::table('dossier_tuteur')->where('tuteur_id', $tuteurId)->count());
    }

    public function test_un_tuteur_d_une_autre_ecole_est_introuvable(): void
    {
        [$adminAilleurs, $ailleurs] = $this->eleveAvecDossier();
        $tuteurAilleurs = $this->ajouter($adminAilleurs, $ailleurs, self::TUTEUR)->json('data.id');
        [$admin, $awa] = $this->eleveAvecDossier();

        $this->ajouter($admin, $awa, ['tuteur_id' => $tuteurAilleurs, 'relation' => 'pere'])->assertNotFound();
        $this->asTenant($admin)->putJson("/api/tuteurs/{$tuteurAilleurs}", ['nom' => 'Pirate'])->assertNotFound();
        $this->asTenant($admin)->deleteJson("/api/eleves/{$ailleurs->id}/tuteurs/{$tuteurAilleurs}")->assertNotFound();
        self::assertSame('Adjoua Konan', DB::table('tuteurs')->where('id', $tuteurAilleurs)->value('nom'));
    }

    public function test_sans_dossier_pas_de_tuteur(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $eleve = $this->membre($ecole, 'etudiant');

        $reponse = $this->ajouter($admin, $eleve, self::TUTEUR);

        $reponse->assertStatus(409)->assertJsonPath('reason', 'dossier_absent');
        $this->assertMatchesOpenApiResponse($reponse, '/eleves/{eleve}/tuteurs', 'post');
        self::assertSame(0, DB::table('tuteurs')->count());
    }

    public function test_422_relation_inconnue_ou_tuteur_sans_nom(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();

        $this->ajouter($admin, $awa, ['relation' => 'cousin'] + self::TUTEUR)->assertUnprocessable()->assertJsonValidationErrors('relation');
        $this->ajouter($admin, $awa, ['nom' => ''] + self::TUTEUR)->assertUnprocessable()->assertJsonValidationErrors('nom');
        $this->ajouter($admin, $awa, ['courriel' => 'pas-un-courriel'] + self::TUTEUR)->assertUnprocessable()->assertJsonValidationErrors('courriel');
        self::assertSame(0, DB::table('tuteurs')->count());
    }

    public function test_modifier_un_tuteur(): void
    {
        [$admin, $awa] = $this->eleveAvecDossier();
        $tuteurId = $this->ajouter($admin, $awa, self::TUTEUR)->json('data.id');

        $reponse = $this->asTenant($admin)->putJson("/api/tuteurs/{$tuteurId}", ['telephone' => '+2250700000010']);

        $reponse->assertOk()->assertJsonPath('data.telephone', '+2250700000010')->assertJsonPath('data.nom', 'Adjoua Konan');
        $this->assertMatchesOpenApiResponse($reponse, '/tuteurs/{tuteur}', 'put');
    }

    public function test_retirer_le_lien_et_un_tuteur_sans_eleve_disparait(): void
    {
        [$admin, $awa, $ecole] = $this->eleveAvecDossier();
        $tuteurId = $this->ajouter($admin, $awa, self::TUTEUR)->json('data.id');
        $yao = $this->membre($ecole, 'etudiant');
        $this->dossier($admin, $yao, 'Yao');
        $this->ajouter($admin, $yao, ['tuteur_id' => $tuteurId, 'relation' => 'mere'])->assertCreated();

        $this->asTenant($admin)->deleteJson("/api/eleves/{$awa->id}/tuteurs/{$tuteurId}")->assertOk();
        self::assertSame(1, DB::table('tuteurs')->where('id', $tuteurId)->count(), 'Yao a encore ce tuteur.');

        $this->asTenant($admin)->deleteJson("/api/eleves/{$yao->id}/tuteurs/{$tuteurId}")->assertOk();
        self::assertSame(0, DB::table('tuteurs')->where('id', $tuteurId)->count());
    }

    public function test_la_purge_d_un_compte_emporte_son_dossier_et_les_tuteurs_sans_eleve(): void
    {
        [$admin, $awa, $ecole] = $this->eleveAvecDossier();
        $seul = $this->ajouter($admin, $awa, self::TUTEUR)->json('data.id');
        $partage = $this->ajouter($admin, $awa, ['nom' => 'Paul Konan', 'relation' => 'pere'] + self::TUTEUR)->json('data.id');
        $yao = $this->membre($ecole, 'etudiant');
        $this->dossier($admin, $yao, 'Yao');
        $this->ajouter($admin, $yao, ['tuteur_id' => $partage, 'relation' => 'pere'])->assertCreated();

        User::query()->withoutGlobalScopes()->findOrFail($awa->id)->forceDelete();

        self::assertSame(0, DB::table('dossiers_eleves')->where('user_id', $awa->id)->count());
        self::assertSame(0, DB::table('tuteurs')->where('id', $seul)->count());
        self::assertSame(1, DB::table('tuteurs')->where('id', $partage)->count());
    }

    public function test_ecole_klassci_refuse_les_tuteurs(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $admin = $this->membre($ecole, 'admin');
        $eleve = $this->membre($ecole, 'etudiant');

        $this->ajouter($admin, $eleve, self::TUTEUR)->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertSame(0, DB::table('tuteurs')->count());
    }

    // ───────────────────────── harnais

    /** @return array{0: User, 1: User, 2: Institution} l'admin, l'élève (avec dossier), l'école */
    private function eleveAvecDossier(): array
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->dossier($admin, $eleve, 'Konan');

        return [$admin, $eleve, $ecole];
    }

    private function dossier(User $admin, User $eleve, string $nom): void
    {
        $this->asTenant($admin)->putJson("/api/eleves/{$eleve->id}/dossier", [
            'nom' => $nom, 'prenoms' => 'Prénom '.$eleve->id, 'date_naissance' => '2012-03-14', 'confirmer_doublon' => true,
        ])->assertCreated();
    }

    /** @param  array<string, mixed>  $corps */
    private function ajouter(User $admin, User $eleve, array $corps): TestResponse
    {
        return $this->asTenant($admin)->postJson("/api/eleves/{$eleve->id}/tuteurs", $corps);
    }
}

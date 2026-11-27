<?php

declare(strict_types=1);

namespace Tests\Feature\Inscription;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1658, PR 1 : valider, annuler ou garder sous réserve une inscription (ligne `classe_etudiant`), école autonome.
 *
 * La validation est un état À CÔTÉ du statut (`validation` : en_attente, validee, annulee) ; l'ACCÈS reste porté par le
 * seul statut (`actif`), que lisent tous les autres services.
 * - Réglage d'école `inscriptions_a_valider` : vrai, une inscription par CODE (le geste de l'élève) naît `inactif` et
 *   `en_attente`, et « valider » l'ouvre (`actif`), s'il reste une place ; faux, elle naît `actif` et `validee`, comme
 *   avant.
 * - Annuler (motif obligatoire) ferme l'accès comme une fin d'adhésion (#1783) : `inactif`, datée, `motif_fin`
 *   `annulation`. L'historique demeure.
 * - Une réserve (condition, pièce attendue) empêche la validation tant qu'elle n'est pas levée.
 * - Gestion d'une école autonome ; KLASSCI : 403 `catalogue_managed_by_klassci` ; autre école : 404. Audit.
 */
final class ValidationDesInscriptionsTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->travelTo('2026-11-27 10:00:00');
    }

    public function test_reglage_faux_l_inscription_par_code_nait_active_et_validee(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $awa = $this->apprenant($ecole);

        $this->asTenant($awa)->postJson('/api/me/inscriptions', ['code' => $code])->assertCreated();

        self::assertSame(['actif', 'validee'], $this->etat($classe, $awa));
    }

    public function test_reglage_vrai_l_inscription_par_code_nait_inactive_en_attente_et_le_dit(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $ecole->forceFill(['inscriptions_a_valider' => true])->save();
        $awa = $this->apprenant($ecole);

        $this->asTenant($awa)->postJson('/api/me/inscriptions', ['code' => $code])
            ->assertCreated()->assertJsonPath('data.validation', 'en_attente');

        self::assertSame(['inactif', 'en_attente'], $this->etat($classe, $awa));
        // Le même code, de nouveau : l'attente est dite, pas « inscription non active ».
        $this->asTenant($awa)->postJson('/api/me/inscriptions', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('reason', 'enrolment_pending');
    }

    public function test_valider_ouvre_l_acces_et_l_audit_le_garde(): void
    {
        [$ecole, $admin, $classe, $awa, $id] = $this->enAttente();

        $reponse = $this->geste($admin, $id, 'valider');

        $reponse->assertOk()->assertJsonPath('data.validation', 'validee')->assertJsonPath('data.statut', 'actif');
        $this->assertMatchesOpenApiResponse($reponse, '/inscriptions/{inscription}/valider', 'post');
        self::assertSame(['actif', 'validee'], $this->etat($classe, $awa));
        self::assertSame($admin->id, (int) $this->ligne($classe, $awa)?->valide_par);
        self::assertTrue(DB::table('audit_logs')->where('action', 'inscription.validee')->exists());
    }

    public function test_valider_une_classe_pleine_est_refuse(): void
    {
        [$ecole, $admin, $classe, $awa, $id] = $this->enAttente(capacite: 1);
        $this->adhesion($classe, $this->apprenant($ecole, 'deja@test.ci'), 'actif', 'validee');

        $this->geste($admin, $id, 'valider')->assertStatus(409)->assertJsonPath('reason', 'class_full');
        self::assertSame(['inactif', 'en_attente'], $this->etat($classe, $awa));
    }

    public function test_valider_hors_de_l_attente_est_refuse(): void
    {
        [, $admin, $classe, $awa, $id] = $this->enAttente();
        $this->geste($admin, $id, 'valider')->assertOk();

        $refus = $this->geste($admin, $id, 'valider');

        $refus->assertStatus(409)->assertJsonPath('reason', 'etat_de_validation');
        $this->assertMatchesOpenApiResponse($refus, '/inscriptions/{inscription}/valider', 'post');
    }

    public function test_une_reserve_empeche_la_validation_jusqu_a_sa_levee(): void
    {
        [, $admin, $classe, $awa, $id] = $this->enAttente();

        $this->asTenant($admin)->postJson("/api/inscriptions/{$id}/reserve", ['condition' => 'Relevé du baccalauréat', 'piece' => 'releve_bac'])
            ->assertOk()->assertJsonPath('data.reserve.condition', 'Relevé du baccalauréat');
        $this->geste($admin, $id, 'valider')->assertStatus(409)->assertJsonPath('reason', 'reserve_non_levee');

        $this->geste($admin, $id, 'lever-reserve')->assertOk()->assertJsonPath('data.reserve.levee', true);
        $this->geste($admin, $id, 'valider')->assertOk();
        self::assertSame(['actif', 'validee'], $this->etat($classe, $awa));
    }

    public function test_annuler_ferme_l_acces_garde_l_historique_et_exige_un_motif(): void
    {
        [$ecole, $admin, $classe] = $this->ecoleEtAdmin();
        $awa = $this->apprenant($ecole);
        $id = $this->adhesion($classe, $awa, 'actif', 'validee');

        $this->asTenant($admin)->postJson("/api/inscriptions/{$id}/annuler", [])->assertUnprocessable()->assertJsonValidationErrors('motif');
        $reponse = $this->asTenant($admin)->postJson("/api/inscriptions/{$id}/annuler", ['motif' => 'Dossier incomplet']);

        $reponse->assertOk()->assertJsonPath('data.validation', 'annulee')->assertJsonPath('data.statut', 'inactif');
        $this->assertMatchesOpenApiResponse($reponse, '/inscriptions/{inscription}/annuler', 'post');
        $ligne = $this->ligne($classe, $awa);
        self::assertSame(['inactif', 'annulee', 'annulation', '2026-11-27', 'Dossier incomplet'], [
            $ligne?->statut, $ligne?->validation, $ligne?->motif_fin, substr((string) $ligne?->ends_on, 0, 10), $ligne?->motif_annulation,
        ]);
        $this->geste($admin, $id, 'valider')->assertStatus(409)->assertJsonPath('reason', 'etat_de_validation');
    }

    public function test_la_fiche_redoublant_origine_affectation(): void
    {
        [$ecole, $admin, $classe] = $this->ecoleEtAdmin();
        $id = $this->adhesion($classe, $this->apprenant($ecole), 'actif', 'validee');

        $reponse = $this->asTenant($admin)->putJson("/api/inscriptions/{$id}", [
            'redoublant' => true, 'etablissement_origine' => 'Lycée moderne de Bouaké', 'affectation' => 'affecte',
        ]);

        $reponse->assertOk()->assertJsonPath('data.redoublant', true)->assertJsonPath('data.affectation', 'affecte');
        $this->assertMatchesOpenApiResponse($reponse, '/inscriptions/{inscription}', 'put');
        $this->asTenant($admin)->putJson("/api/inscriptions/{$id}", ['affectation' => 'mute'])->assertUnprocessable()->assertJsonValidationErrors('affectation');
    }

    public function test_le_reglage_de_l_ecole_se_regle_par_la_gestion(): void
    {
        [$ecole, $admin] = $this->ecoleEtAdmin();

        $reponse = $this->asTenant($admin)->putJson('/api/inscriptions/reglage', ['a_valider' => true]);

        $reponse->assertOk()->assertJsonPath('data.a_valider', true);
        $this->assertMatchesOpenApiResponse($reponse, '/inscriptions/reglage', 'put');
        self::assertTrue((bool) Institution::query()->whereKey($ecole->id)->value('inscriptions_a_valider'));
    }

    /** L'isolation : une inscription d'une autre école est introuvable, du même corps qu'un id absent. */
    public function test_404_une_inscription_d_une_autre_ecole(): void
    {
        [, $admin] = $this->ecoleEtAdmin();
        [$ailleurs, , $classeAilleurs] = $this->ecoleEtAdmin();
        $id = $this->adhesion($classeAilleurs, $this->apprenant($ailleurs, 'yao@test.ci'), 'inactif', 'en_attente');

        $absente = $this->geste($admin, 999999, 'valider')->assertNotFound();
        self::assertSame($absente->json(), $this->geste($admin, $id, 'valider')->assertNotFound()->json());
        self::assertSame('en_attente', DB::table('classe_etudiant')->where('id', $id)->value('validation'));
    }

    public function test_403_hors_de_la_gestion_et_en_ecole_klassci(): void
    {
        [$ecole, , $classe] = $this->ecoleEtAdmin();
        $id = $this->adhesion($classe, $this->apprenant($ecole), 'inactif', 'en_attente');
        $enseignant = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant', 'klassci_token' => null]);
        $this->geste($enseignant, $id, 'valider')->assertForbidden();

        $klassci = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $adminKlassci = User::factory()->create(['institution_id' => $klassci->id, 'role' => 'superAdmin', 'klassci_token' => null]);
        $classeKlassci = Classe::factory()->create(['institution_id' => $klassci->id, 'klassci_id' => 4242, 'effectif' => 0]);
        $idKlassci = $this->adhesion($classeKlassci, $this->apprenant($klassci, 'k@test.ci'), 'actif', null);

        $this->geste($adminKlassci, $idKlassci, 'valider')->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        $this->asTenant($adminKlassci)->putJson('/api/inscriptions/reglage', ['a_valider' => true])->assertForbidden();
    }

    // ───────────────────────── harnais

    /** @return array{0: Institution, 1: User, 2: Classe} */
    private function ecoleEtAdmin(int $capacite = 0): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        $admin = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'effectif' => $capacite]);

        return [$ecole, $admin, $classe];
    }

    /** @return array{0: Institution, 1: User, 2: Classe, 3: User, 4: int} une inscription en attente */
    private function enAttente(int $capacite = 0): array
    {
        [$ecole, $admin, $classe] = $this->ecoleEtAdmin($capacite);
        $awa = $this->apprenant($ecole);

        return [$ecole, $admin, $classe, $awa, $this->adhesion($classe, $awa, 'inactif', 'en_attente')];
    }

    private function adhesion(Classe $classe, User $eleve, string $statut, ?string $validation): int
    {
        return (int) DB::table('classe_etudiant')->insertGetId([
            'classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $classe->institution_id,
            'statut' => $statut, 'validation' => $validation, 'date_inscription' => '2026-11-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function geste(User $acteur, int $id, string $geste): TestResponse
    {
        return $this->asTenant($acteur)->postJson("/api/inscriptions/{$id}/{$geste}");
    }

    /** @return list<mixed> [statut, validation] */
    private function etat(Classe $classe, User $eleve): array
    {
        $ligne = $this->ligne($classe, $eleve);

        return [$ligne?->statut, $ligne?->validation];
    }

    private function ligne(Classe $classe, User $eleve): ?object
    {
        return DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->first();
    }
}

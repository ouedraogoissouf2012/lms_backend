<?php

declare(strict_types=1);

namespace Tests\Feature\Enseignants;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1666 (ARRÊT 1 validé le 28/11) — le profil d'un enseignant d'une école créée depuis le LMS :
 * - matricule FACULTATIF, saisi librement, unique par école (les nuls permis), spécialité, titre, grade (énumération
 *   fermée), diplôme, université, date de début, statut (en activité / inactif), charge hebdomadaire maximale ;
 * - la gestion écrit ; l'enseignant lit SON profil ; l'annuaire local rend le vrai profil (plus de `null` fabriqués) ;
 * - la liste se filtre par statut et par spécialité, dans les DEUX volets (sur la valeur rendue) ;
 * - école KLASSCI : le profil se lit dans KLASSCI (les trois champs relayés) ; l'écrire ou le lire ici est refusé
 *   (403 `teacher_profile_managed_by_klassci`).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class ProfilEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private User $coordinateur;

    private User $awa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->awa = $this->membre($this->ecole, 'enseignant');
    }

    public function test_la_gestion_ecrit_le_profil_et_il_se_relit(): void
    {
        $this->ecrire($this->awa, $this->profil())->assertOk()->assertJsonPath('data.matricule', 'P-2019-014');

        $this->asTenant($this->coordinateur)->getJson($this->chemin($this->awa))->assertOk()
            ->assertJsonPath('data.enseignant_id', $this->awa->id)
            ->assertJsonPath('data.specialite', 'Mathématiques')
            ->assertJsonPath('data.titre', 'Dr')
            ->assertJsonPath('data.grade', 'maitre_assistant')
            ->assertJsonPath('data.diplome', 'Doctorat')
            ->assertJsonPath('data.universite', 'Université Félix Houphouët-Boigny')
            ->assertJsonPath('data.date_debut', '2019-09-02')
            ->assertJsonPath('data.statut', 'en_activite')
            ->assertJsonPath('data.charge_hebdo_max', 18);
    }

    public function test_un_enseignant_sans_profil_lit_des_champs_vides(): void
    {
        $this->asTenant($this->coordinateur)->getJson($this->chemin($this->awa))->assertOk()
            ->assertJsonPath('data.matricule', null)
            ->assertJsonPath('data.statut', null);
    }

    public function test_le_matricule_est_facultatif_et_unique_par_ecole(): void
    {
        $this->ecrire($this->awa, $this->profil())->assertOk();
        $moussa = $this->membre($this->ecole, 'enseignant');

        $this->ecrire($moussa, ['matricule' => 'P-2019-014'] + $this->profil())
            ->assertUnprocessable()->assertJsonPath('reason', 'matricule_deja_pris');
        $this->ecrire($moussa, ['matricule' => null] + $this->profil())->assertOk();
        $this->ecrire($this->membre($this->ecole, 'enseignant'), ['matricule' => null] + $this->profil())->assertOk();

        $ailleurs = $this->ecole();
        $this->asTenant($this->membre($ailleurs, 'coordinateur'))
            ->putJson($this->chemin($this->membre($ailleurs, 'enseignant')), $this->profil())->assertOk();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saisiesRefusees(): array
    {
        return [
            'grade hors énumération' => [['grade' => 'docteur_honoris'], 'grade'],
            'statut hors énumération' => [['statut' => 'retraite'], 'statut'],
            'charge nulle' => [['charge_hebdo_max' => 0], 'charge_hebdo_max'],
            'charge excessive' => [['charge_hebdo_max' => 61], 'charge_hebdo_max'],
            'date illisible' => [['date_debut' => '02/09/2019'], 'date_debut'],
        ];
    }

    /** @param  array<string, mixed>  $champs */
    #[DataProvider('saisiesRefusees')]
    public function test_une_saisie_invalide_est_refusee(array $champs, string $champ): void
    {
        $this->ecrire($this->awa, $champs + $this->profil())->assertUnprocessable()->assertJsonValidationErrors([$champ]);
    }

    public function test_l_enseignant_lit_son_profil_mais_ne_l_ecrit_pas(): void
    {
        $this->ecrire($this->awa, $this->profil())->assertOk();

        $this->asTenant($this->awa)->getJson($this->chemin($this->awa))->assertOk()->assertJsonPath('data.matricule', 'P-2019-014');
        $this->asTenant($this->awa)->putJson($this->chemin($this->awa), $this->profil())->assertForbidden();
        $this->asTenant($this->awa)->getJson($this->chemin($this->membre($this->ecole, 'enseignant')))->assertNotFound();
    }

    public function test_un_compte_qui_n_est_pas_enseignant_ou_d_une_autre_ecole_est_introuvable(): void
    {
        $this->ecrire($this->membre($this->ecole, 'etudiant'), $this->profil())->assertNotFound();
        $this->ecrire($this->membre($this->ecole(), 'enseignant'), $this->profil())->assertNotFound();
    }

    public function test_l_annuaire_local_rend_le_vrai_profil_et_se_filtre(): void
    {
        $this->ecrire($this->awa, $this->profil())->assertOk();
        $moussa = $this->membre($this->ecole, 'enseignant');
        $this->ecrire($moussa, ['matricule' => 'P-2020-002', 'specialite' => 'Physique', 'statut' => 'inactif'] + $this->profil())->assertOk();
        $sansProfil = $this->membre($this->ecole, 'enseignant');

        $lignes = collect($this->asTenant($this->coordinateur)->getJson('/api/lms/enseignants')->assertOk()->json('data'))->keyBy('id');
        self::assertSame(['P-2019-014', 'Mathématiques', 'en_activite'], [$lignes[$this->awa->id]['matricule'], $lignes[$this->awa->id]['specialization'], $lignes[$this->awa->id]['status']]);
        self::assertSame([null, null, null], [$lignes[$sansProfil->id]['matricule'], $lignes[$sansProfil->id]['specialization'], $lignes[$sansProfil->id]['status']]);

        self::assertSame([$moussa->id], $this->idsFiltres('?statut=inactif'));
        self::assertSame([$this->awa->id], $this->idsFiltres('?specialite=Mathématiques&statut=en_activite'));
    }

    public function test_l_annuaire_klassci_se_filtre_sur_ses_valeurs_relayees(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEnseignantsEnrichis')->andReturn(['success' => true, 'data' => [
                ['id' => 71, 'nom' => 'Kouassi', 'matricule' => 'K-1', 'specialization' => 'Maths', 'status' => 'actif'],
                ['id' => 72, 'nom' => 'Traoré', 'matricule' => 'K-2', 'specialization' => 'Physique', 'status' => 'inactif'],
            ]]);
        });
        $coordinateur = $this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur');

        $ids = array_column($this->asTenant($coordinateur)->getJson('/api/lms/enseignants?specialite=Physique')->assertOk()->json('data'), 'id');

        self::assertSame([72], $ids);
    }

    public function test_une_ecole_klassci_tient_le_profil_dans_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $enseignant = $this->membre($ecole, 'enseignant');

        $this->asTenant($coordinateur)->putJson($this->chemin($enseignant), $this->profil())
            ->assertForbidden()->assertJsonPath('reason', 'teacher_profile_managed_by_klassci');
        $this->asTenant($coordinateur)->getJson($this->chemin($enseignant))
            ->assertForbidden()->assertJsonPath('reason', 'teacher_profile_managed_by_klassci');
    }

    /** @return array<string, mixed> */
    private function profil(): array
    {
        return [
            'matricule' => 'P-2019-014',
            'specialite' => 'Mathématiques',
            'titre' => 'Dr',
            'grade' => 'maitre_assistant',
            'diplome' => 'Doctorat',
            'universite' => 'Université Félix Houphouët-Boigny',
            'date_debut' => '2019-09-02',
            'statut' => 'en_activite',
            'charge_hebdo_max' => 18,
        ];
    }

    /** @param  array<string, mixed>  $champs */
    private function ecrire(User $enseignant, array $champs): TestResponse
    {
        return $this->asTenant($this->coordinateur)->putJson($this->chemin($enseignant), $champs);
    }

    private function chemin(User $enseignant): string
    {
        return "/api/lms/enseignants/{$enseignant->id}/profil";
    }

    /** @return list<int> */
    private function idsFiltres(string $filtre): array
    {
        return array_map('intval', array_column($this->asTenant($this->coordinateur)->getJson('/api/lms/enseignants'.$filtre)->assertOk()->json('data'), 'id'));
    }
}

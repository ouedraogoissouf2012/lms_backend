<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Admission\DroitDInscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1619 : l'administration d'une école autonome INSCRIT un élève existant dans une classe, et l'en RETIRE.
 *
 * Avant : un élève n'entrait dans une classe que par un code (son geste) ou un import. L'admin qui venait de créer un
 * élève ne pouvait pas le placer.
 *
 * - Inscrire : `POST /api/classes/{classe}/eleves { eleve_id }`. C'est l'entrée décidée par l'école
 *   (`AdmissionParLEcole`, #1783) : ni classe archivée, ni Période close, ni élève déjà actif, ni classe pleine (409).
 *   Une ancienne adhésion est réactivée, jamais doublée.
 * - Retirer : `DELETE /api/classes/{classe}/eleves/{eleve}`. L'adhésion active se ferme (`inactif`, datée, motif
 *   `retrait`) ; le dossier demeure.
 * - Le droit est celui des INSCRIPTIONS (`PorteDesAdmissions`, comme #1817) : école KLASSCI, 403
 *   `enrolment_managed_by_klassci`. Une classe miroir de l'école : 403 `catalogue_managed_by_klassci`, un refus motivé.
 *   Classe ou élève d'une autre école : 404, du même corps qu'un
 *   id absent. Un compte qui n'est pas un élève : 422. Vrai jeton (`asTenant`).
 */
final class LAdminInscritUnEleveTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const INSCRIRE = '/classes/{classe}/eleves';

    private const RETIRER = '/classes/{classe}/eleves/{eleve}';

    public function test_l_admin_inscrit_un_eleve_existant(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole);
        $eleve = $this->eleve($ecole);

        $reponse = $this->inscrire($gestion, $classe, $eleve);

        $reponse->assertCreated()->assertJsonPath('data.eleve_id', $eleve->id)->assertJsonPath('data.classe_id', $classe->id);
        $this->assertMatchesOpenApiResponse($reponse, self::INSCRIRE, 'post');
        $ligne = $this->ligne($classe, $eleve);
        self::assertSame(['actif', '2026-11-27', $ecole->id], [$ligne?->statut, substr((string) $ligne?->date_inscription, 0, 10), (int) $ligne?->institution_id]);
    }

    public function test_le_coordinateur_inscrit_aussi(): void
    {
        [$ecole] = $this->ecole();
        $coordinateur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur', 'klassci_token' => null]);

        $this->inscrire($coordinateur, $this->classe($ecole), $this->eleve($ecole))->assertCreated();
    }

    public function test_retirer_ferme_l_adhesion_et_reinscrire_la_reactive_sans_doublon(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole);
        $eleve = $this->eleve($ecole);
        $this->adhesion($classe, $eleve, 'actif');

        $retrait = $this->retirer($gestion, $classe, $eleve);

        $retrait->assertOk();
        $this->assertMatchesOpenApiResponse($retrait, self::RETIRER, 'delete');
        $ligne = $this->ligne($classe, $eleve);
        self::assertSame(['inactif', '2026-11-27', 'retrait'], [$ligne?->statut, substr((string) $ligne?->ends_on, 0, 10), $ligne?->motif_fin]);

        $this->inscrire($gestion, $classe, $eleve)->assertCreated();
        self::assertSame(1, DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->count());
        $ligne = $this->ligne($classe, $eleve);
        self::assertSame(['actif', null, null], [$ligne?->statut, $ligne?->ends_on, $ligne?->motif_fin]);
    }

    public function test_les_refus_de_l_admission_passent_tels_quels_et_rien_ne_s_ecrit(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $pleine = $this->classe($ecole, capacite: 1);
        $this->adhesion($pleine, $this->eleve($ecole), 'actif');
        $archivee = $this->classe($ecole);
        DB::table('classes')->where('id', $archivee->id)->update(['archivee_le' => now()]);
        $close = $this->classe($ecole, periode: TrainingSession::factory()->create(['institution_id' => $ecole->id, 'status' => TrainingSessionStatus::Cloturee]));
        $dejaLa = $this->classe($ecole);
        $eleve = $this->eleve($ecole);
        $this->adhesion($dejaLa, $eleve, 'actif');

        foreach (['class_full' => $pleine, 'class_archived' => $archivee, 'class_period_closed' => $close, 'already_in_class' => $dejaLa] as $motif => $classe) {
            $reponse = $this->inscrire($gestion, $classe, $eleve);
            $reponse->assertStatus(409)->assertJsonPath('reason', $motif);
            $this->assertMatchesOpenApiResponse($reponse, self::INSCRIRE, 'post');
        }
        self::assertNull($this->ligne($pleine, $eleve));
        self::assertNull($this->ligne($archivee, $eleve));
        self::assertNull($this->ligne($close, $eleve));
    }

    public function test_422_un_compte_qui_n_est_pas_un_eleve_ou_sans_eleve(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole);
        $enseignant = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);

        $this->inscrire($gestion, $classe, $enseignant)->assertUnprocessable()->assertJsonValidationErrors('eleve_id');
        $sans = $this->asTenant($gestion)->postJson("/api/classes/{$classe->id}/eleves", []);
        $sans->assertUnprocessable()->assertJsonValidationErrors('eleve_id');
        $this->assertMatchesOpenApiResponse($sans, self::INSCRIRE, 'post');
        self::assertSame(0, DB::table('classe_etudiant')->where('classe_id', $classe->id)->count());
    }

    public function test_retirer_sans_adhesion_404_et_une_adhesion_close_409(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole);
        $close = $this->eleve($ecole);
        $this->adhesion($classe, $close, 'abandonne');

        $this->retirer($gestion, $classe, $this->eleve($ecole))->assertNotFound();
        $this->retirer($gestion, $classe, $close)->assertStatus(409)->assertJsonPath('reason', 'enrolment_not_active');
        self::assertSame('abandonne', $this->ligne($classe, $close)?->statut);
    }

    /** L'isolation : une classe ou un élève d'une autre école est introuvable, du même corps qu'un id absent. */
    public function test_une_classe_ou_un_eleve_d_une_autre_ecole_est_introuvable(): void
    {
        [$ecole, $gestion] = $this->ecole();
        [$ailleurs] = $this->ecole();
        $classe = $this->classe($ecole);
        $eleve = $this->eleve($ecole);
        $classeAilleurs = $this->classe($ailleurs);
        $eleveAilleurs = $this->eleve($ailleurs);
        $this->adhesion($classeAilleurs, $eleveAilleurs, 'actif');

        $absente = $this->asTenant($gestion)->postJson('/api/classes/999999/eleves', ['eleve_id' => $eleve->id]);
        $absente->assertNotFound();
        foreach ([[$classeAilleurs, $eleve], [$classe, $eleveAilleurs]] as [$c, $e]) {
            $reponse = $this->inscrire($gestion, $c, $e);
            $reponse->assertNotFound();
            self::assertSame($absente->json(), $reponse->json());
        }
        $this->retirer($gestion, $classeAilleurs, $eleveAilleurs)->assertNotFound();

        self::assertSame('actif', $this->ligne($classeAilleurs, $eleveAilleurs)?->statut);
        self::assertSame(0, DB::table('classe_etudiant')->where('user_id', $eleve->id)->count());
    }

    public function test_403_hors_de_la_gestion(): void
    {
        [$ecole] = $this->ecole();
        $classe = $this->classe($ecole);
        $eleve = $this->eleve($ecole);

        foreach (['enseignant', 'etudiant'] as $role) {
            $acteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => null]);
            $this->inscrire($acteur, $classe, $eleve)->assertForbidden();
        }
        self::assertNull($this->ligne($classe, $eleve));
    }

    public function test_une_ecole_klassci_et_une_classe_miroir_sont_refusees(): void
    {
        [$klassci, $gestionKlassci] = $this->ecole(InstitutionMode::Klassci);
        $classe = $this->classe($klassci);
        $eleve = $this->eleve($klassci);
        $this->adhesion($classe, $eleve, 'actif');

        $inscription = $this->inscrire($gestionKlassci, $classe, $this->eleve($klassci));
        $inscription->assertForbidden()->assertJsonPath('reason', 'enrolment_managed_by_klassci');
        $this->assertMatchesOpenApiResponse($inscription, self::INSCRIRE, 'post');
        $this->retirer($gestionKlassci, $classe, $eleve)->assertForbidden()->assertJsonPath('reason', 'enrolment_managed_by_klassci');

        [$ecole, $gestion] = $this->ecole();
        $miroir = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242, 'effectif' => 0]);
        $this->inscrire($gestion, $miroir, $this->eleve($ecole))->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');

        self::assertSame('actif', $this->ligne($classe, $eleve)?->statut);
    }

    /**
     * Doublures DIVERGENTES : le droit d'inscription fermé dans une école dont le catalogue est ouvert. Si le refus
     * venait du catalogue, l'inscription passerait ; il vient de l'autorité d'inscription.
     */
    public function test_inscription_fermee_et_catalogue_ouvert_l_admin_n_inscrit_pas(): void
    {
        [$ecole, $gestion] = $this->ecole();
        $classe = $this->classe($ecole);
        $inscrit = $this->eleve($ecole);
        $this->adhesion($classe, $inscrit, 'actif');
        $this->droitDInscription(false);

        $this->inscrire($gestion, $classe, $this->eleve($ecole))->assertForbidden()->assertJsonPath('reason', 'enrolment_managed_by_klassci');
        $this->retirer($gestion, $classe, $inscrit)->assertForbidden()->assertJsonPath('reason', 'enrolment_managed_by_klassci');

        self::assertSame(1, DB::table('classe_etudiant')->where('classe_id', $classe->id)->count());
        self::assertSame('actif', $this->ligne($classe, $inscrit)?->statut);
    }

    /** L'inverse : le droit d'inscription ouvert dans une école KLASSCI (catalogue fermé) laisse inscrire. */
    public function test_inscription_ouverte_et_catalogue_ferme_l_admin_inscrit(): void
    {
        [$klassci, $gestion] = $this->ecole(InstitutionMode::Klassci);
        $classe = $this->classe($klassci);
        $eleve = $this->eleve($klassci);
        $this->droitDInscription(true);

        $this->inscrire($gestion, $classe, $eleve)->assertCreated();

        self::assertSame('actif', $this->ligne($classe, $eleve)?->statut);
    }

    // ───────────────────────── harnais (celui de UnEleveChangeDeClasseTest, #1783)

    private function inscrire(User $gestion, Classe $classe, User $eleve): TestResponse
    {
        return $this->asTenant($gestion)->postJson("/api/classes/{$classe->id}/eleves", ['eleve_id' => $eleve->id]);
    }

    private function retirer(User $gestion, Classe $classe, User $eleve): TestResponse
    {
        return $this->asTenant($gestion)->deleteJson("/api/classes/{$classe->id}/eleves/{$eleve->id}");
    }

    private function ligne(Classe $classe, User $eleve): ?object
    {
        return DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->first();
    }

    /** @return array{0: Institution, 1: User} */
    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null])];
    }

    private function classe(Institution $ecole, int $capacite = 0, ?TrainingSession $periode = null): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $ecole->id, 'klassci_id' => null, 'effectif' => $capacite, 'training_session_id' => $periode?->id,
        ]);
    }

    private function eleve(Institution $ecole): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']);
    }

    /** @param array<string, mixed> $plus */
    private function adhesion(Classe $classe, User $eleve, string $statut, array $plus = []): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $classe->institution_id,
            'statut' => $statut, 'date_inscription' => '2026-01-10', 'created_at' => now(), 'updated_at' => now(),
        ] + $plus);
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
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Enums\InstitutionMode;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\KlassciSeanceDesigneeStricte;
use App\Services\Seances\Mutations\SeanceHideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Le masquage d'une séance (`/lms/seances/{id}/hide` et `unhide`) et la
 * synchronisation des présences (`/lms/attendances/from-video-session`) désignent
 * la séance par l'identifiant du FIL, hors collision, dans les deux types d'école :
 * l'id KLASSCI d'un miroir en école KLASSCI, l'id local en école autonome. Et
 * jamais une séance d'un autre établissement, même sans tenant.
 *
 * La COLLISION (une séance d'id local N, une autre de `klassci_seance_id` N) n'appelle
 * aucun ordre : chaque chemin désigne la séance de l'espace de l'école (ADR-1154-01).
 * Elle est prouvée dans {@see ChaquePorteDesigneLaSeanceDeSonEspaceTest} (#1566), qui
 * remplace le test laissé incomplet en attendant D439.
 */
final class DesignationHorsCollisionTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** École KLASSCI : un élève masque puis réaffiche un miroir par son id KLASSCI. */
    public function test_ecole_klassci_le_masquage_designe_le_miroir_par_son_id_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $seance = Seance::factory()->for($ecole)->create(['klassci_seance_id' => 900]);

        $this->assertMasqueEtReaffiche($this->membre($ecole, 'etudiant'), 900, $seance);
    }

    /** École autonome : un élève masque puis réaffiche une séance par son id local. */
    public function test_ecole_autonome_le_masquage_designe_la_seance_par_son_id_local(): void
    {
        $ecole = $this->ecole();
        $seance = Seance::factory()->for($ecole)->create(['klassci_seance_id' => null]);

        $this->assertMasqueEtReaffiche($this->membre($ecole, 'etudiant'), $seance->id, $seance);
    }

    /** Sans tenant, le masquage ne désigne jamais la séance d'un autre établissement. */
    public function test_sans_tenant_le_masquage_reste_dans_l_etablissement_du_compte(): void
    {
        $ailleurs = Seance::factory()->for($this->ecole(InstitutionMode::Klassci))->create(['klassci_seance_id' => 901]);
        $eleve = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $this->app->forgetScopedInstances();

        // La désignation est celle que la route reçoit en école KLASSCI (#1566).
        $resultat = app(SeanceHideService::class)->hide(901, $eleve, app(KlassciSeanceDesigneeStricte::class));

        self::assertSame(404, $resultat['status']);
        self::assertSame(0, DB::table('seance_user_hidden')->where('seance_id', $ailleurs->id)->count());
    }

    /**
     * Présences, dans les deux types d'école : l'id du fil désigne la séance que
     * l'enseignant gère. La séance est trouvée (le refus vient de l'élève non
     * inscrit, et non d'une séance introuvable) ; un id inconnu rend 404.
     */
    public function test_les_presences_designent_la_seance_par_l_id_du_fil(): void
    {
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $enseignantK = $this->membre($klassci, 'enseignant');
        $enseignantK->forceFill(['klassci_id' => 42])->save();
        Seance::factory()->for($klassci)->create(['klassci_seance_id' => 902, 'klassci_enseignant_id' => 42]);

        $autonome = $this->ecole();
        $enseignantA = $this->membre($autonome, 'enseignant');
        $locale = Seance::factory()->for($autonome)->create(['klassci_seance_id' => null, 'klassci_enseignant_id' => null, 'created_by' => $enseignantA->id]);

        foreach ([[$enseignantK, 902], [$enseignantA, $locale->id]] as [$enseignant, $id]) {
            $this->presences($enseignant, $id)->assertStatus(403)->assertJsonPath('reason', 'not_enrolled');
            $this->presences($enseignant, 987654)->assertNotFound();
        }
    }

    private function assertMasqueEtReaffiche(User $eleve, int $id, Seance $seance): void
    {
        $this->asTenant($eleve)->postJson("/api/lms/seances/{$id}/hide")->assertOk();
        self::assertSame(1, DB::table('seance_user_hidden')->where('seance_id', $seance->id)->where('user_id', $eleve->id)->count());

        $this->asTenant($eleve)->postJson("/api/lms/seances/{$id}/unhide")->assertOk();
        self::assertSame(0, DB::table('seance_user_hidden')->where('seance_id', $seance->id)->where('user_id', $eleve->id)->count());
    }

    private function presences(User $enseignant, int $id): TestResponse
    {
        return $this->asTenant($enseignant)->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => $id,
            'date' => now()->toDateString(),
            'participants' => [['etudiant_id' => 999999, 'statut' => 'present']],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeanceLocale;
use Tests\TestCase;

/**
 * `GET /lms/seances/{id}/participants` dans une école en mode autonome.
 *
 * Avant : la route exigeait le jeton KLASSCI (401) et cherchait la séance dans les
 * matières KLASSCI du porteur. Dans une école en mode autonome, le personnel recevait
 * 401 (relevé par Fille 1, parcours des présences).
 *
 * Source locale (ARRÊT 1 validé) : la séance par son id local, bornée à
 * l'établissement ; accès par `VisioActorAuthorization::canManage` (son auteur, ou la
 * gestion), comme KLASSCI, où l'enseignant ne trouve que les séances de ses matières ;
 * élèves par `ClasseAudienceSource::studentsOf` (la règle de l'admission) ; `teacher`
 * est l'auteur. Même forme que la source KLASSCI.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class ParticipantsSeanceLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeanceLocale;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ecoleAutonome();
    }

    public function test_l_auteur_voit_les_eleves_actifs_de_la_classe_sans_klassci(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);
        $actif = $this->eleveDe($this->classe);
        $this->eleveDe($this->classe, 'inactif');
        $this->eleveDe(Classe::factory()->for($this->ecole)->create(['klassci_id' => null]));
        $this->aucunAppelKlassci();

        $this->asTenant($auteur)->getJson($this->de($seance->id))
            ->assertOk()
            ->assertJsonPath('data.seance.id', $seance->id)
            ->assertJsonPath('data.teacher.id', $auteur->id)
            ->assertJsonPath('data.students.*.id', [$actif->id])
            ->assertJsonPath('data.total_participants', 2);
    }

    public function test_la_gestion_voit_les_participants(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);
        $actif = $this->eleveDe($this->classe);

        $this->asTenant($this->compte('coordinator'))->getJson($this->de($seance->id))
            ->assertOk()
            ->assertJsonPath('data.teacher.id', $auteur->id)
            ->assertJsonPath('data.students.*.id', [$actif->id]);
    }

    public function test_un_collegue_qui_ne_gere_pas_la_seance_recoit_404(): void
    {
        $seance = $this->seanceLocale($this->compte('teacher'));
        $this->eleveDe($this->classe);

        $this->asTenant($this->compte('teacher'))->getJson($this->de($seance->id))->assertNotFound();
    }

    public function test_la_seance_d_une_autre_ecole_est_introuvable(): void
    {
        $ailleurs = Institution::factory()->standalone()->create();
        $seance = $this->seanceLocale($this->compte('teacher', $ailleurs), ['institution_id' => $ailleurs->id]);

        $this->asTenant($this->compte('coordinator'))->getJson($this->de($seance->id))->assertNotFound();
    }

    /** Un compte d'une autre école présent dans le pivot n'est pas compté (la règle de l'admission). */
    public function test_un_compte_d_une_autre_ecole_dans_le_pivot_n_est_pas_un_participant(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);
        $actif = $this->eleveDe($this->classe);
        $etranger = $this->compte('student', Institution::factory()->standalone()->create());
        $this->classe->etudiants()->attach($etranger->id, ['statut' => 'actif']);

        $this->asTenant($auteur)->getJson($this->de($seance->id))
            ->assertOk()
            ->assertJsonPath('data.students.*.id', [$actif->id]);
    }

    /** Laravel garde le contrôleur sur l'objet Route : la source se reçoit par la MÉTHODE. */
    public function test_la_meme_route_sert_une_ecole_klassci_puis_une_ecole_autonome(): void
    {
        $klassci = User::factory()->teacher()->for(Institution::factory()->create())->create(['klassci_token' => null]);
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);

        $this->asTenant($klassci)->getJson($this->de(4242))
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
        $this->asTenant($auteur)->getJson($this->de($seance->id))->assertOk()->assertJsonPath('data.teacher.id', $auteur->id);
    }

    private function de(int $seanceId): string
    {
        return '/api/lms/seances/'.$seanceId.'/participants';
    }

    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
    }
}

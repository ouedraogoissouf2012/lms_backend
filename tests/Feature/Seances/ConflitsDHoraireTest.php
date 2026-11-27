<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\SeanceCalendarProjection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeanceLocale;
use Tests\TestCase;

/**
 * #1668 — une même personne n'anime pas deux visios à la fois, une même classe n'en suit
 * pas deux : la création et la modification d'une séance locale refusent un chevauchement.
 *
 * - L'intervalle est `[début, fin effective)` : `fin_seance`, à défaut début +
 *   {@see SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES} (la règle déjà lue par le
 *   calendrier). Deux créneaux contigus ne se gênent pas.
 * - « Même enseignant » : la règle de propriété des séances (`created_by`, ou l'identité
 *   KLASSCI de l'enseignant pour une séance miroir d'une école basculée).
 * - « Même classe » : ses deux identités, chacune dans son espace (`classe_id`, ou
 *   `klassci_classe_id` = son `klassci_id`).
 * - Le refus est un 409 `seance_schedule_conflict` qui nomme les séances, sous `conflicts`.
 *   La gestion peut forcer (`forcer_conflit`) ; l'enseignant reçoit 403
 *   `conflict_override_forbidden`.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class ConflitsDHoraireTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeanceLocale;
    use RefreshDatabase;

    private const CREER = '/api/lms/seances';

    private User $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01 07:00:00');
        $this->disableKlassciMiddleware();
        $this->ecoleAutonome();
        $this->enseignant = $this->compte('teacher');
    }

    public function test_meme_enseignant_sur_un_creneau_chevauchant_409_qui_nomme_la_seance(): void
    {
        $occupee = $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00') + ['titre' => 'Algèbre']);

        $reponse = $this->creer($this->enseignant, '09:00', '11:00', $this->autreClasse())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'seance_schedule_conflict');

        $this->assertSame([[
            'id' => $occupee->id,
            'titre' => 'Algèbre',
            'debut' => '2026-12-02T08:00:00+00:00',
            'fin' => '2026-12-02T10:00:00+00:00',
            'motifs' => ['enseignant'],
        ]], $reponse->json('conflicts'));
        $this->assertSame(1, Seance::query()->count());
    }

    public function test_meme_classe_avec_un_autre_enseignant_409_motif_classe(): void
    {
        $this->seanceLocale($this->compte('teacher'), $this->creneau('08:00', '10:00'));

        $this->creer($this->enseignant, '09:30', '10:30', $this->classe)
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.motifs', ['classe']);
    }

    public function test_meme_enseignant_et_meme_classe_les_deux_motifs(): void
    {
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'));

        $this->creer($this->enseignant, '09:00', '09:30', $this->classe)
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.motifs', ['enseignant', 'classe']);
    }

    public function test_creneaux_contigus_acceptes(): void
    {
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'));

        $this->creer($this->enseignant, '10:00', '11:00', $this->classe)->assertCreated();
    }

    public function test_sans_fin_la_seance_occupe_la_duree_supposee(): void
    {
        $this->seanceLocale($this->enseignant, ['date_seance' => '2026-12-02 08:00:00', 'fin_seance' => null]);
        $finSupposee = Carbon::parse('2026-12-02 08:00:00')->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);

        $this->creer($this->enseignant, $finSupposee->copy()->subMinute()->format('H:i'), null, $this->autreClasse())
            ->assertStatus(409);
        $this->creer($this->enseignant, $finSupposee->format('H:i'), null, $this->autreClasse())
            ->assertCreated();
    }

    public function test_autre_enseignant_et_autre_classe_acceptes(): void
    {
        $this->seanceLocale($this->compte('teacher'), $this->creneau('08:00', '10:00'));

        $this->creer($this->enseignant, '08:00', '10:00', $this->autreClasse())->assertCreated();
    }

    public function test_une_seance_supprimee_ou_inactive_ne_gene_pas(): void
    {
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'))->delete();
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00') + ['is_active' => false]);

        $this->creer($this->enseignant, '08:00', '10:00', $this->classe)->assertCreated();
    }

    public function test_une_autre_ecole_n_est_jamais_un_conflit(): void
    {
        $voisine = Institution::factory()->standalone()->create();
        $sienne = $this->ecole;
        $this->ecole = $voisine;
        $this->classe = Classe::factory()->for($voisine)->create(['klassci_id' => null]);
        $this->seanceLocale($this->compte('teacher', $voisine), $this->creneau('08:00', '10:00') + ['created_by' => $this->enseignant->id]);
        $this->ecole = $sienne;

        $this->creer($this->enseignant, '08:00', '10:00', $this->autreClasse())->assertCreated();
    }

    public function test_la_gestion_force_le_conflit(): void
    {
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'));

        $this->creer($this->compte('coordinator'), '08:00', '10:00', $this->classe, ['forcer_conflit' => true])
            ->assertCreated();
        $this->assertSame(2, Seance::query()->count());
    }

    public function test_l_enseignant_ne_force_pas_403(): void
    {
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'));

        $this->creer($this->enseignant, '08:00', '10:00', $this->classe, ['forcer_conflit' => true])
            ->assertForbidden()
            ->assertJsonPath('reason', 'conflict_override_forbidden');
        $this->assertSame(1, Seance::query()->count());
    }

    public function test_une_fin_avant_le_debut_422(): void
    {
        $this->creer($this->enseignant, '10:00', '09:00', $this->classe)
            ->assertStatus(422)
            ->assertJsonValidationErrors('fin_seance');
    }

    public function test_deplacer_une_seance_sur_un_creneau_occupe_409(): void
    {
        $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'));
        $deplacee = $this->seanceLocale($this->enseignant, $this->creneau('14:00', '15:00'));

        $this->asTenant($this->enseignant)
            ->patchJson('/api/lms/seances/'.$deplacee->id, ['date_seance' => '2026-12-02T09:00:00+00:00'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'seance_schedule_conflict');
    }

    public function test_une_seance_ne_se_gene_pas_elle_meme(): void
    {
        $seance = $this->seanceLocale($this->enseignant, $this->creneau('08:00', '10:00'));

        $this->asTenant($this->enseignant)
            ->patchJson('/api/lms/seances/'.$seance->id, ['date_seance' => '2026-12-02T08:30:00+00:00', 'fin_seance' => '2026-12-02T10:30:00+00:00'])
            ->assertOk();
    }

    /**
     * École basculée : elle garde des séances miroirs, désignées par les identités KLASSCI
     * de l'enseignant et de la classe. Elles occupent le créneau comme les autres.
     */
    public function test_ecole_basculee_une_seance_miroir_du_meme_enseignant_est_un_conflit(): void
    {
        $this->enseignant->forceFill(['klassci_enseignant_id' => 4401])->save();
        $this->seanceLocale($this->compte('teacher'), $this->creneau('08:00', '10:00') + [
            'created_by' => null, 'klassci_enseignant_id' => 4401, 'classe_id' => null, 'klassci_classe_id' => 9901,
        ]);

        $this->creer($this->enseignant, '09:00', '10:00', $this->autreClasse())
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.motifs', ['enseignant']);
    }

    public function test_ecole_basculee_une_seance_miroir_de_la_meme_classe_est_un_conflit(): void
    {
        $miroir = Classe::factory()->for($this->ecole)->create(['klassci_id' => 5501]);
        $this->seanceLocale($this->compte('teacher'), $this->creneau('08:00', '10:00') + [
            'created_by' => null, 'klassci_enseignant_id' => 7777, 'classe_id' => null, 'klassci_classe_id' => 5501,
        ]);

        $this->creer($this->enseignant, '09:00', '10:00', $miroir)
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.motifs', ['classe']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<JsonResponse>
     */
    private function creer(User $auteur, string $debut, ?string $fin, Classe $classe, array $extra = []): TestResponse
    {
        return $this->asTenant($auteur)->postJson(self::CREER, $extra + [
            'titre' => 'Séance',
            'date_seance' => '2026-12-02T'.$debut.':00+00:00',
            'classe_id' => $classe->id,
        ] + ($fin !== null ? ['fin_seance' => '2026-12-02T'.$fin.':00+00:00'] : []));
    }

    /** @return array{date_seance: string, fin_seance: string} */
    private function creneau(string $debut, string $fin): array
    {
        return ['date_seance' => '2026-12-02 '.$debut.':00', 'fin_seance' => '2026-12-02 '.$fin.':00'];
    }

    private function autreClasse(): Classe
    {
        return Classe::factory()->for($this->ecole)->create(['klassci_id' => null]);
    }
}

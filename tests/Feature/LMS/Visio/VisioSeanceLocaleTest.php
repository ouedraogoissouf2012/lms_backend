<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeanceLocale;
use Tests\TestCase;

/**
 * La visio d'une séance d'école en mode autonome (ADR-EI-01, « même route, autre
 * source ») : son activation et l'admission de ses élèves.
 *
 * Une séance locale ne porte aucun identifiant KLASSCI : elle se désigne par son
 * id local, son auteur (`created_by`) et sa classe locale (`classe_id`). Rien ici
 * n'écrit sous un nom `klassci_*`.
 *
 * Vrai jeton porteur (`asTenant`) : la source est choisie par le mode de
 * l'établissement résolu, comme en production.
 */
final class VisioSeanceLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeanceLocale;
    use RefreshDatabase;

    private const KLASSCI = ['klassci_seance_id', 'klassci_enseignant_id', 'klassci_classe_id', 'klassci_matiere_id'];

    private const REFUS = [
        'success' => false,
        'message' => 'Vous ne pouvez pas gérer cette visio',
        'reason' => 'actor_not_authorized',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
        $this->ecoleAutonome();
    }

    // --- Activer -------------------------------------------------------------

    public function test_l_auteur_active_la_visio_de_sa_seance_locale_sans_jeton_ni_appel_klassci(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);

        $reponse = $this->asTenant($auteur)
            ->postJson("/api/lms/seances/{$seance->id}/activate-visio")
            ->assertOk();

        $ligne = self::ligne($seance);
        self::assertIsString($ligne['visio_room_id']);
        self::assertNotSame('', $ligne['visio_room_id']);
        $reponse->assertExactJson([
            'success' => true,
            'message' => 'Visioconférence activée',
            'data' => [
                'visio_enabled' => true,
                'visio_status' => 'programmee',
                'visio_room_id' => $ligne['visio_room_id'],
                'notifications_sent' => 0,
                'notifications_queued' => true,
                'classe_synced' => false,
            ],
        ]);

        self::assertTrue((bool) $ligne['visio_enabled']);
        self::assertSame('jitsi', $ligne['visio_type']);
        self::assertSame('programmee', $ligne['visio_status']);
        self::assertFalse((bool) $ligne['visio_active']);
        self::assertTrue((bool) $ligne['is_active']);
        self::assertSame($auteur->id, (int) $ligne['updated_by']);
        foreach (self::KLASSCI as $colonne) {
            self::assertNull($ligne[$colonne], "{$colonne} reste nul");
        }
        self::assertSame(1, DB::table('seances')->count(), 'aucune seconde ligne');
        Http::assertNothingSent();
    }

    public function test_l_activation_garde_le_salon_deja_attribue(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur, ['visio_room_id' => 'salon-deja-notifie']);

        $this->asTenant($auteur)
            ->postJson("/api/lms/seances/{$seance->id}/activate-visio")
            ->assertOk()
            ->assertJsonPath('data.visio_room_id', 'salon-deja-notifie');

        self::assertSame('salon-deja-notifie', self::ligne($seance)['visio_room_id']);
    }

    public function test_un_coordinateur_de_l_etablissement_active_la_visio(): void
    {
        $seance = $this->seanceLocale($this->compte('teacher'));

        $this->asTenant($this->compte('coordinator'))
            ->postJson("/api/lms/seances/{$seance->id}/activate-visio")
            ->assertOk()
            ->assertJsonPath('data.visio_status', 'programmee');
    }

    public function test_un_autre_enseignant_de_l_etablissement_recoit_le_refus_motive_sans_effet(): void
    {
        $seance = $this->seanceLocale($this->compte('teacher'));
        $avant = self::ligne($seance);

        $this->asTenant($this->compte('teacher'))
            ->postJson("/api/lms/seances/{$seance->id}/activate-visio")
            ->assertForbidden()
            ->assertExactJson(self::REFUS);

        self::assertSame($avant, self::ligne($seance));
        Queue::assertNothingPushed();
    }

    public function test_la_seance_d_un_autre_etablissement_est_introuvable(): void
    {
        $seance = $this->seanceLocale($this->compte('teacher'));
        $avant = self::ligne($seance);
        $ailleurs = $this->compte('coordinator', Institution::factory()->standalone()->create());

        $this->asTenant($ailleurs)
            ->postJson("/api/lms/seances/{$seance->id}/activate-visio")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Séance non trouvée']);

        self::assertSame($avant, self::ligne($seance));
        Queue::assertNothingPushed();
    }

    public function test_une_seance_inexistante_est_introuvable_sans_rien_ecrire(): void
    {
        $this->asTenant($this->compte('teacher'))
            ->postJson('/api/lms/seances/4242/activate-visio')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Séance non trouvée']);

        self::assertSame(0, DB::table('seances')->count());
        Http::assertNothingSent();
    }

    // --- Admettre ------------------------------------------------------------

    public function test_un_eleve_actif_de_la_classe_rejoint_la_visio_de_la_seance_locale(): void
    {
        $seance = $this->seanceLocaleActive($this->compte('teacher'));
        $eleve = $this->eleveDe($this->classe);

        $this->asTenant($eleve)
            ->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.visio_room_id', 'salon');

        self::assertTrue(DB::table('esbtp_attendance')->where('seance_id', $seance->id)->where('user_id', $eleve->id)->exists());
    }

    public function test_un_eleve_d_une_autre_classe_n_est_pas_admis(): void
    {
        $seance = $this->seanceLocaleActive($this->compte('teacher'));
        $eleve = $this->eleveDe(Classe::factory()->for($this->ecole)->create(['klassci_id' => null]));

        $this->asTenant($eleve)
            ->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_enrolled');
    }

    public function test_un_eleve_inactif_de_la_classe_n_est_pas_admis(): void
    {
        $seance = $this->seanceLocaleActive($this->compte('teacher'));
        $eleve = $this->eleveDe($this->classe, 'inactif');

        $this->asTenant($eleve)
            ->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_enrolled');
    }

    public function test_une_seance_sans_classe_garde_son_motif(): void
    {
        $seance = $this->seanceLocaleActive($this->compte('teacher'), ['classe_id' => null]);
        $eleve = $this->eleveDe($this->classe);

        $this->asTenant($eleve)
            ->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertForbidden()
            ->assertJsonPath('reason', 'no_classe_id');
    }

    public function test_la_classe_d_un_autre_etablissement_n_admet_personne(): void
    {
        $ailleurs = Institution::factory()->standalone()->create();
        $classeAilleurs = Classe::factory()->for($ailleurs)->create(['klassci_id' => null]);
        $seance = $this->seanceLocaleActive($this->compte('teacher'), ['classe_id' => $classeAilleurs->id]);
        $eleve = $this->eleveDe($this->classe);
        $classeAilleurs->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        $this->asTenant($eleve)
            ->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_enrolled');
    }

    // --- École KLASSCI : la source KLASSCI, la classe miroir d'abord --------

    public function test_une_ecole_klassci_garde_la_source_klassci_et_son_refus_sans_jeton(): void
    {
        $ecole = Institution::factory()->create();
        $enseignant = User::factory()->teacher()->for($ecole)->create(['klassci_token' => null]);
        $seance = Seance::factory()->for($ecole)->create(['created_by' => $enseignant->id]);

        $this->asTenant($enseignant)
            ->postJson("/api/lms/seances/{$seance->id}/activate-visio")
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
    }

    /**
     * École KLASSCI : une séance miroitée (`klassci_classe_id` seul) suit son chemin
     * d'avant, par sa classe miroir.
     *
     * Changement déclaré : une séance créée depuis le LMS dans une école KLASSCI, qui
     * porte sa classe locale (`classe_id`), admet désormais les élèves de cette classe.
     * La classe locale passe d'abord, comme dans l'accès aux enregistrements.
     */
    public function test_ecole_klassci_la_seance_miroitee_suit_sa_classe_miroir_la_seance_locale_sa_classe(): void
    {
        $ecole = Institution::factory()->create();
        $miroir = Classe::factory()->for($ecole)->create(['klassci_id' => 55]);
        $locale = Classe::factory()->for($ecole)->create(['klassci_id' => null]);
        $eleveDuMiroir = $this->eleveDe($miroir);
        $eleveDuLocal = $this->eleveDe($locale);
        $visio = ['visio_enabled' => true, 'visio_status' => 'active', 'visio_active' => true, 'visio_room_id' => 'salon'];

        $miroitee = Seance::factory()->for($ecole)->create(['klassci_classe_id' => 55, 'classe_id' => null] + $visio);
        $this->asTenant($eleveDuMiroir)->postJson("/api/lms/seances/{$miroitee->id}/join")->assertOk();
        $this->asTenant($eleveDuLocal)->postJson("/api/lms/seances/{$miroitee->id}/join")
            ->assertForbidden()->assertJsonPath('reason', 'not_enrolled');

        $creeDansLeLms = Seance::factory()->for($ecole)->create(['klassci_classe_id' => null, 'classe_id' => $locale->id] + $visio);
        $this->asTenant($eleveDuLocal)->postJson("/api/lms/seances/{$creeDansLeLms->id}/join")->assertOk();
        $this->asTenant($eleveDuMiroir)->postJson("/api/lms/seances/{$creeDansLeLms->id}/join")
            ->assertForbidden()->assertJsonPath('reason', 'not_enrolled');
    }
}

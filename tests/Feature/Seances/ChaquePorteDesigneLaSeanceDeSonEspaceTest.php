<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1566 (étape D de #1154, ADR-1154-01) : chaque chemin désigne la séance de l'ESPACE de l'école.
 * Il n'y a aucun ordre à choisir sur une collision : c'est ce qui remplace D439.
 *
 * Collision : la séance A a l'id local X, la séance B porte `klassci_seance_id` X.
 * - École KLASSCI : X désigne B. Les écrans y envoient l'id KLASSCI (mesuré sur le front `dev`).
 * - École autonome : X désigne A, l'id local.
 *
 * Chemins couverts : le masquage (`hide`, `unhide`), les présences de la visio
 * (`from-video-session`), la désactivation (`deactivate-visio`). La modification ne désigne
 * aucune ligne : elle rend le MÊME 403 dans les deux espaces.
 */
final class ChaquePorteDesigneLaSeanceDeSonEspaceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
    }

    /** @return array<string, array{string}> */
    public static function volets(): array
    {
        return ['ecole klassci' => ['klassci'], 'ecole autonome' => ['standalone']];
    }

    #[DataProvider('volets')]
    public function test_le_masquage_designe_la_seance_de_l_espace_de_l_ecole(string $volet): void
    {
        [$x, $cible, $autre] = $this->collision($volet);
        $eleve = $this->membre('etudiant');

        $this->asTenant($eleve)->postJson("/api/lms/seances/{$x}/hide")->assertOk();
        self::assertSame([1, 0], [$this->masquee($cible, $eleve), $this->masquee($autre, $eleve)]);

        SeanceUserHidden::hide($autre->id, $eleve->id);
        $this->asTenant($eleve)->postJson("/api/lms/seances/{$x}/unhide")->assertOk();
        self::assertSame([0, 1], [$this->masquee($cible, $eleve), $this->masquee($autre, $eleve)]);
    }

    /** L'enseignant ne gère que la cible : l'autre rendrait `actor_not_authorized`. */
    #[DataProvider('volets')]
    public function test_les_presences_designent_la_seance_de_l_espace_de_l_ecole(string $volet): void
    {
        $enseignant = $this->membre('enseignant');
        [$x, $cible] = $this->collision($volet);
        $cible->forceFill(['created_by' => $enseignant->id])->save();

        $this->presences($enseignant, $x)->assertStatus(403)->assertJsonPath('reason', 'not_enrolled');
    }

    #[DataProvider('volets')]
    public function test_la_desactivation_designe_la_seance_de_l_espace_de_l_ecole(string $volet): void
    {
        [$x, $cible, $autre] = $this->collision($volet);

        $this->asTenant($this->membre('coordinateur'))->postJson("/api/lms/seances/{$x}/deactivate-visio")->assertOk();

        self::assertFalse((bool) self::ligne($cible)['visio_enabled'], 'La séance de l\'espace de l\'école.');
        self::assertTrue((bool) self::ligne($autre)['visio_enabled'], 'L\'autre séance est intacte.');
    }

    /**
     * Un id de l'AUTRE espace seul ne désigne rien : l'id local d'un miroir en école KLASSCI, le
     * `klassci_seance_id` d'un miroir resté dans une école passée au mode autonome.
     */
    #[DataProvider('volets')]
    public function test_un_id_de_l_autre_espace_seul_ne_designe_rien(string $volet): void
    {
        $this->ecole($volet);
        $miroir = $this->seance((int) DB::table('seances')->max('id') + 1000);
        $enseignant = $this->membre('enseignant');
        $miroir->forceFill(['created_by' => $enseignant->id])->save();
        $x = $volet === 'klassci' ? $miroir->id : (int) $miroir->klassci_seance_id;
        $avant = self::ligne($miroir);

        $this->asTenant($this->membre('etudiant'))->postJson("/api/lms/seances/{$x}/hide")->assertNotFound();
        $this->presences($enseignant, $x)->assertNotFound();
        $this->asTenant($this->membre('coordinateur'))->postJson("/api/lms/seances/{$x}/deactivate-visio")->assertNotFound();

        self::assertSame(0, DB::table('seance_user_hidden')->where('seance_id', $miroir->id)->count());
        self::assertSame($avant, self::ligne($miroir));
    }

    /**
     * La modification ne désigne aucune ligne (ModifierUneSeanceTest, décision du 17/11) : sur
     * une collision, les deux espaces rendent le même 403 ; un id d'aucune séance, 404.
     */
    public function test_ecole_klassci_la_modification_rend_le_meme_refus_dans_les_deux_espaces(): void
    {
        [$x, $b, $a] = $this->collision('klassci');
        $coordinateur = $this->membre('coordinateur');

        foreach ([$x, (int) $a->klassci_seance_id, $b->id] as $id) {
            $this->asTenant($coordinateur)->putJson("/api/lms/seances/{$id}", ['titre' => 'Dans le LMS'])
                ->assertForbidden()->assertJsonPath('reason', 'seance_managed_by_klassci');
        }
        $this->asTenant($coordinateur)->putJson('/api/lms/seances/'.((int) DB::table('seances')->max('id') + 5000), ['titre' => 'Rien'])
            ->assertNotFound();
    }

    /**
     * A (id local X) et B (`klassci_seance_id` X), dans une école neuve du volet.
     *
     * @return array{int, Seance, Seance} [X, la séance de l'espace de l'école, l'autre]
     */
    private function collision(string $volet): array
    {
        $this->ecole($volet);
        $a = $this->seance(null);
        $b = $this->seance($a->id);
        if ($volet === 'klassci') {
            // A est lui-même un miroir, d'un numéro KLASSCI qu'aucune séance n'a pour id local.
            $a->forceFill(['klassci_seance_id' => (int) DB::table('seances')->max('id') + 1000])->save();
        }

        return $volet === 'klassci' ? [$a->id, $b, $a] : [$a->id, $a, $b];
    }

    private function ecole(string $volet): void
    {
        $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    private function membre(string $role): User
    {
        return User::factory()->for($this->ecole)->create(['role' => $role, 'klassci_token' => null, 'klassci_id' => null]);
    }

    /** Une séance de l'école, visio programmée, dont l'enseignant n'est pas un compte du test. */
    private function seance(?int $klassciSeanceId): Seance
    {
        return Seance::factory()->for($this->ecole)->withVisio()->create([
            'created_by' => null,
            'klassci_seance_id' => $klassciSeanceId,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    private function presences(User $enseignant, int $id): TestResponse
    {
        return $this->asTenant($enseignant)->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => $id,
            'date' => now()->toDateString(),
            'participants' => [['etudiant_id' => 999999, 'statut' => 'present']],
        ]);
    }

    private function masquee(Seance $seance, User $eleve): int
    {
        return DB::table('seance_user_hidden')->where('seance_id', $seance->id)->where('user_id', $eleve->id)->count();
    }

    /** @return array<string, mixed> */
    private static function ligne(Seance $seance): array
    {
        return (array) DB::table('seances')->where('id', $seance->id)->first();
    }
}

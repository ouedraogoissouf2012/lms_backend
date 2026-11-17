<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #933 — la séance que désignent la suppression (`DELETE /lms/seances/{id}`) et
 * la désactivation (`POST /lms/seances/{id}/deactivate-visio`) suit l'espace
 * d'identifiants de l'école, choisi par son mode déclaré (ADR-760-01, EI-02).
 *
 * - École autonome : l'id LOCAL seulement. Une école passée de KLASSCI au mode
 *   autonome garde des `klassci_seance_id` ; un id sans séance locale ne désigne
 *   jamais, par ce second identifiant, une AUTRE séance.
 * - École KLASSCI : deux appelants, deux espaces (le calendrier de gestion envoie
 *   l'id KLASSCI, l'historique l'id local). Les deux passent ; la collision entre
 *   les deux espaces est la dette #1154.
 *
 * Une séance inconnue garde ses contrats : 403 à la suppression
 * (`DeleteSeanceRequestTest`), 404 à la désactivation. Aucun id codé en dur :
 * l'identifiant KLASSCI est pris au-delà des ids locaux créés.
 *
 * Vrai jeton porteur (`asTenant`) : la source est choisie par le mode de
 * l'établissement résolu, comme en production.
 */
final class SeanceDesigneeParSonEspaceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
    }

    // ───────────────────────── école autonome : l'id local seulement

    public function test_dans_une_ecole_autonome_un_id_sans_seance_locale_ne_supprime_pas_la_seance_qui_le_porte(): void
    {
        $this->ecole('standalone');
        $miroir = $this->seanceMiroir();
        $avant = self::ligne($miroir);

        $this->asTenant($this->coordinateur())
            ->deleteJson("/api/lms/seances/{$miroir->klassci_seance_id}")
            ->assertForbidden();

        self::assertSame($avant, self::ligne($miroir));
    }

    public function test_dans_une_ecole_autonome_un_id_sans_seance_locale_ne_desactive_pas_la_visio_de_la_seance_qui_le_porte(): void
    {
        $this->ecole('standalone');
        $miroir = $this->seanceMiroir();
        $avant = self::ligne($miroir);

        $this->asTenant($this->coordinateur())
            ->postJson("/api/lms/seances/{$miroir->klassci_seance_id}/deactivate-visio")
            ->assertNotFound();

        self::assertSame($avant, self::ligne($miroir));
    }

    // ───────────────────────── les deux volets : l'id local

    #[DataProvider('volets')]
    public function test_la_seance_designee_par_son_id_local_est_supprimee(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seance();

        $this->asTenant($this->coordinateur())
            ->deleteJson("/api/lms/seances/{$seance->id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Séance supprimée avec succès']);

        self::assertSoftDeleted($seance);
    }

    #[DataProvider('volets')]
    public function test_la_visio_de_la_seance_designee_par_son_id_local_est_desactivee(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seance();

        $this->asTenant($this->coordinateur())
            ->postJson("/api/lms/seances/{$seance->id}/deactivate-visio")
            ->assertOk()
            ->assertJsonPath('data.visio_enabled', false);

        self::assertFalse((bool) self::ligne($seance)['visio_enabled']);
    }

    // ───────────────────────── école KLASSCI : l'id du calendrier

    public function test_dans_une_ecole_klassci_le_calendrier_supprime_par_l_id_klassci(): void
    {
        $this->ecole('klassci');
        $seance = $this->seanceMiroir();

        $this->asTenant($this->coordinateur())
            ->deleteJson("/api/lms/seances/{$seance->klassci_seance_id}")
            ->assertOk();

        self::assertSoftDeleted($seance);
    }

    public function test_dans_une_ecole_klassci_la_visio_se_desactive_par_l_id_klassci(): void
    {
        $this->ecole('klassci');
        $seance = $this->seanceMiroir();

        $this->asTenant($this->coordinateur())
            ->postJson("/api/lms/seances/{$seance->klassci_seance_id}/deactivate-visio")
            ->assertOk();

        self::assertFalse((bool) self::ligne($seance)['visio_enabled']);
    }

    // ───────────────────────── les deux volets : l'autre école

    #[DataProvider('volets')]
    public function test_la_seance_d_une_autre_ecole_n_est_ni_supprimee_ni_desactivee(string $volet): void
    {
        $this->ecole($volet);
        $coordinateur = $this->coordinateur();
        $this->ecole($volet);
        $ailleurs = $this->seanceMiroir();
        $avant = self::ligne($ailleurs);

        foreach (array_unique([$ailleurs->id, $ailleurs->klassci_seance_id]) as $identifiant) {
            $this->asTenant($coordinateur)->deleteJson("/api/lms/seances/{$identifiant}")->assertForbidden();
            $this->asTenant($coordinateur)->postJson("/api/lms/seances/{$identifiant}/deactivate-visio")->assertNotFound();
        }

        self::assertSame($avant, self::ligne($ailleurs));
    }

    private function ecole(string $volet): void
    {
        $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    private function coordinateur(): User
    {
        return User::factory()->coordinator()->for($this->ecole)->create(['klassci_token' => null]);
    }

    /** Une séance de l'école, visio programmée, sans identité KLASSCI. */
    private function seance(): Seance
    {
        return Seance::factory()->for($this->ecole)->withVisio()->create([
            'created_by' => User::factory()->teacher()->for($this->ecole)->create(['klassci_token' => null])->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    /**
     * Une séance qui porte un `klassci_seance_id` qu'aucune séance n'a pour id
     * local : celle d'une école KLASSCI, ou celle qu'une école passée au mode
     * autonome a gardée.
     */
    private function seanceMiroir(): Seance
    {
        $seance = $this->seance();
        $seance->forceFill(['klassci_seance_id' => (int) DB::table('seances')->max('id') + 1000])->save();

        return $seance;
    }

    /** @return array<string, mixed> */
    private static function ligne(Seance $seance): array
    {
        return (array) DB::table('seances')->where('id', $seance->id)->first();
    }
}

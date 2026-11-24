<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1154, étape C (ADR-760-01) : un id porte un seul sens par porte.
 *
 * - `DELETE /api/lms/seances/local/{id}` : l'id LOCAL, dans les deux types d'école ;
 * - `DELETE /api/lms/seances/{id}`, la porte historique : dans une école KLASSCI, l'id KLASSCI
 *   seulement, l'espace du calendrier de gestion, son seul appelant. Dans une école autonome,
 *   l'id local, son seul espace.
 *
 * Avant, la porte historique essayait l'espace local d'abord : sur une collision des deux
 * numérotations, l'id KLASSCI du calendrier supprimait la séance locale qui porte le même nombre.
 */
final class SuppressionStricteEnEcoleKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_ecole_klassci_la_porte_historique_supprime_la_seance_de_l_id_klassci(): void
    {
        $ecole = Institution::factory()->create();
        [$locale, $miroir] = $this->collision($ecole);

        $this->asTenant($this->coordinateur($ecole))
            ->deleteJson("/api/lms/seances/{$locale->id}")
            ->assertOk();

        self::assertSoftDeleted($miroir);
        self::assertNotSoftDeleted($locale);
    }

    /** Verrou : la porte locale désigne par l'id local, même nombre, autre séance. */
    public function test_ecole_klassci_la_porte_locale_supprime_la_seance_de_l_id_local(): void
    {
        $ecole = Institution::factory()->create();
        [$locale, $miroir] = $this->collision($ecole);

        $this->asTenant($this->coordinateur($ecole))
            ->deleteJson("/api/lms/seances/local/{$locale->id}")
            ->assertOk();

        self::assertSoftDeleted($locale);
        self::assertNotSoftDeleted($miroir);
    }

    /** Sans collision aussi : la porte historique ne lit plus l'espace local en école KLASSCI. */
    public function test_ecole_klassci_un_id_local_ne_designe_rien_par_la_porte_historique(): void
    {
        $ecole = Institution::factory()->create();
        $locale = $this->seance($ecole, null);

        $this->asTenant($this->coordinateur($ecole))
            ->deleteJson("/api/lms/seances/{$locale->id}")
            ->assertForbidden();

        self::assertNotSoftDeleted($locale);
    }

    /** Verrou : en école autonome, la porte historique parle l'espace local, son seul espace. */
    public function test_ecole_autonome_la_porte_historique_supprime_par_l_id_local(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $locale = $this->seance($ecole, null);

        $this->asTenant($this->coordinateur($ecole))
            ->deleteJson("/api/lms/seances/{$locale->id}")
            ->assertOk();

        self::assertSoftDeleted($locale);
    }

    /** Verrou : la séance d'une autre école n'est désignée par aucune porte. */
    public function test_la_seance_d_une_autre_ecole_n_est_designee_par_aucune_porte(): void
    {
        $ecole = Institution::factory()->create();
        $ailleurs = $this->seance(Institution::factory()->create(), null);
        $miroirAilleurs = $this->seance(Institution::factory()->create(), (int) $ailleurs->id + 5000);
        $coordinateur = $this->coordinateur($ecole);

        $this->asTenant($coordinateur)->deleteJson("/api/lms/seances/local/{$ailleurs->id}")->assertForbidden();
        $this->asTenant($coordinateur)->deleteJson("/api/lms/seances/{$miroirAilleurs->klassci_seance_id}")->assertForbidden();

        self::assertNotSoftDeleted($ailleurs);
        self::assertNotSoftDeleted($miroirAilleurs);
    }

    /**
     * Deux séances de la même école : la première a l'id local X, la seconde porte X comme
     * id KLASSCI.
     *
     * @return array{0: Seance, 1: Seance}
     */
    private function collision(Institution $ecole): array
    {
        $locale = $this->seance($ecole, null);

        return [$locale, $this->seance($ecole, (int) $locale->id)];
    }

    private function seance(Institution $ecole, ?int $klassciSeanceId): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'created_by' => User::factory()->teacher()->for($ecole)->create(['klassci_token' => null])->id,
            'klassci_seance_id' => $klassciSeanceId,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'visio_active' => false,
        ]);
    }

    private function coordinateur(Institution $ecole): User
    {
        return User::factory()->coordinator()->for($ecole)->create(['klassci_token' => null]);
    }
}

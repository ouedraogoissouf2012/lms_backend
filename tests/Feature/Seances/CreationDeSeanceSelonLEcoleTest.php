<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1182 — décision du user du 17/11 : « Pour LMS KLASSCI, la procédure reste
 * telle : on crée la séance depuis KLASSCI, […] et elle est juste récupérée par le
 * LMS, pas plus pas moins. »
 *
 * - École KLASSCI : `POST /lms/seances` est refusé, 403 `seance_managed_by_klassci`
 *   (le motif de la modification, #1121), et rien n'est écrit.
 * - École autonome : la création ne change pas.
 *
 * Vrai jeton. En production, aucune séance ni matière locale n'existe dans une
 * école KLASSCI (mesure du 17/11) : le refus ne laisse aucune donnée orpheline.
 */
final class CreationDeSeanceSelonLEcoleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const CHARGE = ['titre' => 'Révision du chapitre 3', 'date_seance' => '2026-12-01T09:00:00+00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{0: string}> */
    public static function personnel(): array
    {
        return ['enseignant' => ['enseignant'], 'coordinateur' => ['coordinateur'], 'admin d\'école' => ['superAdmin']];
    }

    #[DataProvider('personnel')]
    public function test_une_ecole_klassci_ne_cree_pas_de_seance_dans_le_lms(string $role): void
    {
        $compte = $this->compte(InstitutionMode::Klassci, $role);

        $this->asTenant($compte)->postJson('/api/lms/seances', self::CHARGE)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'seance_managed_by_klassci');

        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count(), 'Rien n\'est écrit.');
    }

    /** Verrou : l'école autonome crée, comme avant. */
    #[DataProvider('personnel')]
    public function test_une_ecole_autonome_cree_sa_seance(string $role): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, $role);

        $this->asTenant($compte)->postJson('/api/lms/seances', self::CHARGE)->assertCreated();

        self::assertSame(1, Seance::query()->withoutGlobalScopes()->where('created_by', $compte->id)->count());
    }

    private function compte(InstitutionMode $mode, string $role): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return User::factory()->for($ecole)->create(['role' => $role, 'last_klassci_sync' => now()]);
    }
}

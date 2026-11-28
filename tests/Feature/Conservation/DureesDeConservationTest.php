<?php

declare(strict_types=1);

namespace Tests\Feature\Conservation;

use App\Models\Institution;
use App\Models\User;
use App\Services\Retention\RetentionRegistry;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * #1705, PR 1 : les durées de conservation des données (loi 2013-450), UN tableau (`config/conservation.php`).
 *
 * - Chaque usage y figure, avec sa durée, son point de départ, et la politique qui l'applique. S'il n'en a pas, il suit
 *   le compte (copies, notes, présences partent avec sa purge).
 * - Chaque politique du registre rend la durée du tableau : une durée ne peut plus diverger en silence.
 * - La note de conformité (`docs/conformite-loi-2013-450.md`) reprend chaque usage et sa durée.
 * - La purge des comptes supprimés est PLANIFIÉE (30 jours, `comptes:purge --apply`). Celle d'une école reste MANUELLE
 *   (`purge:run institutions`), par décision.
 */
final class DureesDeConservationTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'docs/conformite-loi-2013-450.md';

    public function test_chaque_politique_du_registre_rend_la_duree_du_tableau(): void
    {
        $registre = app(RetentionRegistry::class);
        $parPolitique = [];
        foreach ($this->usages() as $usage) {
            if (is_string($usage['politique'] ?? null)) {
                $parPolitique[$usage['politique']] = $usage;
            }
        }

        foreach ($registre->keys() as $cle) {
            self::assertArrayHasKey($cle, $parPolitique, "La politique « {$cle} » n'a pas de ligne au tableau des durées.");
            $politique = $registre->get($cle);
            self::assertNotNull($politique);
            self::assertSame((int) config($parPolitique[$cle]['cle']), $politique->defaultGraceDays(), $cle);
        }
    }

    public function test_chaque_usage_a_sa_politique_ou_suit_le_compte(): void
    {
        $cles = app(RetentionRegistry::class)->keys();

        foreach ($this->usages() as $nom => $usage) {
            self::assertIsString($usage['libelle'] ?? null, $nom);
            self::assertIsString($usage['depuis'] ?? null, $nom);
            if (($usage['suit_le_compte'] ?? false) === true) {
                continue;
            }
            self::assertContains($usage['politique'] ?? null, $cles, "« {$nom} » désigne une politique inconnue.");
            self::assertGreaterThan(0, (int) config($usage['cle']), "« {$nom} » n'a pas de durée positive.");
        }
    }

    public function test_la_duree_des_comptes_supprimes_a_une_seule_source(): void
    {
        config(['conservation.comptes_supprimes_jours' => 45, 'conservation.ecoles_supprimees_jours' => 60]);
        $registre = app(RetentionRegistry::class);

        self::assertSame(45, $registre->get('users')?->defaultGraceDays());
        self::assertSame(60, $registre->get('institutions')?->defaultGraceDays());
    }

    public function test_la_note_de_conformite_reprend_chaque_usage_et_sa_duree(): void
    {
        $note = (string) file_get_contents(base_path(self::NOTE));

        foreach ($this->usages() as $nom => $usage) {
            self::assertStringContainsString((string) $usage['libelle'], $note, "La note ne dit rien de « {$nom} ».");
            if (($usage['suit_le_compte'] ?? false) !== true) {
                self::assertStringContainsString((int) config($usage['cle']).' jours', $note, "La note ne donne pas la durée de « {$nom} ».");
            }
        }
        self::assertStringContainsString('purge:run institutions', $note, 'La note doit dire que la purge d\'une école reste manuelle.');
    }

    public function test_les_comptes_supprimes_sont_purges_apres_trente_jours(): void
    {
        $ecole = Institution::factory()->create();
        $ancien = User::factory()->create(['institution_id' => $ecole->id]);
        $recent = User::factory()->create(['institution_id' => $ecole->id]);
        $vivant = User::factory()->create(['institution_id' => $ecole->id]);
        $ancien->delete();
        $recent->delete();
        User::query()->withoutGlobalScopes()->withTrashed()->whereKey($ancien->id)->update(['deleted_at' => now()->subDays(31)]);
        User::query()->withoutGlobalScopes()->withTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(29)]);

        self::assertSame(0, Artisan::call('comptes:purge', ['--apply' => true]));

        $restants = User::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', [$ancien->id, $recent->id, $vivant->id])->pluck('id')->sort()->values()->all();
        self::assertSame([$recent->id, $vivant->id], $restants);
    }

    public function test_la_purge_des_comptes_est_planifiee_et_celle_des_ecoles_ne_l_est_pas(): void
    {
        $commandes = array_map(static fn (Event $e): string => (string) $e->command, app(Schedule::class)->events());

        self::assertNotEmpty(array_filter($commandes, static fn (string $c): bool => str_contains($c, 'comptes:purge --apply')));
        self::assertEmpty(array_filter($commandes, static fn (string $c): bool => str_contains($c, 'purge:run')));
    }

    /** @return array<string, array<string, mixed>> */
    private function usages(): array
    {
        $usages = config('conservation.usages');
        self::assertIsArray($usages, 'config/conservation.php doit porter le tableau `usages`.');

        return $usages;
    }
}

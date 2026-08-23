<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances\Cursor;

use App\Models\SeanceSyncCursor;
use App\Services\Seances\Sync\Cursor\EloquentSeanceSyncCursorStore;
use App\Services\Seances\Sync\Cursor\SeanceSyncPosition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EloquentSeanceSyncCursorStore::class)]
final class EloquentSeanceSyncCursorStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_load_without_any_row_returns_a_fresh_start_of_cycle(): void
    {
        $position = (new EloquentSeanceSyncCursorStore)->load();

        self::assertTrue($position->isAtStart());
        self::assertSame([], $position->taintedInstitutionIds);
        // `load()` ne persiste rien : la ligne n'apparaît qu'au premier `save()`.
        self::assertDatabaseCount('seance_sync_cursors', 0);
    }

    public function test_saved_position_is_restored_identically(): void
    {
        $store = new EloquentSeanceSyncCursorStore;
        $cycleStartedAt = CarbonImmutable::now()->subMinutes(12);

        $store->save(new SeanceSyncPosition(7, 42, $cycleStartedAt, [7, 9]));
        $reloaded = $store->load();

        self::assertSame(7, $reloaded->lastInstitutionId);
        self::assertSame(42, $reloaded->lastUserId);
        self::assertSame([7, 9], $reloaded->taintedInstitutionIds);
        self::assertSame(
            $cycleStartedAt->startOfSecond()->toDateTimeString(),
            $reloaded->cycleStartedAt->toDateTimeString(),
        );
    }

    /**
     * L'unique sur `name` garantit qu'un second enregistrement met à jour la
     * position au lieu d'en créer une deuxième — un curseur dupliqué ferait
     * repartir la sync de deux endroits à la fois.
     */
    public function test_saving_twice_keeps_a_single_cursor_row(): void
    {
        $store = new EloquentSeanceSyncCursorStore;

        $store->save(new SeanceSyncPosition(1, 1, CarbonImmutable::now()));
        $store->save(new SeanceSyncPosition(2, 5, CarbonImmutable::now()));

        self::assertDatabaseCount('seance_sync_cursors', 1);
        self::assertSame(2, $store->load()->lastInstitutionId);
        self::assertSame(5, $store->load()->lastUserId);
    }

    public function test_reset_opens_a_new_cycle_from_the_start_without_taints(): void
    {
        $store = new EloquentSeanceSyncCursorStore;
        $store->save(new SeanceSyncPosition(7, 42, CarbonImmutable::now()->subHour(), [7]));

        $store->reset();
        $position = $store->load();

        self::assertTrue($position->isAtStart());
        self::assertSame([], $position->taintedInstitutionIds);
        self::assertTrue(
            $position->cycleStartedAt->greaterThan(CarbonImmutable::now()->subMinute()),
            'Un cycle remis à zéro doit être daté de maintenant, sinon son balayage archiverait sur une référence périmée.',
        );
    }

    /**
     * La colonne JSON peut être éditée à la main en exploitation : une valeur
     * illisible ne doit pas faire échouer la passe suivante.
     */
    public function test_malformed_taints_are_ignored_rather_than_breaking_the_pass(): void
    {
        SeanceSyncCursor::query()->create([
            'name' => SeanceSyncCursor::KLASSCI_SEANCES,
            'last_institution_id' => 1,
            'last_user_id' => 2,
            'cycle_started_at' => CarbonImmutable::now(),
            'tainted_institution_ids' => ['3', 'not-an-id', null, 4],
        ]);

        self::assertSame([3, 4], (new EloquentSeanceSyncCursorStore)->load()->taintedInstitutionIds);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #579 — la migration de rattrapage rend visibles les notifications déjà
 * écrites sans `institution_id`.
 *
 * Le correctif de `NotificationDispatcher` protège l'avenir ; les lignes déjà
 * en base resteraient invisibles pour toujours sans cette réparation.
 *
 * @see database/migrations/2026_08_24_000001_backfill_notifications_institution_id.php
 */
final class BackfillNotificationInstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_repairs_orphan_rows_and_leaves_supradmin_ones(): void
    {
        $institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($institution);

        $student = User::factory()->student()->create(['institution_id' => $institution->id]);
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        // Lignes telles que le bug les écrivait : institution_id à NULL.
        $orphan = $this->insertOrphanFor($student);
        $legitimate = $this->insertOrphanFor($supradmin);

        $this->runBackfill();

        self::assertSame(
            $institution->id,
            $this->institutionOf($orphan),
            "La notification de l'étudiant n'a pas été réparée."
        );
        self::assertNull(
            $this->institutionOf($legitimate),
            'La notification du supradmin ne devait pas être touchée.'
        );
    }

    /** Rejouer la migration ne doit rien changer. */
    public function test_backfill_is_idempotent(): void
    {
        $institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($institution);
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);

        $orphan = $this->insertOrphanFor($student);

        $this->runBackfill();
        $this->runBackfill();

        self::assertSame($institution->id, $this->institutionOf($orphan));
        self::assertSame(1, DB::table('notifications')->count());
    }

    /**
     * Après réparation, le destinataire voit enfin sa notification à travers
     * le scope global — c'est le seul critère qui compte.
     */
    public function test_repaired_notification_becomes_visible_to_its_recipient(): void
    {
        $institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($institution);
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);

        $this->insertOrphanFor($student);

        self::assertSame(0, Notification::where('user_id', $student->id)->count());

        $this->runBackfill();

        self::assertSame(1, Notification::where('user_id', $student->id)->count());
    }

    /**
     * Les notifications `evaluation_approaching` ne sont PAS réparées.
     *
     * `NotifyUpcomingEvaluations` écrit un id KLASSCI dans `user_id`, pas un
     * `users.id`. Leur donner l'institution du porteur local qui partage cet
     * identifiant les rendrait visibles à quelqu'un d'autre — potentiellement
     * dans une autre institution. Réparer la visibilité d'une ligne mal
     * adressée en ferait une fuite.
     */
    public function test_backfill_leaves_misaddressed_evaluation_notifications_inert(): void
    {
        $institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($institution);

        // Un utilisateur local dont l'id COLLISIONNE avec un id KLASSCI écrit
        // par la commande d'évaluations.
        $collidingUser = User::factory()->student()->create(['institution_id' => $institution->id]);

        $misaddressed = (int) DB::table('notifications')->insertGetId([
            'user_id' => $collidingUser->id,
            'type' => 'evaluation_approaching',
            'title' => 'Évaluation approchante',
            'message' => "L'évaluation \"Partiel de mathématiques\" aura lieu le 30/08/2026",
            'data' => json_encode(['evaluation_id' => 42]),
            'institution_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        self::assertNull(
            $this->institutionOf($misaddressed),
            'Une notification mal adressée a été rendue visible à un tiers.'
        );
        self::assertSame(0, Notification::where('user_id', $collidingUser->id)->count());
    }

    /** Insère une ligne telle que le bug la produisait, sans passer par Eloquent. */
    private function insertOrphanFor(User $user): int
    {
        return (int) DB::table('notifications')->insertGetId([
            'user_id' => $user->id,
            'type' => Notification::TYPE_VISIO_STARTING,
            'title' => 'Visio',
            'message' => 'Votre visioconférence démarre.',
            'data' => json_encode([]),
            'institution_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function institutionOf(int $notificationId): ?int
    {
        $value = DB::table('notifications')->where('id', $notificationId)->value('institution_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Rejoue la migration sur les données du test.
     *
     * `RefreshDatabase` l'a déjà exécutée à la création du schéma, sur une
     * table vide : c'est ici qu'elle rencontre de vraies lignes orphelines.
     */
    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_08_24_000001_backfill_notifications_institution_id.php'
        );

        $migration->up();
    }
}

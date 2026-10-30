<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `POST /api/lms/notifications/send-session-reminder` n'existe plus.
 *
 * Bouchon depuis sa naissance (`ac614e1b`, 2025-10-22) : la route répondait
 * `200 « Rappels acceptés »` sans rien envoyer, n'avait aucun contrôle de rôle, et
 * n'était appelée par aucun écran. L'issue de suivi promise par #111 puis #151 n'a
 * jamais été ouverte. Le besoin réel — prévenir les élèves d'une séance — est servi
 * par `DispatchVisioNotification`, à l'activation et à la bascule de la visio.
 *
 * Ce test fige le retrait : la recréer sans fonctionnalité derrière rougirait ici.
 * Les deux volets : la route est absente pour tout compte. Vrai jeton porteur.
 */
final class RappelSeanceRetireTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_la_route_n_est_plus_declaree(): void
    {
        self::assertFalse(Route::has('lms.notifications.send-session-reminder'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_un_compte_authentifie_recoit_404(string $volet): void
    {
        $this->disableKlassciMiddleware();
        $ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
        $coordinateur = User::factory()->coordinator()->for($ecole)->create();

        $this->asTenant($coordinateur)
            ->postJson('/api/lms/notifications/send-session-reminder', [
                'seance_cours_id' => 42,
                'channels' => ['app'],
            ])
            ->assertNotFound();
    }
}

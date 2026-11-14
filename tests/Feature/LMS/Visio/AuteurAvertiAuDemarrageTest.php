<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Jobs\DispatchVisioNotification;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use App\Services\Notification\VisioNotificationDispatcher;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le démarrage d'une séance locale avertit son AUTEUR, comme celui d'une séance
 * KLASSCI avertit son enseignant (`klassci_enseignant_id`).
 *
 * L'auteur est `created_by` de la séance désignée par son id local, cherché dans
 * l'établissement de la séance, et seulement s'il est enseignant.
 */
final class AuteurAvertiAuDemarrageTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        $this->ecole = Institution::factory()->standalone()->create();
    }

    public function test_le_demarrage_d_une_seance_locale_avertit_son_auteur(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceDe($auteur);

        app(VisioNotificationDispatcher::class)->notifyVisioStarting($seance->id, self::charge($seance));

        self::assertSame([$auteur->id], $this->avertis());
        self::assertSame('Votre visioconférence a démarré', Notification::withoutGlobalScope('institution')->sole()->title);
    }

    public function test_un_auteur_d_un_autre_etablissement_n_est_pas_averti(): void
    {
        $voisin = User::factory()->teacher()->for(Institution::factory()->standalone()->create())->create();
        $seance = $this->seanceDe($voisin);

        app(VisioNotificationDispatcher::class)->notifyVisioStarting($seance->id, self::charge($seance));

        self::assertSame([], $this->avertis());
    }

    public function test_un_auteur_qui_n_est_pas_enseignant_n_est_pas_averti(): void
    {
        $seance = $this->seanceDe($this->compte('coordinator'));

        app(VisioNotificationDispatcher::class)->notifyVisioStarting($seance->id, self::charge($seance));

        self::assertSame([], $this->avertis());
    }

    /** Par la route réelle : le démarrage, puis le job exécuté comme le worker. */
    public function test_le_demarrage_par_la_route_avertit_l_auteur(): void
    {
        Queue::fake();
        $auteur = $this->compte('teacher');
        $seance = $this->seanceDe($auteur, ['visio_enabled' => true, 'visio_status' => 'programmee', 'visio_room_id' => 'salon']);

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/start-visio")->assertOk();
        $this->app->forgetScopedInstances();
        foreach (Queue::pushed(DispatchVisioNotification::class) as $job) {
            $job->handle(app(NotificationService::class), app(LoggerInterface::class));
        }

        self::assertSame([$auteur->id], $this->avertis());
    }

    private function compte(string $role): User
    {
        return User::factory()->{$role}()->for($this->ecole)->create(['klassci_token' => null]);
    }

    /**
     * Une séance locale de l'établissement, sans classe : seul l'auteur est en jeu.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function seanceDe(User $auteur, array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->create($attributs + [
            'created_by' => $auteur->id,
            'classe_id' => null,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    /**
     * La charge EXACTE du démarrage (`VisioSessionService`), sans `institution_id`.
     *
     * @return array<string, mixed>
     */
    private static function charge(Seance $seance): array
    {
        return [
            'seance_locale_id' => $seance->id,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'matiere_nom' => 'Anglais',
            'enseignant_nom' => 'Enseignant',
        ];
    }

    /** @return list<int> */
    private function avertis(): array
    {
        /** @var list<int> $ids */
        $ids = Notification::withoutGlobalScope('institution')->where('type', Notification::TYPE_VISIO_STARTING)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        return $ids;
    }
}

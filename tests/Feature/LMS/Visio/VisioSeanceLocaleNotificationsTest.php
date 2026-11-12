<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Jobs\DispatchVisioNotification;
use App\Models\Classe;
use App\Models\Notification;
use App\Services\Notification\VisioNotificationDispatcher;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeanceLocale;
use Tests\TestCase;

/**
 * Les élèves d'une séance d'école en mode autonome sont avertis de sa visio :
 * programmée (activation) et démarrée. Décision de l'utilisateur : « élèves avertis
 * des publications ».
 *
 * L'audience est celle de l'admission (`ClasseAudienceSource`) : les élèves actifs
 * de la classe locale de la séance, et eux seuls. La séance est désignée par son id
 * local, jamais deviné depuis l'identifiant reçu par la route.
 */
final class VisioSeanceLocaleNotificationsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeanceLocale;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
        $this->ecoleAutonome();
    }

    public function test_l_activation_avertit_les_eleves_actifs_de_la_classe_et_eux_seuls(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);
        $inscrit = $this->eleveDe($this->classe);
        $this->eleveDe($this->classe, 'inactif');
        $this->eleveDe(Classe::factory()->for($this->ecole)->create(['klassci_id' => null]));

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/activate-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame([$inscrit->id], $this->destinataires(Notification::TYPE_VISIO_SCHEDULED));
        self::assertSame(
            $seance->id,
            (int) json_decode((string) Notification::query()->value('data'), true)['seance_id'],
            'la notification désigne la séance par son id local',
        );
    }

    public function test_le_demarrage_avertit_les_eleves_actifs_de_la_classe(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur, ['visio_enabled' => true, 'visio_status' => 'programmee', 'visio_room_id' => 'salon']);
        $inscrit = $this->eleveDe($this->classe);
        $this->eleveDe($this->classe, 'inactif');

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/start-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame([$inscrit->id], $this->destinataires(Notification::TYPE_VISIO_STARTING));
    }

    public function test_une_seance_sans_classe_n_avertit_personne(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur, ['classe_id' => null]);
        $this->eleveDe($this->classe);

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/activate-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame(0, Notification::query()->count());
    }

    /**
     * Sans id local déclaré, rien n'est deviné : l'identifiant reçu par le job
     * peut être un identifiant KLASSCI, et désigner une autre séance.
     */
    public function test_sans_id_local_declare_aucune_seance_n_est_devinee(): void
    {
        $seance = $this->seanceLocale($this->compte('teacher'));
        $this->eleveDe($this->classe);

        $envoyes = app(VisioNotificationDispatcher::class)->notifyVisioScheduled($seance->id, [
            'klassci_classe_id' => null,
            'matiere_nom' => 'Anglais',
        ]);

        self::assertSame(0, $envoyes);
        self::assertSame(0, Notification::query()->count());
    }

    /** Les jobs mis en file par la requête, exécutés comme le ferait le worker. */
    private function executerLesNotifications(): void
    {
        $jobs = Queue::pushed(DispatchVisioNotification::class);
        self::assertNotEmpty($jobs, 'une notification est mise en file');

        foreach ($jobs as $job) {
            $job->handle(app(NotificationService::class), app(LoggerInterface::class));
        }
    }

    /** @return list<int> */
    private function destinataires(string $type): array
    {
        /** @var list<int> $ids */
        $ids = Notification::query()->where('type', $type)->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        return $ids;
    }
}

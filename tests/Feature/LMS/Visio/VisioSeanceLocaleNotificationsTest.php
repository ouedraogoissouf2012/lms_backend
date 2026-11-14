<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Jobs\DispatchVisioNotification;
use App\Models\Classe;
use App\Models\Institution;
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
            (int) (Notification::withoutGlobalScope('institution')->sole()->data['seance_id'] ?? null),
            'la notification désigne la séance par son id local',
        );
    }

    /** Les élèves actifs, puis l'auteur de la séance (`AuteurAvertiAuDemarrageTest`). */
    public function test_le_demarrage_avertit_les_eleves_actifs_de_la_classe(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur, ['visio_enabled' => true, 'visio_status' => 'programmee', 'visio_room_id' => 'salon']);
        $inscrit = $this->eleveDe($this->classe);
        $this->eleveDe($this->classe, 'inactif');

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/start-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame([$inscrit->id, $auteur->id], $this->destinataires(Notification::TYPE_VISIO_STARTING));
    }

    public function test_une_seance_sans_classe_n_avertit_personne(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur, ['classe_id' => null]);
        $this->eleveDe($this->classe);

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/activate-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame(0, Notification::withoutGlobalScope('institution')->count());
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
        self::assertSame(0, Notification::withoutGlobalScope('institution')->count());
    }

    /**
     * L'avertissement suit EXACTEMENT l'audience de l'admission (V3) : un compte
     * d'une autre école, présent dans le pivot de la classe (donnée anormale), est
     * refusé à l'entrée, il n'est donc pas averti non plus.
     */
    public function test_un_compte_d_une_autre_ecole_dans_le_pivot_n_est_pas_averti(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);
        $inscrit = $this->eleveDe($this->classe);
        $etranger = $this->compte('student', Institution::factory()->standalone()->create());
        $this->classe->etudiants()->attach($etranger->id, ['statut' => 'actif']);

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/activate-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame([$inscrit->id], $this->destinataires(Notification::TYPE_VISIO_SCHEDULED));
    }

    /**
     * Un élève dont le rôle s'écrit `student` (alias reconnu par le prédicat de
     * rôle) est admis en salle : il est aussi averti. Pas de rôle écrit en dur.
     */
    public function test_un_eleve_au_role_alias_est_averti_comme_il_est_admis(): void
    {
        $auteur = $this->compte('teacher');
        $seance = $this->seanceLocale($auteur);
        $alias = $this->eleveDe($this->classe);
        $alias->forceFill(['role' => 'student'])->save();

        $this->asTenant($auteur)->postJson("/api/lms/seances/{$seance->id}/activate-visio")->assertOk();
        $this->executerLesNotifications();

        self::assertSame([$alias->id], $this->destinataires(Notification::TYPE_VISIO_SCHEDULED));
    }

    /**
     * Les jobs mis en file par la requête, exécutés comme le ferait le worker :
     * il oublie les instances de la requête, dont le tenant. Sans cela, le scope
     * de l'école de l'auteur filtrerait les comptes à la place du code testé.
     */
    private function executerLesNotifications(): void
    {
        $jobs = Queue::pushed(DispatchVisioNotification::class);
        self::assertNotEmpty($jobs, 'une notification est mise en file');
        $this->app->forgetScopedInstances();

        foreach ($jobs as $job) {
            $job->handle(app(NotificationService::class), app(LoggerInterface::class));
        }
    }

    /** @return list<int> */
    private function destinataires(string $type): array
    {
        /** @var list<int> $ids */
        $ids = Notification::withoutGlobalScope('institution')->where('type', $type)->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        return $ids;
    }
}

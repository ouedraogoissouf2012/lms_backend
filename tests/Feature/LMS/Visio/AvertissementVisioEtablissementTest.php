<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Jobs\DispatchVisioNotification;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Notification\VisioNotificationDispatcher;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * L'avertissement d'une séance KLASSCI suit l'établissement de la séance.
 *
 * `klassci_id` n'est unique que par établissement : chaque école a sa propre
 * instance KLASSCI. Le job tourne sans tenant (le worker oublie celui de la
 * requête), le scope global y est inerte : la classe et l'enseignant se cherchent
 * dans l'établissement lu sur la séance désignée par son id local.
 *
 * Un établissement voisin, aux mêmes identifiants KLASSCI, est créé le premier :
 * ses lignes précèdent celles de la séance.
 */
final class AvertissementVisioEtablissementTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const CLASSE = 55;

    private const ENSEIGNANT = 9;

    private const SEANCE_KLASSCI = 349;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
    }

    public function test_la_programmation_avertit_la_classe_de_l_etablissement_de_la_seance(): void
    {
        [, $eleveVoisin] = $this->ecole();
        [$ecole, $eleve] = $this->ecole();

        $this->dispatcher()->notifyVisioScheduled(self::SEANCE_KLASSCI, $this->charge($this->seance($ecole)));

        self::assertSame([$eleve->id], $this->avertis(Notification::TYPE_VISIO_SCHEDULED));
        self::assertNotContains($eleveVoisin->id, $this->avertis(Notification::TYPE_VISIO_SCHEDULED));
    }

    public function test_le_demarrage_avertit_la_classe_et_l_enseignant_de_l_etablissement_de_la_seance(): void
    {
        $this->ecole();
        [$ecole, $eleve, $enseignant] = $this->ecole();

        $this->dispatcher()->notifyVisioStarting(self::SEANCE_KLASSCI, $this->charge($this->seance($ecole)));

        self::assertSame([$eleve->id, $enseignant->id], $this->avertis(Notification::TYPE_VISIO_STARTING));
    }

    /** Un job mis en file avant que la source ne déclare l'id local garde l'établissement de sa charge. */
    public function test_sans_id_local_l_etablissement_de_la_charge_borne_encore(): void
    {
        $this->ecole();
        [$ecole, $eleve] = $this->ecole();
        $charge = ['institution_id' => $ecole->id] + $this->charge($this->seance($ecole));
        unset($charge['seance_locale_id']);

        $this->dispatcher()->notifyVisioScheduled(self::SEANCE_KLASSCI, $charge);

        self::assertSame([$eleve->id], $this->avertis(Notification::TYPE_VISIO_SCHEDULED));
    }

    /** Sans id local ni établissement, rien n'est deviné : aucun envoi. */
    public function test_sans_id_local_ni_etablissement_personne_n_est_averti(): void
    {
        $this->ecole();
        [$ecole] = $this->ecole();
        $charge = $this->charge($this->seance($ecole));
        unset($charge['seance_locale_id']);

        $envoyes = $this->dispatcher()->notifyVisioStarting(self::SEANCE_KLASSCI, $charge);

        self::assertSame(0, $envoyes);
        self::assertSame(0, Notification::withoutGlobalScope('institution')->count());
    }

    /** Par la route réelle : l'activation, puis le job exécuté comme le worker. */
    public function test_l_activation_par_la_route_avertit_l_etablissement_de_l_enseignant(): void
    {
        Queue::fake();
        [, $eleveVoisin] = $this->ecole();
        [$ecole, $eleve, $enseignant] = $this->ecole();
        $enseignant->forceFill(['klassci_token' => 'jeton-klassci'])->save();
        $this->klassciConnaitLaSeance();

        $this->asTenant($enseignant)->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/activate-visio')->assertOk();
        $this->app->forgetScopedInstances();
        foreach (Queue::pushed(DispatchVisioNotification::class) as $job) {
            $job->handle(app(NotificationService::class), app(LoggerInterface::class));
        }

        self::assertSame([$eleve->id], $this->avertis(Notification::TYPE_VISIO_SCHEDULED));
        self::assertNotContains($eleveVoisin->id, $this->avertis(Notification::TYPE_VISIO_SCHEDULED));
        self::assertSame($ecole->id, Seance::withoutGlobalScopes()->sole()->institution_id);
    }

    /**
     * Une école KLASSCI : sa classe et son enseignant aux MÊMES identifiants
     * KLASSCI que ceux de ses voisins, et un élève actif.
     *
     * @return array{0: Institution, 1: User, 2: User}
     */
    private function ecole(): array
    {
        $ecole = Institution::factory()->create();
        $classe = Classe::factory()->for($ecole)->create(['klassci_id' => self::CLASSE]);
        $eleve = User::factory()->student()->for($ecole)->create();
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);
        $enseignant = User::factory()->teacher()->for($ecole)->create(['klassci_id' => self::ENSEIGNANT]);

        return [$ecole, $eleve, $enseignant];
    }

    private function seance(Institution $ecole): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'klassci_seance_id' => self::SEANCE_KLASSCI,
            'klassci_classe_id' => self::CLASSE,
            'klassci_enseignant_id' => self::ENSEIGNANT,
            'classe_id' => null,
        ]);
    }

    /**
     * La charge EXACTE des sources HTTP (`VisioActivationService`), sans `institution_id`.
     *
     * @return array<string, mixed>
     */
    private function charge(Seance $seance): array
    {
        return [
            'seance_locale_id' => $seance->id,
            'klassci_classe_id' => $seance->klassci_classe_id,
            'klassci_enseignant_id' => $seance->klassci_enseignant_id,
            'matiere_nom' => 'Anglais',
            'enseignant_nom' => 'Enseignant',
        ];
    }

    private function dispatcher(): VisioNotificationDispatcher
    {
        return app(VisioNotificationDispatcher::class);
    }

    /** @return list<int> */
    private function avertis(string $type): array
    {
        /** @var list<int> $ids */
        $ids = Notification::withoutGlobalScope('institution')->where('type', $type)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        return $ids;
    }

    /** L'emploi du temps KLASSCI de l'enseignant connaît la séance, dans la classe 55 (forme réelle, #739). */
    private function klassciConnaitLaSeance(): void
    {
        $matiere = ['id' => 3, 'nom' => 'Anglais'];
        $emploiTemps = ['data' => [[
            'id' => self::SEANCE_KLASSCI,
            'matiere' => $matiere,
            'classe' => ['id' => self::CLASSE, 'libelle' => 'BTS'],
            'programmation' => ['date_seance' => now()->addDays(3)->format('Y-m-d')],
        ]]];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($matiere, $emploiTemps): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton-klassci', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [$matiere]]]);
            $mock->shouldReceive('getEmploiTemps')->andReturn($emploiTemps);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }
}

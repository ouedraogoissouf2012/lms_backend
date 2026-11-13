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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use ReflectionProperty;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La garde d'idempotence des avertissements visio (V1, revue croisée) : sa clé est
 * l'id LOCAL de la séance (`seances.id`), et non l'identifiant reçu par la route.
 *
 * La route reçoit un identifiant KLASSCI dans une école KLASSCI, et un id local
 * dans une école en mode autonome. Le même nombre désigne donc deux séances
 * différentes, et la garde, qui ne lit pas l'établissement, tenait la seconde
 * pour « déjà avertie ». Ses élèves ne recevaient rien. Le cas existe entre deux
 * écoles, et dans une seule école KLASSCI qui a des séances miroitées et des
 * séances créées dans le LMS.
 */
final class NotificationsVisioCleLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const CLASSE_KLASSCI = 55;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
    }

    public function test_deux_ecoles_au_meme_numero_avertissent_chacune_leurs_eleves(): void
    {
        [$locale, $eleveLocal] = $this->seanceLocale(Institution::factory()->standalone()->create());
        [$miroitee, $eleveKlassci] = $this->seanceMiroitee(Institution::factory()->create(), $locale->id);

        $this->programmee($locale->id, $locale);
        $this->programmee($locale->id, $miroitee);

        $this->assertAvertis(Notification::TYPE_VISIO_SCHEDULED, [$eleveLocal, $eleveKlassci]);
        $this->assertAvertis(Notification::TYPE_VISIO_SCHEDULED, [$eleveLocal], $locale);
        $this->assertAvertis(Notification::TYPE_VISIO_SCHEDULED, [$eleveKlassci], $miroitee);
    }

    public function test_une_ecole_klassci_avertit_sa_seance_miroitee_et_sa_seance_locale_au_meme_numero(): void
    {
        $ecole = Institution::factory()->create();
        [$locale, $eleveLocal] = $this->seanceLocale($ecole);
        [$miroitee, $eleveKlassci] = $this->seanceMiroitee($ecole, $locale->id);

        $this->programmee($locale->id, $locale);
        $this->programmee($locale->id, $miroitee);

        $this->assertAvertis(Notification::TYPE_VISIO_SCHEDULED, [$eleveLocal, $eleveKlassci]);
    }

    public function test_le_demarrage_suit_la_meme_cle(): void
    {
        [$locale, $eleveLocal] = $this->seanceLocale(Institution::factory()->standalone()->create());
        [$miroitee, $eleveKlassci] = $this->seanceMiroitee(Institution::factory()->create(), $locale->id);

        app(VisioNotificationDispatcher::class)->notifyVisioStarting($locale->id, $this->donnees($locale));
        app(VisioNotificationDispatcher::class)->notifyVisioStarting($locale->id, $this->donnees($miroitee));

        $this->assertAvertis(Notification::TYPE_VISIO_STARTING, [$eleveLocal, $eleveKlassci]);
    }

    /** La source KLASSCI déclare, elle aussi, l'id local de la séance qu'elle avertit. */
    public function test_l_activation_klassci_declare_l_id_local_de_la_seance(): void
    {
        Queue::fake();
        $this->klassciConnaitLaSeance(349);
        $ecole = Institution::factory()->create();
        $enseignant = User::factory()->teacher()->for($ecole)->create(['klassci_token' => 'jeton-klassci']);

        $this->asTenant($enseignant)->postJson('/api/lms/seances/349/activate-visio')->assertOk();

        $seance = Seance::query()->withoutGlobalScopes()->where('klassci_seance_id', 349)->firstOrFail();
        $jobs = Queue::pushed(DispatchVisioNotification::class);
        self::assertCount(1, $jobs);
        $donnees = (new ReflectionProperty(DispatchVisioNotification::class, 'seanceData'))->getValue($jobs->first());
        self::assertIsArray($donnees);
        self::assertSame($seance->id, $donnees['seance_locale_id'] ?? null);
    }

    /** @return array{0: Seance, 1: User} */
    private function seanceLocale(Institution $ecole): array
    {
        $classe = Classe::factory()->for($ecole)->create(['klassci_id' => null]);
        $seance = Seance::factory()->for($ecole)->create([
            'classe_id' => $classe->id,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
        ]);

        return [$seance, $this->eleveDe($classe)];
    }

    /**
     * Une séance miroitée dont l'identifiant KLASSCI vaut `$klassciId`.
     *
     * @return array{0: Seance, 1: User}
     */
    private function seanceMiroitee(Institution $ecole, int $klassciId): array
    {
        $classe = Classe::factory()->for($ecole)->create(['klassci_id' => self::CLASSE_KLASSCI]);
        $seance = Seance::factory()->for($ecole)->create([
            'klassci_seance_id' => $klassciId,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
            'classe_id' => null,
        ]);

        return [$seance, $this->eleveDe($classe)];
    }

    private function eleveDe(Classe $classe): User
    {
        $eleve = User::factory()->student()->create(['institution_id' => $classe->institution_id]);
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        return $eleve;
    }

    /** Comme le job : l'identifiant de la ROUTE, et l'id local que déclare la source. */
    private function programmee(int $idRoute, Seance $seance): void
    {
        app(VisioNotificationDispatcher::class)->notifyVisioScheduled($idRoute, $this->donnees($seance));
    }

    /** @return array<string, mixed> */
    private function donnees(Seance $seance): array
    {
        return [
            'seance_locale_id' => $seance->id,
            'institution_id' => $seance->institution_id,
            'klassci_classe_id' => $seance->klassci_classe_id,
            'matiere_nom' => 'Maths',
            'enseignant_nom' => 'Enseignant',
        ];
    }

    /** @param  list<User>  $eleves */
    private function assertAvertis(string $type, array $eleves, ?Seance $seance = null): void
    {
        $avertis = Notification::withoutGlobalScope('institution')->where('type', $type)->get()
            ->filter(static fn (Notification $n): bool => $seance === null
                || (((array) $n->data)['seance_locale_id'] ?? null) === $seance->id)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        self::assertSame(collect($eleves)->pluck('id')->sort()->values()->all(), $avertis);
    }

    /** L'emploi du temps KLASSCI connaît la séance `$id` (forme réelle, #739). */
    private function klassciConnaitLaSeance(int $id): void
    {
        $matiere = ['id' => 3, 'nom' => 'Anglais'];
        $emploiTemps = ['data' => [[
            'id' => $id,
            'matiere' => $matiere,
            'classe' => ['id' => 4, 'libelle' => 'BTS'],
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

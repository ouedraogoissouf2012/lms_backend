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
use App\Services\Seances\Sync\SeanceSyncStats;
use App\Services\Seances\Sync\SeanceUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use ReflectionProperty;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Chaque source KLASSCI d'un avertissement visio déclare l'id LOCAL de la séance
 * (`seance_locale_id`), la clé de la garde d'idempotence (V1, revue de #1035).
 *
 * Sans lui, la garde retombe sur l'identifiant de la route, sans qu'aucun
 * avertissement ne manque : seul un test par source rend son oubli visible.
 * L'activation KLASSCI est épinglée dans `NotificationsVisioCleLocaleTest`, les
 * sources locales par leurs propres tests (sans id, personne n'est averti).
 */
final class SourcesKlassciDeclarentIdLocalTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const JETON = 'jeton-klassci';

    private const SEANCE_KLASSCI = 349;

    private const CLASSE_KLASSCI = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
    }

    public function test_la_bascule_declare_l_id_local_de_la_seance(): void
    {
        Queue::fake();
        $this->klassciConnaitLaSeance();
        $coordinateur = User::factory()->coordinator()->for(Institution::factory()->create())
            ->create(['klassci_token' => self::JETON]);

        $this->asTenant($coordinateur)
            ->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/toggle-visio', ['enabled' => true])
            ->assertOk();

        $this->assertIdLocalDeclare(self::seanceKlassci()->id);
    }

    public function test_le_demarrage_d_une_seance_miroitee_declare_son_id_local(): void
    {
        Queue::fake();
        $ecole = Institution::factory()->create();
        $enseignant = User::factory()->teacher()->for($ecole)->create(['klassci_token' => self::JETON, 'klassci_id' => 9]);
        $seance = Seance::factory()->for($ecole)->create([
            'klassci_seance_id' => self::SEANCE_KLASSCI,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
            'klassci_enseignant_id' => 9,
            'created_by' => $enseignant->id,
            'visio_enabled' => true,
            'visio_status' => 'programmee',
            'visio_room_id' => 'salon',
        ]);

        $this->asTenant($enseignant)->postJson("/api/lms/seances/{$seance->id}/start-visio")->assertOk();

        $this->assertIdLocalDeclare($seance->id);
    }

    /** La synchronisation avertit sans file : la notification écrite porte l'id local. */
    public function test_la_synchronisation_declare_l_id_local_de_la_seance_creee(): void
    {
        $ecole = Institution::factory()->create();
        $enseignant = User::factory()->teacher()->for($ecole)->create(['klassci_token' => self::JETON, 'klassci_id' => 9]);
        $classe = Classe::factory()->for($ecole)->create(['klassci_id' => self::CLASSE_KLASSCI]);
        $eleve = User::factory()->student()->for($ecole)->create();
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        app(SeanceUpsertService::class)->upsert(
            $enseignant,
            self::JETON,
            $ecole->id,
            ['id' => 3, 'nom' => 'Anglais'],
            ['id' => self::SEANCE_KLASSCI, 'classe' => ['id' => self::CLASSE_KLASSCI]],
            new SeanceSyncStats,
        );

        $notification = Notification::withoutGlobalScope('institution')->where('user_id', $eleve->id)->sole();
        self::assertSame(self::seanceKlassci()->id, $notification->data['seance_locale_id'] ?? null);
    }

    private function assertIdLocalDeclare(int $idLocal): void
    {
        $jobs = Queue::pushed(DispatchVisioNotification::class);
        self::assertCount(1, $jobs, 'un avertissement est mis en file');
        $donnees = (new ReflectionProperty(DispatchVisioNotification::class, 'seanceData'))->getValue($jobs->first());
        self::assertIsArray($donnees);
        self::assertSame($idLocal, $donnees['seance_locale_id'] ?? null);
    }

    private static function seanceKlassci(): Seance
    {
        return Seance::query()->withoutGlobalScopes()->where('klassci_seance_id', self::SEANCE_KLASSCI)->sole();
    }

    /** Les matières de l'enseignant listent la séance (lecture de la bascule, `FetchesSeanceDataFromKlassci`). */
    private function klassciConnaitLaSeance(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with(self::JETON, 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 3, 'nom' => 'Anglais']]]);
            $mock->shouldReceive('requestWithUserToken')->with(self::JETON, 'matieres/3', 'GET')
                ->andReturn(['data' => [
                    'seances_programmees' => [['id' => self::SEANCE_KLASSCI, 'classe' => ['id' => self::CLASSE_KLASSCI]]],
                    'enseignant' => ['id' => 9, 'nom_complet' => 'Enseignant'],
                ]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }
}

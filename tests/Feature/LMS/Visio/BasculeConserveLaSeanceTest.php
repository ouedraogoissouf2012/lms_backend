<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\InstitutionMode;
use App\Jobs\DispatchVisioNotification;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use ReflectionProperty;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La bascule (`toggle-visio`) d'une séance que la synchronisation a déjà écrite, en
 * école KLASSCI : elle change l'ÉTAT de la visio, jamais la classe, l'enseignant,
 * la matière ni le salon de la séance. Les élèves restent admis, et l'activation
 * les avertit d'après la séance elle-même.
 *
 * KLASSCI est simulé tel qu'il répond : `matieres/{id}.seances_programmees` vide
 * (mesuré, voir `KlassciEmploiTempsSeances`). La création d'une séance par la
 * bascule, sans ligne, est gardée par `VisioParticipantValidationContractTest` et
 * `SourcesKlassciDeclarentIdLocalTest`.
 */
final class BasculeConserveLaSeanceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const JETON = 'jeton-klassci';

    private const SEANCE_KLASSCI = 349;

    private const CLASSE_KLASSCI = 4;

    private const ENSEIGNANT_KLASSCI = 9;

    private const MATIERE_KLASSCI = 3;

    private const SALON = 'salon-synchronise';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
        $this->klassciTelQuIlRepond();
    }

    public function test_l_activation_garde_la_classe_l_enseignant_la_matiere_et_le_salon(): void
    {
        [$ecole, $seance] = $this->seanceSynchronisee();

        $this->basculer($ecole, true)->assertJsonPath('data.visio_room_id', self::SALON);

        $ligne = $seance->fresh();
        self::assertNotNull($ligne);
        self::assertSame(
            [self::CLASSE_KLASSCI, self::ENSEIGNANT_KLASSCI, self::MATIERE_KLASSCI, self::SALON, true],
            [$ligne->klassci_classe_id, $ligne->klassci_enseignant_id, $ligne->klassci_matiere_id, $ligne->visio_room_id, $ligne->visio_enabled],
        );
    }

    public function test_apres_l_activation_l_eleve_de_la_classe_est_admis(): void
    {
        [$ecole, $seance, $eleve] = $this->seanceSynchronisee();
        $coordinateur = $this->basculeur($ecole);
        $this->asTenant($coordinateur)->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/toggle-visio', ['enabled' => true])->assertOk();

        $this->asTenant($coordinateur)
            ->postJson("/api/lms/seances/{$seance->id}/validate-participant", ['user_id' => $eleve->id])
            ->assertOk()
            ->assertJsonPath('authorized', true);
    }

    public function test_l_activation_avertit_d_apres_la_seance(): void
    {
        [$ecole, $seance] = $this->seanceSynchronisee();

        $this->basculer($ecole, true);

        $jobs = Queue::pushed(DispatchVisioNotification::class);
        self::assertCount(1, $jobs, 'un avertissement est mis en file');
        $donnees = (new ReflectionProperty(DispatchVisioNotification::class, 'seanceData'))->getValue($jobs->first());
        self::assertIsArray($donnees);
        self::assertSame($seance->id, $donnees['seance_locale_id'] ?? null);
        self::assertSame(self::CLASSE_KLASSCI, $donnees['klassci_classe_id'] ?? null);
    }

    /** La désactivation garde aussi la classe et l'enseignant de la séance. */
    public function test_la_desactivation_garde_la_classe_et_l_enseignant(): void
    {
        [$ecole, $seance] = $this->seanceSynchronisee(['visio_enabled' => true, 'visio_status' => 'programmee']);

        $this->basculer($ecole, false);

        $ligne = $seance->fresh();
        self::assertNotNull($ligne);
        self::assertSame([self::CLASSE_KLASSCI, self::ENSEIGNANT_KLASSCI, false], [$ligne->klassci_classe_id, $ligne->klassci_enseignant_id, $ligne->visio_enabled]);
    }

    /**
     * Une séance telle que la synchronisation l'écrit (`SeanceUpsertService`), avec
     * sa classe locale et un élève actif.
     *
     * @param  array<string, mixed>  $etat
     * @return array{0: Institution, 1: Seance, 2: User}
     */
    private function seanceSynchronisee(array $etat = []): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $enseignant = User::factory()->teacher()->for($ecole)->create(['klassci_id' => self::ENSEIGNANT_KLASSCI]);
        $classe = Classe::factory()->for($ecole)->create(['klassci_id' => self::CLASSE_KLASSCI]);
        $eleve = User::factory()->student()->for($ecole)->create();
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);
        $seance = Seance::factory()->for($ecole)->create($etat + [
            'klassci_seance_id' => self::SEANCE_KLASSCI,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
            'klassci_enseignant_id' => self::ENSEIGNANT_KLASSCI,
            'klassci_matiere_id' => self::MATIERE_KLASSCI,
            'matiere_nom' => 'Anglais',
            'enseignant_nom' => 'Enseignant',
            'created_by' => $enseignant->id,
            'visio_enabled' => false,
            'visio_status' => null,
            'visio_room_id' => self::SALON,
        ]);

        return [$ecole, $seance, $eleve];
    }

    private function basculeur(Institution $ecole): User
    {
        return User::factory()->coordinator()->for($ecole)->create(['klassci_token' => self::JETON]);
    }

    private function basculer(Institution $ecole, bool $active): TestResponse
    {
        return $this->asTenant($this->basculeur($ecole))
            ->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/toggle-visio', ['enabled' => $active])
            ->assertOk();
    }

    /** `matieres/{id}` liste la matière, mais sans séance programmée : la réponse réelle de KLASSCI. */
    private function klassciTelQuIlRepond(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with(self::JETON, 'matieres', 'GET')
                ->andReturn(['data' => [['id' => self::MATIERE_KLASSCI, 'nom' => 'Anglais']]]);
            $mock->shouldReceive('requestWithUserToken')->with(self::JETON, 'matieres/'.self::MATIERE_KLASSCI, 'GET')
                ->andReturn(['data' => ['seances_programmees' => [], 'enseignant' => ['id' => self::ENSEIGNANT_KLASSCI, 'nom_complet' => 'Enseignant']]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }
}

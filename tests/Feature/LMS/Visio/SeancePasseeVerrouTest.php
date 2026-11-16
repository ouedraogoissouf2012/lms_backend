<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le verrou des séances passées (signalé par le user, capture en prod) : une séance
 * dont la fin est dépassée de plus de 30 min (`end_window` de l'affichage) ne se
 * démarre plus, ne s'active plus, et sa visio ne se rejoint plus si elle n'est plus
 * active depuis moins de 4 h (`is_accessible`). Refus : 409 `seance_past`.
 *
 * Fin d'une séance : la fin réelle si on la connaît (programmation KLASSCI,
 * `fin_seance`), sinon début + 2 h pour une séance LOCALE (l'estimation de
 * l'affichage). Une ligne KLASSCI sans fin connue n'est PAS refusée : échec ouvert,
 * déclaré, plutôt qu'une fin inventée qui couperait un cours.
 *
 * Fuseau : UTC (celui de l'application et de la Côte d'Ivoire). Vrai jeton.
 */
final class SeancePasseeVerrouTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const SEANCE_KLASSCI = 349;

    private const JETON = 'jeton-klassci';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->travelTo(Carbon::parse('2026-11-16 15:00:00', 'UTC'));
    }

    public function test_ecole_du_lms_une_seance_passee_ne_se_demarre_ni_ne_s_active(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $gestion = $this->compte($ecole, 'coordinateur', null);
        // Début 12:00, fin estimée 14:00, marge jusqu'à 14:30 : passée à 15:00.
        $programmee = $this->seanceLocale($ecole, '2026-11-16 12:00:00', ['visio_enabled' => true]);
        $aActiver = $this->seanceLocale($ecole, '2026-11-16 12:00:00', ['visio_enabled' => false]);

        $this->assertSeancePassee($this->asTenant($gestion)->postJson("/api/lms/seances/{$programmee->id}/start-visio"));
        $this->assertSeancePassee($this->asTenant($gestion)->postJson("/api/lms/seances/{$aActiver->id}/activate-visio"));
        $this->assertSeancePassee($this->asTenant($gestion)->postJson("/api/lms/seances/{$aActiver->id}/toggle-visio", ['enabled' => true]));

        self::assertSame('programmee', $programmee->fresh()?->visio_status, 'rien n est démarré');
        self::assertFalse((bool) $aActiver->fresh()?->visio_enabled, 'rien n est activé');
    }

    /** Témoins : une séance EN COURS (fin passée de moins de 30 min) et une séance future restent ouvertes. */
    public function test_ecole_du_lms_une_seance_en_cours_ou_future_reste_ouverte(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $gestion = $this->compte($ecole, 'coordinateur', null);
        // Début 12:40, fin estimée 14:40 : la marge court jusqu'à 15:10.
        $enCours = $this->seanceLocale($ecole, '2026-11-16 12:40:00', ['visio_enabled' => true]);
        $future = $this->seanceLocale($ecole, '2026-11-17 09:00:00', ['visio_enabled' => false]);

        $this->asTenant($gestion)->postJson("/api/lms/seances/{$enCours->id}/start-visio")->assertOk();
        $this->asTenant($gestion)->postJson("/api/lms/seances/{$future->id}/activate-visio")->assertOk();
    }

    /** Désactiver la visio d'une séance passée reste permis : le verrou ne porte que sur l'ouverture. */
    public function test_desactiver_une_seance_passee_reste_permis(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $seance = $this->seanceLocale($ecole, '2026-11-16 08:00:00', ['visio_enabled' => true]);

        $this->asTenant($this->compte($ecole, 'coordinateur', null))
            ->postJson("/api/lms/seances/{$seance->id}/toggle-visio", ['enabled' => false])
            ->assertOk();
    }

    /** Rejoindre suit `is_accessible` : une visio démarrée à temps reste joignable 4 h ; au-delà, refus. */
    public function test_rejoindre_suit_la_regle_d_accessibilite(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $eleve = $this->compte($ecole, 'etudiant', null);
        DB::table('classe_etudiant')->insert(['classe_id' => $classe->id, 'user_id' => $eleve->id, 'statut' => 'actif',
            'date_inscription' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
        $active = ['classe_id' => $classe->id, 'visio_enabled' => true, 'visio_status' => 'active', 'visio_active' => true];

        $recente = $this->seanceLocale($ecole, '2026-11-16 09:00:00', $active + ['visio_started_at' => '2026-11-16 14:00:00']);
        $ancienne = $this->seanceLocale($ecole, '2026-11-16 09:00:00', $active + ['visio_started_at' => '2026-11-16 09:00:00']);

        $joint = $this->asTenant($eleve)->postJson("/api/lms/seances/{$recente->id}/join");
        self::assertNotSame(409, $joint->status(), 'visio démarrée il y a 1 h : joignable');
        $this->assertSeancePassee($this->asTenant($eleve)->postJson("/api/lms/seances/{$ancienne->id}/join"));
    }

    /** Minuit (UTC) : la règle compare des instants, pas des dates. Un cours de 23:30 court encore à 00:20. */
    public function test_au_voisinage_de_minuit_la_regle_compare_des_instants(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $gestion = $this->compte($ecole, 'coordinateur', null);
        $seance = $this->seanceLocale($ecole, '2026-11-15 23:30:00', ['visio_enabled' => true]);

        $this->travelTo(Carbon::parse('2026-11-16 00:20:00', 'UTC'));
        $this->asTenant($gestion)->postJson("/api/lms/seances/{$seance->id}/start-visio")->assertOk();

        $seance->forceFill(['visio_status' => 'programmee', 'visio_active' => false])->save();
        $this->travelTo(Carbon::parse('2026-11-16 02:01:00', 'UTC'));
        $this->assertSeancePassee($this->asTenant($gestion)->postJson("/api/lms/seances/{$seance->id}/start-visio"));
    }

    /** École KLASSCI : la fin réelle, écrite à l'activation, verrouille le démarrage ; sans fin connue, échec ouvert. */
    public function test_ecole_klassci_le_demarrage_lit_la_fin_reelle(): void
    {
        $ecole = Institution::factory()->create();
        // Sans jeton : démarrer ne lit pas KLASSCI, et la resynchronisation de classe se tait.
        $gestion = $this->compte($ecole, 'coordinateur', null);
        $passee = $this->seanceKlassci($ecole, self::SEANCE_KLASSCI, '2026-11-16 08:00:00', '2026-11-16 12:00:00');
        $sansFin = $this->seanceKlassci($ecole, self::SEANCE_KLASSCI + 1, '2026-11-16 08:00:00', null);
        // Fin réelle 16:00 : un cours de 4 h, que l'estimation (+ 2 h) aurait déclaré passé.
        $longue = $this->seanceKlassci($ecole, self::SEANCE_KLASSCI + 2, '2026-11-16 12:00:00', '2026-11-16 16:00:00');

        $this->assertSeancePassee($this->asTenant($gestion)->postJson("/api/lms/seances/{$passee->id}/start-visio"));
        self::assertNotSame(409, $this->asTenant($gestion)->postJson("/api/lms/seances/{$sansFin->id}/start-visio")->status(), 'fin inconnue : pas de refus');
        self::assertNotSame(409, $this->asTenant($gestion)->postJson("/api/lms/seances/{$longue->id}/start-visio")->status(), 'fin réelle 16:00 : en cours');
    }

    /** École KLASSCI : l'activation lit la programmation réelle, refuse une séance passée, et garde la fin d'une séance future. */
    public function test_ecole_klassci_l_activation_lit_la_programmation(): void
    {
        $ecole = Institution::factory()->create();
        $gestion = $this->compte($ecole, 'coordinateur', self::JETON);

        $this->klassciConnait(self::SEANCE_KLASSCI, '2026-11-16', '08:00:00', '10:00:00');
        $this->assertSeancePassee($this->asTenant($gestion)->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/activate-visio'));
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->where('klassci_seance_id', self::SEANCE_KLASSCI)->count());

        $this->klassciConnait(self::SEANCE_KLASSCI, '2026-11-18', '08:00:00', '12:00:00');
        $this->asTenant($gestion)->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/activate-visio')->assertOk();
        $ligne = Seance::query()->withoutGlobalScopes()->where('klassci_seance_id', self::SEANCE_KLASSCI)->firstOrFail();
        self::assertSame('2026-11-18 12:00:00', $ligne->fin_seance?->utc()->format('Y-m-d H:i:s'));
    }

    /** École KLASSCI : la bascule ne réactive pas une séance passée ; elle se désactive toujours. */
    public function test_ecole_klassci_la_bascule_ne_reactive_pas_une_seance_passee(): void
    {
        $ecole = Institution::factory()->create();
        $gestion = $this->compte($ecole, 'coordinateur', self::JETON);
        $this->seanceKlassci($ecole, self::SEANCE_KLASSCI, '2026-11-16 08:00:00', '2026-11-16 10:00:00', ['visio_enabled' => false]);

        $this->assertSeancePassee($this->asTenant($gestion)->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/toggle-visio', ['enabled' => true]));
        $this->asTenant($gestion)->postJson('/api/lms/seances/'.self::SEANCE_KLASSCI.'/toggle-visio', ['enabled' => false])->assertOk();
    }

    /** « À venir » : la séance terminée ce matin n'y est plus ; celle en cours (marge) et celle de ce soir y sont. */
    public function test_les_seances_a_venir_ecole_du_lms(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $passee = $this->seanceLocale($ecole, '2026-11-16 08:00:00');
        $enCours = $this->seanceLocale($ecole, '2026-11-16 12:45:00');
        $ceSoir = $this->seanceLocale($ecole, '2026-11-16 18:00:00');

        $ids = $this->aVenir($this->compte($ecole, 'coordinateur', null));

        self::assertNotContains($passee->id, $ids);
        self::assertContains($enCours->id, $ids);
        self::assertContains($ceSoir->id, $ids);
    }

    /** « À venir », école KLASSCI (cache local de la gestion) : la fin réelle `fin_seance` décide, pas l'estimation. */
    public function test_les_seances_a_venir_ecole_klassci_lisent_la_fin_reelle(): void
    {
        $ecole = Institution::factory()->create();
        $this->seanceKlassci($ecole, 501, '2026-11-16 08:00:00', '2026-11-16 12:00:00');
        $this->seanceKlassci($ecole, 502, '2026-11-16 12:00:00', '2026-11-16 16:00:00');

        $reponse = $this->asTenant($this->compte($ecole, 'coordinateur', self::JETON))->getJson('/api/lms/seances/upcoming')->assertOk();
        $klassciIds = array_column((array) $reponse->json('data'), 'klassci_seance_id');

        self::assertNotContains(501, $klassciIds, 'fin réelle 12:00 : passée');
        self::assertContains(502, $klassciIds, 'fin réelle 16:00 : en cours (l estimation dirait passée)');
        self::assertSame(count($klassciIds), $reponse->json('meta.total_seances'));
    }

    /** La migration est additive et réversible. */
    public function test_la_migration_fin_seance_se_defait_et_se_refait(): void
    {
        $migration = require database_path('migrations/2026_11_16_000000_add_fin_seance_to_seances_table.php');

        self::assertTrue(Schema::hasColumn('seances', 'fin_seance'));
        $migration->down();
        self::assertFalse(Schema::hasColumn('seances', 'fin_seance'));
        $migration->up();
        self::assertTrue(Schema::hasColumn('seances', 'fin_seance'));
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertSeancePassee(TestResponse $reponse): void
    {
        $reponse->assertStatus(409)->assertJsonPath('reason', 'seance_past')->assertJsonPath('success', false);
    }

    /** @return list<int> */
    private function aVenir(User $compte): array
    {
        $data = $this->asTenant($compte)->getJson('/api/lms/seances/upcoming')->assertOk()->json('data');

        return array_values(array_map(static fn (array $s): int => (int) $s['id'], is_array($data) ? $data : []));
    }

    private function compte(Institution $ecole, string $role, ?string $jeton): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => $jeton]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function seanceLocale(Institution $ecole, string $debut, array $attributs = []): Seance
    {
        return Seance::factory()->for($ecole)->create($attributs + [
            'date_seance' => $debut, 'is_active' => true, 'visio_status' => 'programmee', 'visio_active' => false,
            'klassci_seance_id' => null, 'klassci_enseignant_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null,
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function seanceKlassci(Institution $ecole, int $klassciId, string $debut, ?string $fin, array $attributs = []): Seance
    {
        return Seance::factory()->for($ecole)->create($attributs + [
            'klassci_seance_id' => $klassciId, 'date_seance' => $debut, 'fin_seance' => $fin, 'is_active' => true,
            'visio_enabled' => true, 'visio_status' => 'programmee', 'visio_active' => false,
        ]);
    }

    /** L'emploi du temps KLASSCI, dans sa forme réelle : `programmation.date`, `heure_debut`, `heure_fin`. */
    private function klassciConnait(int $seanceId, string $date, string $debut, string $fin): void
    {
        $matiere = ['id' => 3, 'nom' => 'Anglais'];
        $emploiTemps = ['data' => [[
            'id' => $seanceId,
            'matiere' => $matiere,
            'classe' => ['id' => 4, 'libelle' => 'BTS Genie Civil'],
            'programmation' => ['date' => $date, 'heure_debut' => "{$date}T{$debut}.000000Z", 'heure_fin' => "{$date}T{$fin}.000000Z"],
        ]]];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($matiere, $emploiTemps): void {
            $mock->shouldReceive('requestWithUserToken')->with(self::JETON, 'me/teacher-dashboard', 'GET')->andReturn(['data' => ['matieres' => [$matiere]]]);
            $mock->shouldReceive('requestWithUserToken')->with(self::JETON, 'matieres', 'GET')->andReturn(['data' => [$matiere]]);
            $mock->shouldReceive('getEmploiTemps')->andReturn($emploiTemps);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use App\Services\Visio\Lifecycle\VisioActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L'activation visio cherche la séance dans une clé KLASSCI toujours vide (#739).
 *
 * ## Le défaut, mesuré en production le 2026-09-14
 *
 * `VisioActivationService::locateKlassciSeance()` parcourt les matières de
 * l'enseignant et lit dans chacune `data.seances_programmees`. Mesure faite avec
 * le jeton réel de `bede@gmail.com` sur la production, ce jour-là :
 *
 *     matiere 1 (Marketing digital) : seances_programmees = 0  | statistiques.seances.total_programmees = 47
 *     matiere 2 (Algorithme)        : seances_programmees = 0  | statistiques.seances.total_programmees = 51
 *     matiere 3 (Anglais)           : seances_programmees = 0  | statistiques.seances.total_programmees = 28
 *
 * KLASSCI se contredit dans le MÊME corps de réponse. `firstWhere` ne peut donc
 * jamais aboutir, et `activate()` rend `404 « Séance non trouvée »` — ligne 47.
 *
 * ## Pourquoi personne ne l'a vu
 *
 * `SeanceUpsertService::create()` — le chemin de SYNCHRONISATION — crée déjà
 * chaque séance avec `visio_enabled = true` et un `visio_room_id`. L'écran montre
 * donc une visio active, et le bouton « Activer » ne sert qu'à produire un 404
 * que personne ne relie au reste.
 *
 * ## Le piège que ces tests verrouillent
 *
 * Remplacer la source SANS rien d'autre ne suffit pas : `activate()` passe
 * `'visio_room_id' => SecureVisioRoomIdGenerator::make()` en valeur
 * INCONDITIONNELLE de son `updateOrCreate`. Sur une séance déjà synchronisée,
 * activer RÉATTRIBUERAIT le salon — les liens déjà envoyés aux étudiants
 * deviendraient morts, et un cours en train de se tenir serait coupé.
 *
 * Le second test échoue sur la correction naïve et passe sur la correction
 * complète. C'est sa raison d'être.
 */
final class VisioActivationSeanceSourceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-enseignant';

    private const KLASSCI_SEANCE_ID = 349;

    private const KLASSCI_MATIERE_ID = 3;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_token' => self::TOKEN,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE défaut : la séance existe chez KLASSCI, mais pas dans la clé que le
     * service interroge. L'activation doit malgré tout aboutir.
     */
    public function test_activation_finds_the_seance_although_seances_programmees_is_empty(): void
    {
        $this->fakeKlassci();

        $resultat = app(VisioActivationService::class)->activate(self::KLASSCI_SEANCE_ID, $this->teacher);

        self::assertSame(200, $resultat['status'], 'la seance existe dans l emploi du temps : l activation doit aboutir');
        self::assertTrue($resultat['payload']['success'] ?? false);

        $seance = Seance::query()->where('klassci_seance_id', self::KLASSCI_SEANCE_ID)->first();
        self::assertNotNull($seance, 'la seance doit etre enregistree localement');
        self::assertTrue((bool) $seance->visio_enabled);
    }

    /**
     * Un salon déjà attribué ne se réattribue pas.
     *
     * La séance a été créée par la synchronisation, avec son salon et ses
     * notifications déjà parties. Activer ne doit pas changer l'adresse sous les
     * pieds de ceux qui l'ont reçue.
     */
    public function test_activation_does_not_reassign_a_room_already_attributed(): void
    {
        $salonDOrigine = 'lms_a66243351f250a8f1e0d89f98f52658ccd122454';

        Seance::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => self::KLASSCI_SEANCE_ID,
            'klassci_matiere_id' => self::KLASSCI_MATIERE_ID,
            'visio_enabled' => true,
            'visio_type' => 'jitsi',
            'visio_status' => 'programmee',
            'visio_room_id' => $salonDOrigine,
        ]);

        $this->fakeKlassci();

        $resultat = app(VisioActivationService::class)->activate(self::KLASSCI_SEANCE_ID, $this->teacher);

        self::assertSame(200, $resultat['status']);

        $seance = Seance::query()->where('klassci_seance_id', self::KLASSCI_SEANCE_ID)->firstOrFail();
        self::assertSame(
            $salonDOrigine,
            $seance->visio_room_id,
            'le salon a ete reattribue : les liens deja envoyes aux etudiants sont morts',
        );
        self::assertSame($salonDOrigine, $resultat['payload']['data']['visio_room_id'] ?? null);
    }

    // ───────────────────── Simulacre KLASSCI ─────────────────────

    /**
     * La forme RÉELLE, telle que mesurée le 2026-09-14 : `matieres/{id}` répond
     * avec `seances_programmees` VIDE, et l'emploi du temps porte la séance.
     */
    private function fakeKlassci(): void
    {
        $dashboard = ['data' => ['matieres' => [
            ['id' => 1, 'nom' => 'Marketing digital'],
            ['id' => 2, 'nom' => 'Algorithme'],
            ['id' => self::KLASSCI_MATIERE_ID, 'nom' => 'Anglais'],
        ]]];

        // La clé morte : KLASSCI la rend vide tout en annonçant 28 séances.
        $detailsMatiere = ['data' => [
            'matiere' => ['id' => self::KLASSCI_MATIERE_ID, 'nom' => 'Anglais'],
            'seances_programmees' => [],
            'statistiques' => ['seances' => ['total_programmees' => 28]],
        ]];

        $emploiTemps = ['data' => [[
            'id' => self::KLASSCI_SEANCE_ID,
            'matiere' => ['id' => self::KLASSCI_MATIERE_ID, 'nom' => 'Anglais'],
            'classe' => ['id' => 4, 'libelle' => 'BTS Genie Civil'],
            'programmation' => ['date_seance' => now()->addDays(3)->format('Y-m-d')],
            'salle' => ['nom' => 'B12'],
        ]]];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($dashboard, $detailsMatiere, $emploiTemps): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn($dashboard);

            $mock->shouldReceive('requestWithUserToken')
                ->withArgs(fn (...$a): bool => isset($a[1]) && str_starts_with((string) $a[1], 'matieres/'))
                ->andReturn($detailsMatiere);

            $mock->shouldReceive('getEmploiTemps')->andReturn($emploiTemps);

            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }
}

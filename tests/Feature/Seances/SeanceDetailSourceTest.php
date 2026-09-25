<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Le détail d'une séance cherche dans une clé KLASSCI toujours vide — périmètre
 * restant de #740.
 *
 * ## Le défaut, et la mesure qui l'établit
 *
 * `KlassciSeanceMatiereScanner::findSeance()` ne lit que
 * `matieres/{id}.data.seances_programmees`, sans aucun repli. Cette clé est
 * **systématiquement vide** : mesure refaite le 2026-09-14 avec le jeton réel de
 * l'enseignant `bede@gmail.com` sur la production —
 *
 *     matiere 1 (Marketing digital) : seances_programmees = 0 | total_programmees = 47
 *     matiere 2 (Algorithme)        : seances_programmees = 0 | total_programmees = 51
 *     matiere 3 (Anglais)           : seances_programmees = 0 | total_programmees = 28
 *
 * KLASSCI se contredit dans le même corps. La recherche échoue donc **par
 * construction**, et `SeanceDetailQueryService:82` tombe dans
 * `SeanceVisioEnricher::loadFromLocalDbFallback()`.
 *
 * ## Pourquoi ce n'est pas « un repli »
 *
 * Ce bloc n'est pas un filet de sécurité rare : il est le chemin **nominal** de
 * l'écran. Et il **invente** six valeurs alors que la ligne locale les porte —
 * `'B2 COM'` en dur pour le nom de classe, la date du JOUR à la place de
 * `date_seance`, un horaire 08 h–10 h, une salle `'TEAM'`, `duree_minutes = 120`,
 * `statut = 'programme'`.
 *
 * Un enseignant ouvrant une séance de la semaine dernière la voit donc datée
 * d'aujourd'hui, dans une classe qui n'est pas la sienne.
 *
 * ## Ce que ces tests prouvent
 *
 * `EmploiTempsSeanceLocator` existe déjà et porte la même mesure ; son docblock
 * le désigne comme « le remplaçant canonique vers lequel ils doivent
 * converger ». Ces tests sont RED avant cette convergence et GREEN après : ils
 * sont la preuve que le correctif porte sur la cause, pas sur le symptôme.
 *
 * @see app/Services/Seances/EmploiTempsSeanceLocator.php
 * @see app/Services/Seances/KlassciSeanceMatiereScanner.php
 */
final class SeanceDetailSourceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-enseignant';

    private const KLASSCI_SEANCE_ID = 349;

    private const KLASSCI_MATIERE_ID = 3;

    /** La classe RÉELLE de la séance — ni `'B2 COM'`, ni rien d'inventé. */
    private const CLASSE_REELLE = 'BTS Genie Civil';

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
     * La séance existe dans l'emploi du temps. Son détail doit la décrire —
     * pas décrire une séance imaginaire.
     */
    public function test_le_detail_decrit_la_seance_et_non_un_bouchon(): void
    {
        $this->seanceLocale();
        $this->fakeKlassci();

        $seance = $this->detail()['seance'] ?? [];

        self::assertSame(
            self::CLASSE_REELLE,
            $seance['classe']['nom'] ?? null,
            "le nom de classe est un litteral de jeu d'essai, pas la classe de la seance",
        );
    }

    /**
     * La date affichée est celle de la séance, pas celle du jour.
     *
     * C'est le plus grave des six : une séance passée s'affiche comme si elle
     * avait lieu aujourd'hui, et rien à l'écran ne le signale.
     */
    public function test_la_date_affichee_est_celle_de_la_seance(): void
    {
        $seanceLocale = $this->seanceLocale();
        $this->fakeKlassci();

        $attendue = $seanceLocale->date_seance->format('Y-m-d');
        self::assertNotSame(now()->format('Y-m-d'), $attendue, 'la fixture doit differer d aujourd hui, sinon le test ne prouve rien');

        $seance = $this->detail()['seance'] ?? [];

        self::assertSame(
            $attendue,
            $seance['programmation']['date'] ?? null,
            'la date du JOUR est servie a la place de celle de la seance',
        );
    }

    /**
     * L'identifiant de matière n'est jamais FABRIQUÉ.
     *
     * Le bloc rend `klassci_matiere_id ?? 1` : à défaut, la matière **numéro 1**,
     * celle d'un autre enseignant, choisie parce qu'elle est la première.
     *
     * La fixture porte donc une séance SANS identifiant KLASSCI de matière —
     * le cas d'une séance créée localement. Mesuré à l'écriture : avec un
     * `klassci_matiere_id` renseigné, ce test passait sans rien prouver.
     */
    public function test_l_identifiant_de_matiere_n_est_jamais_fabrique(): void
    {
        $this->seanceLocale(['klassci_matiere_id' => null]);
        $this->fakeKlassci();

        $seance = $this->detail()['seance'] ?? [];

        self::assertNotSame(
            1,
            $seance['matiere']['id'] ?? null,
            'sans identifiant KLASSCI de matiere, en fabriquer un designe la matiere de quelqu un d autre',
        );
    }

    // ───────── Le repli lui-meme (#870) : ce qu il sert quand il s execute ─────────

    /**
     * Le repli decrit la seance, il ne la fabrique pas.
     *
     * Depuis #888 l ENSEIGNANT ne tombe plus ici. L ETUDIANT, si : son parcours
     * lit encore `seances_programmees`. Ce test emprunte donc son chemin, seul
     * moyen d exercer le repli reellement.
     *
     * Mesure de production du 22/09/2026 : sur les 6 seances en base, AUCUNE
     * colonne n est vide -- ni `classe_nom`, ni `matiere_nom`, ni `date_seance`.
     * Les inventions ne compensent donc aucune donnee manquante.
     */
    public function test_le_repli_sert_les_valeurs_de_la_ligne_et_non_des_litteraux(): void
    {
        $locale = $this->seanceLocale();
        $etudiant = $this->etudiant();
        $this->fakeKlassciSansRienTrouver();

        $seance = $this->detailPour($etudiant)['seance'] ?? [];

        self::assertSame(self::CLASSE_REELLE, $seance['classe']['nom'] ?? null, 'nom de classe fabrique');
        self::assertSame(
            $locale->date_seance->format('Y-m-d'),
            $seance['programmation']['date'] ?? null,
            'la date du JOUR est servie a la place de celle de la seance',
        );
        self::assertSame(
            $locale->date_seance->format('H:i'),
            substr((string) ($seance['programmation']['heure_debut'] ?? ''), 11, 5),
            'l horaire 08h00 est fabrique : `date_seance` porte l heure reelle',
        );
    }

    /**
     * La salle n a AUCUNE colonne. En inventer une (`'TEAM'`) affirme un lieu.
     *
     * L omettre est sans danger : le frontend rend deja « N/A » ou masque le
     * champ -- `EventSeanceDetails.vue:59`, `ClassePlanningTab.vue:30`,
     * `CoordinatorSeanceCard.vue:35`.
     */
    public function test_le_repli_n_invente_pas_de_salle(): void
    {
        $this->seanceLocale();
        $etudiant = $this->etudiant();
        $this->fakeKlassciSansRienTrouver();

        $seance = $this->detailPour($etudiant)['seance'] ?? [];

        self::assertNotSame('TEAM', $seance['programmation']['salle'] ?? null, 'une salle est affirmee sans source');
    }

    /**
     * Une duree inconnue se DIT, elle ne se fabrique pas.
     *
     * La table ne porte pas d heure de fin. Le bloc rendait `120` pour tout le
     * monde ; l orchestrateur, lui, aurait calcule l ecart entre le debut et
     * l INSTANT DE LA REQUETE si on lui avait passe `null` sans garde --
     * `Carbon::parse(null)` rend maintenant.
     *
     * La fenetre d ouverture de la visio, elle, garde une borne declaree :
     * sans elle `is_accessible` serait toujours faux et la visio d une seance
     * locale deviendrait inatteignable. Ce test verrouille la distinction.
     */
    public function test_une_duree_inconnue_n_est_pas_fabriquee(): void
    {
        $this->seanceLocale();
        $etudiant = $this->etudiant();
        $this->fakeKlassciSansRienTrouver();

        $detail = $this->detailPour($etudiant);

        // `$x ?? 'absente'` rend 'absente' quand $x EST nul : l'operateur que
        // j'avais mis par prudence rendait l'assertion incapable de distinguer
        // nul d'absent -- exactement ce qu'elle doit verifier. On teste donc la
        // presence de la cle, puis sa valeur.
        self::assertArrayHasKey('duree_minutes', $detail['seance']);
        self::assertNull(
            $detail['seance']['duree_minutes'],
            'une duree est affirmee alors que la table ne porte pas d heure de fin',
        );
        // Verifier que la fenetre EXISTE ne suffit pas : une fenetre de duree
        // NULLE est non-nulle elle aussi, et rendrait la visio inatteignable.
        // Mesure a l'ecriture : une falsification reduisant la fenetre a zero
        // laissait `assertNotNull` verte. On mesure donc son AMPLITUDE.
        $fenetre = $detail['seance']['visio_window'] ?? [];
        self::assertArrayHasKey('end_window', $fenetre);

        $ouverture = Carbon::parse($fenetre['start_window'])
            ->diffInMinutes(Carbon::parse($fenetre['end_window']));

        self::assertGreaterThanOrEqual(
            60,
            $ouverture,
            'la fenetre d ouverture est trop etroite : la visio d une seance locale serait inatteignable',
        );
    }

    private function etudiant(): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
            'klassci_token' => self::TOKEN,
        ]);
    }

    /** KLASSCI ne trouve rien : le repli local s execute. */
    private function fakeKlassciSansRienTrouver(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyMatieresDetails')->andReturn([]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
            $mock->shouldReceive('getEmploiTemps')->andReturn(['data' => []]);
        });
    }

    /** @return array<string, mixed> */
    private function detailPour(User $acteur): array
    {
        Sanctum::actingAs($acteur);

        return $this->getJson('/api/lms/seances/'.self::KLASSCI_SEANCE_ID.'/details')
            ->assertStatus(200)
            ->json('data') ?? [];
    }

    /**
     * La séance telle qu'elle est réellement en base : ses vraies valeurs sont
     * disponibles, et c'est ce qui rend les inventions injustifiables.
     */
    private function seanceLocale(array $surcharge = []): Seance
    {
        return Seance::factory()->create([...[
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => self::KLASSCI_SEANCE_ID,
            'klassci_matiere_id' => self::KLASSCI_MATIERE_ID,
            'klassci_enseignant_id' => $this->teacher->klassci_id,
            'classe_nom' => self::CLASSE_REELLE,
            'matiere_nom' => 'Anglais',
            'date_seance' => now()->addDays(3)->setTime(14, 0),
            'is_active' => true,
        ], ...$surcharge]);
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        Sanctum::actingAs($this->teacher);

        $reponse = $this->getJson('/api/lms/seances/'.self::KLASSCI_SEANCE_ID.'/details')
            ->assertStatus(200);

        /** @var array<string, mixed> $data */
        $data = $reponse->json('data') ?? [];

        return $data;
    }

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

        $detailsMatiere = ['data' => [
            'matiere' => ['id' => self::KLASSCI_MATIERE_ID, 'nom' => 'Anglais'],
            'seances_programmees' => [],
            'statistiques' => ['seances' => ['total_programmees' => 28]],
        ]];

        $emploiTemps = ['data' => [[
            'id' => self::KLASSCI_SEANCE_ID,
            'matiere' => ['id' => self::KLASSCI_MATIERE_ID, 'nom' => 'Anglais'],
            'classe' => ['id' => 4, 'libelle' => self::CLASSE_REELLE, 'nom' => self::CLASSE_REELLE],
            'programmation' => [
                'date_seance' => now()->addDays(3)->format('Y-m-d'),
                'date' => now()->addDays(3)->format('Y-m-d'),
                'heure_debut' => now()->addDays(3)->setTime(14, 0)->toISOString(),
                'heure_fin' => now()->addDays(3)->setTime(16, 0)->toISOString(),
            ],
            'salle' => ['nom' => 'B12'],
        ]]];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($dashboard, $detailsMatiere, $emploiTemps): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
                ->andReturn($dashboard);

            $mock->shouldReceive('requestWithUserToken')
                ->withArgs(fn (...$a): bool => isset($a[1]) && str_starts_with((string) $a[1], 'matieres/'))
                ->andReturn($detailsMatiere);

            // Le scanner interroge les matieres EN LOT, pas une par une.
            // Omettre cette attente rendait 500 -- une faute de mon simulacre
            // que j'avais d'abord prise pour la preuve du defaut.
            $mock->shouldReceive('fetchManyMatieresDetails')->andReturn([
                self::KLASSCI_MATIERE_ID => $detailsMatiere,
            ]);

            $mock->shouldReceive('getEmploiTemps')->andReturn($emploiTemps);

            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }
}

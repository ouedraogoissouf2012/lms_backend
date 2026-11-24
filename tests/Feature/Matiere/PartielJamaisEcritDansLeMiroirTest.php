<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereClassesResolver;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #758 — un résultat amputé du pool KLASSCI est rendu, mais jamais écrit dans le
 * miroir `classe_matiere`.
 *
 * `fetchManyClassesDetails()` omet les classes en échec (transport épuisé, 4xx/5xx,
 * disjoncteur ouvert en route) : c'est son contrat. La source alimentait le miroir
 * avec les seules classes résolues. Au tour suivant, la jambe miroir répondait non
 * vide, la source KLASSCI ne rejouait plus, et la troncature devenait l'état local.
 *
 * Le contrat du pool est testé tel quel : une classe en échec est ABSENTE des détails.
 * École autonome : cette source n'est jamais appelée (`MatiereClassesFromMirrorTest`).
 */
final class PartielJamaisEcritDansLeMiroirTest extends TestCase
{
    use RefreshDatabase;

    private const MATIERE = 3;

    private Institution $ecole;

    private User $enseignant;

    private Classe $b2;

    private Classe $bts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ecole = Institution::factory()->create();
        app(TenantManager::class)->set($this->ecole);
        $this->enseignant = User::factory()->teacher()->create(['institution_id' => $this->ecole->id, 'klassci_token' => 'jeton-enseignant']);
        $this->b2 = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 1, 'libelle' => 'B2 COM']);
        $this->bts = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 4, 'libelle' => 'BTS']);
        Matiere::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::MATIERE]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        Mockery::close();
        parent::tearDown();
    }

    /** Une classe sur deux manque : la résolue est rendue, rien n'est écrit, et le journal nomme la manquante. */
    public function test_un_resultat_ampute_est_rendu_sans_etre_ecrit(): void
    {
        Log::spy();
        $this->klassci($this->details([1]));

        $classes = $this->resoudre();

        self::assertSame([$this->b2->id], array_column($classes, 'id'));
        self::assertSame(0, DB::table('classe_matiere')->count(), 'Le partiel ne doit pas alimenter le miroir.');
        Log::shouldHaveReceived('warning')
            ->with('Classes de la matière — détails KLASSCI incomplets : miroir non alimenté', Mockery::on(
                static fn (array $contexte): bool => $contexte['manquantes'] === [4] && $contexte['demandees'] === 2 && $contexte['recues'] === 1,
            ))
            ->once();
    }

    /** Après un partiel, le tour suivant rejoue KLASSCI et trouve toutes les classes. */
    public function test_le_tour_suivant_rejoue_klassci(): void
    {
        $this->klassci($this->details([1]), $this->details([1, 4]));

        $this->resoudre();
        $classes = $this->resoudre();

        self::assertEqualsCanonicalizing([$this->b2->id, $this->bts->id], array_column($classes, 'id'));
    }

    /** Verrou : un résultat complet alimente le miroir, comme avant. */
    public function test_un_resultat_complet_alimente_le_miroir(): void
    {
        $this->klassci($this->details([1, 4]));

        $this->resoudre();

        self::assertEqualsCanonicalizing([$this->b2->id, $this->bts->id], DB::table('classe_matiere')->pluck('classe_id')->all());
    }

    /**
     * Le tableau de bord cite les classes 1 et 4 ; chaque appel au pool rend les détails suivants.
     *
     * @param  array<int, mixed>  ...$reponses
     */
    private function klassci(array ...$reponses): void
    {
        $tableauDeBord = ['data' => ['classes' => [['id' => 1, 'name' => 'B2 COM'], ['id' => 4, 'name' => 'BTS']]]];

        $this->mock(KlassciProxyService::class, static function (MockInterface $mock) use ($tableauDeBord, $reponses): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton-enseignant', 'me/teacher-dashboard', 'GET')->andReturn($tableauDeBord);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn(...$reponses);
        });
    }

    /**
     * Les détails du pool pour ces classes, chacune portant la matière.
     *
     * @param  list<int>  $classes
     * @return array<int, mixed>
     */
    private function details(array $classes): array
    {
        $details = [];
        foreach ($classes as $id) {
            $details[$id] = ['data' => ['classe' => ['id' => $id], 'matieres' => [['id' => self::MATIERE, 'libelle' => 'Anglais']]]];
        }

        return $details;
    }

    /** @return array<int, array{id: int, nom: string}> */
    private function resoudre(): array
    {
        return app(MatiereClassesResolver::class)->resolve([], self::MATIERE, $this->ecole->id, $this->enseignant);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereClassesResolver;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L'AMORÇAGE du miroir `classe_matiere`, à la connexion.
 *
 * ## Le trou trouvé en revue adversariale
 *
 * `ClasseMatieresSynchronizer` n'était branché que sur
 * `ClasseSyncService::syncClasseById()`, atteint uniquement depuis une SÉANCE
 * ou une VISIO (`SeanceUpsertService`, `SyncKlassciClasse`, `VisioToggleService`,
 * `VisioActivationService`, `VisioSessionService`).
 *
 * Trois conséquences, toutes mesurables :
 *
 * 1. **Au déploiement la table est vide.** Elle ne se remplit qu'au fil des
 *    séances créées. Aucune migration de rattrapage n'existe.
 * 2. **La matière visée par #740 est justement celle sans séance.** Elle
 *    n'obtenait ses classes que si une AUTRE matière de la même classe avait
 *    produit une séance dans la fenêtre — c'est-à-dire par ricochet.
 * 3. **Une panne KLASSCI ressuscitait le 403.** La jambe « séances » est
 *    enveloppée dans un `catch (Throwable) { return []; }` : KLASSCI
 *    injoignable → zéro séance → et, sans miroir amorcé, zéro classe → le
 *    frontend renvoie `classe_id: null` → 403 « This action is unauthorized ».
 *
 * ## Pourquoi le point d'entrée « connexion », et pourquoi ça ne coûte rien
 *
 * `KlassciClassesFetcher::fetchAllClassesWithDetails()` interroge DÉJÀ
 * `classes/{id}` pour chaque classe — c'est de là qu'il tire `etudiants`. Le
 * bloc `matieres` arrive dans la même réponse et était simplement jeté : le
 * défaut exact de `syncClasseById`, une couche plus haut.
 *
 * L'amorçage ne demande donc **aucun appel réseau supplémentaire**, et le
 * miroir devient indépendant des séances — donc de la disponibilité de KLASSCI
 * au moment où l'enseignant ouvre sa page.
 */
final class ClasseMatieresSeededAtLoginTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * LE trou comblé : le lien existe dès la connexion, sans aucune séance.
     */
    public function test_the_login_sync_seeds_the_classe_matiere_mirror(): void
    {
        $this->fakeKlassci(matieres: [
            ['id' => 3, 'libelle' => 'Anglais'],
            ['id' => 9, 'libelle' => 'Marketing digital'],
        ]);

        app(ClasseSyncService::class)->syncUserClasses('jeton', 'coordinateur');

        $classe = Classe::query()->where('klassci_id', 5)->firstOrFail();

        self::assertSame(2, DB::table('classe_matiere')
            ->where('classe_id', $classe->id)
            ->where('institution_id', $this->institution->id)
            ->count());

        self::assertSame(2, Matiere::query()->count());
    }

    /**
     * Le lien est utilisable immédiatement : une matière SANS aucune séance
     * remonte désormais ses classes — c'est tout l'objet de #740.
     */
    public function test_a_matiere_without_seance_gets_its_classe_right_after_login(): void
    {
        $this->fakeKlassci(matieres: [['id' => 3, 'libelle' => 'Anglais']]);

        app(ClasseSyncService::class)->syncUserClasses('jeton', 'coordinateur');

        $classes = app(MatiereClassesResolver::class)
            ->resolve([], 3, $this->institution->id);

        $classe = Classe::query()->where('klassci_id', 5)->firstOrFail();
        self::assertSame([['id' => $classe->id, 'nom' => 'Terminale S']], $classes);
    }

    /**
     * Un payload sans bloc `matieres` ne doit rien casser : tous les
     * établissements ne l'exposent pas, et l'ancienne forme doit continuer de
     * passer — exactement comme `etudiants`, déjà traité conditionnellement.
     */
    public function test_a_payload_without_matieres_still_syncs_the_classe(): void
    {
        $this->fakeKlassci(matieres: null);

        $stats = app(ClasseSyncService::class)->syncUserClasses('jeton', 'coordinateur');

        self::assertSame(1, $stats['classes_created']);
        self::assertSame(0, DB::table('classe_matiere')->count());
    }

    /**
     * Rejouer la connexion n'empile rien : le lien est idempotent.
     */
    public function test_logging_in_twice_creates_no_duplicate_link(): void
    {
        $this->fakeKlassci(matieres: [['id' => 3, 'libelle' => 'Anglais']], appels: 2);

        app(ClasseSyncService::class)->syncUserClasses('jeton', 'coordinateur');
        app(ClasseSyncService::class)->syncUserClasses('jeton', 'coordinateur');

        self::assertSame(1, DB::table('classe_matiere')->count());
        self::assertSame(1, Matiere::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>|null  $matieres
     */
    private function fakeKlassci(?array $matieres, int $appels = 1): void
    {
        $data = ['classe' => ['id' => 5, 'libelle' => 'Terminale S'], 'etudiants' => []];

        if ($matieres !== null) {
            $data['matieres'] = $matieres;
        }

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($data, $appels): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton', 'classes', 'GET')
                ->times($appels)
                ->andReturn(['data' => [['id' => 5, 'libelle' => 'Terminale S']]]);

            $mock->shouldReceive('fetchManyClassesDetails')
                ->with([5], 'jeton')
                ->times($appels)
                ->andReturn([5 => ['data' => $data]]);
        });
    }
}

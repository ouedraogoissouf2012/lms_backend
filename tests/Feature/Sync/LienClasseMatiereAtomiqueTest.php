<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Services\Sync\Classes\ClasseMatieresSynchronizer;
use App\Services\TenantManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #759 — le lien classe ↔ matière s'écrit en UNE instruction, atomique sous
 * `unique(classe_id, matiere_id)`.
 *
 * `updateOrInsert` faisait `exists()` puis `insert()` : deux écritures concurrentes
 * passaient toutes deux la lecture, et la seconde levait une violation d'unicité. La
 * course est rendue déterministe : un écouteur de requêtes écrit le lien concurrent
 * juste après la lecture, s'il y en a une.
 *
 * Les requêtes sont comparées sans guillemets de moteur : SQLite et MySQL citent
 * différemment.
 */
final class LienClasseMatiereAtomiqueTest extends TestCase
{
    use RefreshDatabase;

    private const MATIERE_KLASSCI = 10;

    private Institution $ecole;

    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ecole = Institution::factory()->create();
        app(TenantManager::class)->set($this->ecole);
        $this->classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 1]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** La course : un concurrent écrit le lien entre une lecture et l'écriture. */
    public function test_un_lien_ecrit_par_un_concurrent_ne_fait_pas_echouer_la_synchronisation(): void
    {
        $fenetre = false;
        DB::listen(function (QueryExecuted $requete) use (&$fenetre): void {
            if ($fenetre || ! str_contains(self::normalise($requete->sql), 'select exists(select * from classe_matiere')) {
                return;
            }
            $fenetre = true;
            DB::table('classe_matiere')->insert($this->ligne($this->matiere()->id, now()->subDay()));
        });

        $this->synchroniser();

        self::assertFalse($fenetre, 'Aucune lecture ne doit précéder l\'écriture du lien.');
        self::assertSame(1, DB::table('classe_matiere')->count());
    }

    /** Une seule requête sur la table : l'écriture, jamais une lecture. */
    public function test_le_lien_s_ecrit_en_une_seule_instruction(): void
    {
        $requetes = [];
        DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
            $sql = self::normalise($requete->sql);
            if (str_contains($sql, 'classe_matiere')) {
                $requetes[] = $sql;
            }
        });

        $this->synchroniser();

        self::assertCount(1, $requetes, implode("\n", $requetes));
        self::assertStringStartsWith('insert into classe_matiere', $requetes[0]);
    }

    /** Verrou : un lien existant n'est pas dupliqué, et son `updated_at` avance. */
    public function test_un_lien_existant_est_rafraichi_sans_doublon(): void
    {
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->synchroniser();
        Carbon::setTestNow('2026-11-23 10:00:00');

        $this->synchroniser();

        self::assertSame(1, DB::table('classe_matiere')->count());
        self::assertSame('2026-11-23 10:00:00', (string) DB::table('classe_matiere')->value('updated_at'));
        Carbon::setTestNow();
    }

    /** Verrou : le lien d'une autre classe n'est pas touché. */
    public function test_le_lien_d_une_autre_classe_reste_intact(): void
    {
        $autre = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 2]);
        $hier = now()->subDay()->startOfSecond();
        DB::table('classe_matiere')->insert(['classe_id' => $autre->id] + $this->ligne($this->matiere()->id, $hier));

        $this->synchroniser();

        self::assertSame(2, DB::table('classe_matiere')->count());
        self::assertSame($hier->toDateTimeString(), (string) DB::table('classe_matiere')->where('classe_id', $autre->id)->value('updated_at'));
    }

    private function synchroniser(): void
    {
        app(ClasseMatieresSynchronizer::class)->sync($this->classe, [['id' => self::MATIERE_KLASSCI, 'nom' => 'Mathématiques']]);
    }

    /** La matière miroir, créée comme la synchronisation la crée. */
    private function matiere(): Matiere
    {
        return Matiere::withoutGlobalScope('institution')->firstOrCreate(
            ['klassci_id' => self::MATIERE_KLASSCI, 'institution_id' => $this->ecole->id],
            ['libelle' => 'Mathématiques'],
        );
    }

    /** @return array<string, mixed> */
    private function ligne(int $matiereId, Carbon $date): array
    {
        return [
            'classe_id' => $this->classe->id,
            'matiere_id' => $matiereId,
            'institution_id' => $this->ecole->id,
            'created_at' => $date,
            'updated_at' => $date,
        ];
    }

    private static function normalise(string $sql): string
    {
        return strtolower(str_replace(['"', '`'], '', $sql));
    }
}

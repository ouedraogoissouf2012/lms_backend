<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * « Mes cours » en un nombre de requêtes qui ne dépend ni de la page ni du
 * volume, et sans hydrater toute la sélection pour bâtir ses filtres (#940).
 *
 * La progression était lue cours par cours (une requête par cours de la page),
 * et `filters` hydratait TOUTE la sélection filtrée, avec matière et classe, pour
 * en tirer deux listes. La forme de la réponse ne change pas.
 */
final class MyCoursesRequetesBorneesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const URL = '/api/lessons/my-courses';

    private User $eleve;

    private Classe $classe;

    /** @var list<Matiere> */
    private array $matieres = [];

    /** @var list<User> */
    private array $enseignants = [];

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_le_nombre_de_requetes_ne_depend_pas_de_la_taille_de_la_page(InstitutionMode $mode): void
    {
        $this->ecole($mode, lecons: 20);

        $parUn = $this->requetes('?per_page=1');
        $parQuinze = $this->requetes('?per_page=15');
        $parCent = $this->requetes('?per_page=100');

        self::assertSame($parUn, $parQuinze, 'Une requête par cours de la page.');
        self::assertSame($parUn, $parCent, 'Une requête par cours de la page.');
    }

    #[DataProvider('volets')]
    public function test_les_filtres_n_hydratent_que_les_lecons_de_la_page(InstitutionMode $mode): void
    {
        $this->ecole($mode, lecons: 60);
        $hydratees = 0;
        Lesson::retrieved(static function () use (&$hydratees): void {
            $hydratees++;
        });

        $this->asTenant($this->eleve)->getJson(self::URL.'?per_page=5')->assertOk();

        self::assertLessThanOrEqual(5, $hydratees, "Toute la sélection a été hydratée ({$hydratees} leçons pour une page de 5).");
    }

    /** La forme ne change pas : mêmes filtres, dans l'ordre d'apparition ; la progression de chaque cours. */
    public function test_les_filtres_et_la_progression_restent_les_memes(): void
    {
        $this->ecole(InstitutionMode::Standalone, lecons: 6);
        $premiere = Lesson::query()->withoutGlobalScopes()->orderBy('order')->firstOrFail();
        LessonProgress::factory()->create([
            'user_id' => $this->eleve->id,
            'lesson_id' => $premiere->id,
            'institution_id' => $premiere->institution_id,
            'status' => 'in_progress',
            'progress_percentage' => 40,
        ]);

        $reponse = $this->asTenant($this->eleve)->getJson(self::URL.'?per_page=2')->assertOk();

        self::assertSame(
            array_map(static fn (Matiere $m): array => ['id' => $m->id, 'name' => $m->libelle], $this->matieres),
            $reponse->json('filters.matieres'),
        );
        self::assertSame(
            array_map(static fn (User $e): array => ['id' => $e->id, 'name' => $e->name], $this->enseignants),
            $reponse->json('filters.enseignants'),
        );
        $reponse->assertJsonPath('data.0.id', $premiere->id)
            ->assertJsonPath('data.0.progress.percentage', 40)
            ->assertJsonPath('data.1.progress', null);
    }

    // ───────────────────────── fixtures

    private function requetes(string $parametres): int
    {
        $client = $this->asTenant($this->eleve);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $client->getJson(self::URL.$parametres)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Une école, un élève inscrit à une classe, et des leçons publiées qui
     * alternent trois matières et deux enseignants, dans un ordre fixé.
     */
    private function ecole(InstitutionMode $mode, int $lecons): void
    {
        $this->disableKlassciMiddleware();
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $this->eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']);
        $this->classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 7001]);
        $this->inscrire($mode, $ecole);

        $this->matieres = [];
        foreach (['Maths', 'Physique', 'Chimie'] as $libelle) {
            $this->matieres[] = Matiere::factory()->create(['institution_id' => $ecole->id, 'libelle' => $libelle]);
        }
        $this->enseignants = [];
        foreach (['Awa Prof', 'Moussa Prof'] as $nom) {
            $this->enseignants[] = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant', 'name' => $nom]);
        }

        for ($i = 0; $i < $lecons; $i++) {
            Lesson::factory()->published()->create([
                'institution_id' => $ecole->id,
                'classe_id' => $this->classe->id,
                'matiere_id' => $this->matieres[$i % 3]->id,
                'enseignant_id' => $this->enseignants[$i % 2]->id,
                'order' => $i,
            ]);
        }
    }

    private function inscrire(InstitutionMode $mode, Institution $ecole): void
    {
        if ($mode === InstitutionMode::Standalone) {
            $this->classe->etudiants()->attach($this->eleve->id, ['statut' => 'actif', 'institution_id' => $ecole->id]);

            return;
        }

        UserClass::create([
            'user_id' => $this->eleve->id,
            'klassci_classe_id' => 7001,
            'classe_nom' => $this->classe->libelle,
            'institution_id' => $ecole->id,
            'synced_at' => now(),
        ]);
    }
}

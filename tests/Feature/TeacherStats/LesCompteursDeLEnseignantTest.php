<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherStats;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Dashboard\TeacherStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1624 — les compteurs de l'enseignant (`GET /api/teacher/stats`) dans les deux volets.
 *
 * Ils valaient quatre zéros pour tout enseignant sans identité KLASSCI, donc pour tout enseignant
 * d'une école autonome (l'accueil enseignant de la démo), et comptaient les leçons sur
 * `enseignant_id` = identité KLASSCI, alors que la colonne porte l'id LOCAL de l'auteur.
 *
 * Vrai jeton (`asTenant`), valeurs non nulles. La COLLISION : un même nombre, id local d'une
 * classe et identifiant KLASSCI d'une autre, ne compte jamais une seule classe.
 */
final class LesCompteursDeLEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_une_ecole_autonome_compte_ce_que_l_enseignant_a_cree(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $prof = $this->enseignant($ecole);
        [$c1, $c2] = [$this->classe($ecole), $this->classe($ecole)];
        $maths = $this->matiere($ecole);
        $this->lecon($prof, $c1, $maths);
        $this->lecon($prof, $c2, $maths);
        Evaluation::factory()->create($this->evaluationLocale($prof, $c1, $maths));

        $collegue = $this->enseignant($ecole);
        $this->lecon($collegue, $this->classe($ecole), $this->matiere($ecole));

        $this->asTenant($prof)->getJson('/api/teacher/stats')->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['matieres' => 1, 'classes' => 2, 'evaluations' => 1, 'lessons' => 2]]);
    }

    public function test_en_ecole_klassci_une_lecon_et_une_evaluation_sur_la_meme_classe_font_une_classe(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $prof = $this->enseignant($ecole, 900);
        $classe = $this->classe($ecole, 7001);
        $matiere = $this->matiere($ecole, 3001);
        $this->lecon($prof, $classe, $matiere);
        Evaluation::factory()->create($this->evaluationKlassci($ecole, 900, 7001, 3001));

        $this->asTenant($prof)->getJson('/api/teacher/stats')->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['matieres' => 1, 'classes' => 1, 'evaluations' => 1, 'lessons' => 1]]);
    }

    /** Le même nombre dans deux espaces : l'id local de A, l'identifiant KLASSCI de B. Deux classes. */
    public function test_un_meme_nombre_dans_deux_espaces_ne_compte_pas_une_seule_classe(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $prof = $this->enseignant($ecole, 900);
        $a = $this->classe($ecole);
        $b = $this->classe($ecole, $a->id);
        $matiere = $this->matiere($ecole, 3001);
        $this->lecon($prof, $a, $matiere);
        Evaluation::factory()->create($this->evaluationKlassci($ecole, 900, $a->id, 3001));

        self::assertNotSame($a->id, $b->id);
        $this->asTenant($prof)->getJson('/api/teacher/stats')->assertOk()->assertJsonPath('data.classes', 2);
    }

    public function test_un_homonyme_klassci_d_une_autre_ecole_ne_compte_pas(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $ailleurs = $this->ecole(InstitutionMode::Klassci);
        $prof = $this->enseignant($ecole, 900);
        $this->classe($ailleurs, 7001);
        Evaluation::factory()->create($this->evaluationKlassci($ailleurs, 900, 7001, 3001));
        $this->lecon($this->enseignant($ailleurs, 900), $this->classe($ailleurs), $this->matiere($ailleurs));

        $this->asTenant($prof)->getJson('/api/teacher/stats')->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['matieres' => 0, 'classes' => 0, 'evaluations' => 0, 'lessons' => 0]]);
    }

    /** La correspondance KLASSCI → local est groupée : autant de SELECT pour 1 évaluation que pour 6. */
    public function test_la_correspondance_ne_coute_pas_une_requete_par_evaluation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 08:00:00', 'UTC'));
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $un = $this->enseignant($ecole, 901);
        $six = $this->enseignant($ecole, 906);
        foreach (range(1, 6) as $n) {
            $this->classe($ecole, 8000 + $n);
            $this->matiere($ecole, 4000 + $n);
            Evaluation::factory()->create($this->evaluationKlassci($ecole, 906, 8000 + $n, 4000 + $n));
        }
        $this->classe($ecole, 9001);
        $this->matiere($ecole, 5001);
        Evaluation::factory()->create($this->evaluationKlassci($ecole, 901, 9001, 5001));

        self::assertSame($this->selects($un), $this->selects($six));
        self::assertSame(6, app(TeacherStatsService::class)->buildStats($six)['classes']);
    }

    /** @var list<string>|null les SELECT relevés, `null` hors relevé */
    private ?array $releve = null;

    private bool $ecoute = false;

    /** @return list<string> les SELECT normalisés (sans valeurs) émis par le calcul */
    private function selects(User $enseignant): array
    {
        if (! $this->ecoute) {
            // UN écouteur pour le test, actif seulement pendant un relevé : retirer les
            // écouteurs de `QueryExecuted` couperait aussi ceux du framework et du harnais.
            DB::listen(function (QueryExecuted $requete): void {
                if ($this->releve !== null && str_starts_with(strtolower(ltrim($requete->sql)), 'select')) {
                    $this->releve[] = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', $requete->sql);
                }
            });
            $this->ecoute = true;
        }

        $compte = $enseignant->fresh() ?? $enseignant;
        $this->releve = [];
        app(TeacherStatsService::class)->buildStats($compte);
        $selects = $this->releve;
        $this->releve = null;

        return $selects;
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        return Institution::factory()->create(['mode' => $mode]);
    }

    private function enseignant(Institution $ecole, ?int $identiteKlassci = null): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id, 'role' => 'enseignant', 'klassci_token' => null,
            'klassci_id' => $identiteKlassci, 'klassci_enseignant_id' => $identiteKlassci,
        ]);
    }

    private function classe(Institution $ecole, ?int $klassciId = null): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $klassciId]);
    }

    private function matiere(Institution $ecole, ?int $klassciId = null): Matiere
    {
        return Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $klassciId]);
    }

    private function lecon(User $auteur, Classe $classe, Matiere $matiere): void
    {
        Lesson::factory()->create([
            'institution_id' => $auteur->institution_id, 'enseignant_id' => $auteur->id,
            'classe_id' => $classe->id, 'matiere_id' => $matiere->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluationLocale(User $auteur, Classe $classe, Matiere $matiere): array
    {
        return [
            'institution_id' => $auteur->institution_id, 'enseignant_id' => $auteur->id,
            'classe_id' => $classe->id, 'matiere_id' => $matiere->id,
            'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_enseignant_id' => null, 'klassci_evaluation_id' => null,
        ];
    }

    /** @return array<string, mixed> une évaluation KLASSCI synchronisée, sans auteur local (avant D72) */
    private function evaluationKlassci(Institution $ecole, int $enseignant, int $classe, int $matiere): array
    {
        return [
            'institution_id' => $ecole->id, 'enseignant_id' => null,
            'klassci_enseignant_id' => $enseignant, 'klassci_classe_id' => $classe, 'klassci_matiere_id' => $matiere,
        ];
    }
}

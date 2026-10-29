<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereLessonsAndStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #941 — la vue « apprenant » est la vue par défaut sur les autres lectures du
 * contenu : seul le personnel reconnu (`isStaff()`) en sort.
 *
 * Même règle que LessonListService, via `User::isLearnerAudience()`, sur :
 * la progression d'une leçon, les leçons d'une matière, la recherche globale
 * et ses suggestions. Les comptes testés hors personnel comprennent des
 * variantes de casse de rôles à privilège (`SuperAdmin`, `Supradmin`,
 * `Enseignant`, `ADMIN`) : aucune ne doit rien gagner.
 *
 * Vrai jeton porteur, deux volets (école klassci : cache `user_classes` ;
 * école standalone : pivot local), sans lire le mode.
 *
 * @see app/Models/Concerns/InteractsWithRoles.php isLearnerAudience()
 */
final class LearnerAudienceSitesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    private Lesson $publiee;

    private Lesson $brouillon;

    private Evaluation $evaluationBrouillon;

    private User $camarade;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function horsPersonnel(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            // '' : le rôle absent que la base admet (`users.role` est NOT NULL).
            foreach (['Etudiant', 'eleve', 'SuperAdmin', 'Supradmin', 'Enseignant', 'ADMIN', ''] as $role) {
                yield "« $role », école $volet" => [$volet, $role];
            }
            yield "témoin « etudiant », école $volet" => [$volet, 'etudiant'];
        }
    }

    #[DataProvider('horsPersonnel')]
    public function test_la_progression_d_une_lecon_ne_rend_que_la_sienne(string $volet, string $role): void
    {
        $compte = $this->ecole($volet, $role);

        $data = $this->asTenant($compte)->getJson("/api/lessons/{$this->publiee->id}/progress")->assertOk()->json('data');

        self::assertIsArray($data);
        self::assertArrayHasKey('user_id', $data, 'une seule progression, la sienne, pas une liste');
        self::assertSame($compte->id, $data['user_id']);
        self::assertStringNotContainsString($this->camarade->email, (string) json_encode($data));
    }

    #[DataProvider('horsPersonnel')]
    public function test_les_lecons_d_une_matiere_excluent_les_brouillons(string $volet, string $role): void
    {
        $compte = $this->ecole($volet, $role);

        $lecons = $this->app->make(MatiereLessonsAndStatsBuilder::class)
            ->buildLessonsAndStats(9100, $compte, [], [], [], [], 0)['lessons'];

        $ids = array_column($lecons, 'id');
        self::assertContains($this->publiee->id, $ids);
        self::assertNotContains($this->brouillon->id, $ids, 'brouillon visible hors personnel');
        // La portée apprenant joint aussi SA progression, et seulement la sienne.
        self::assertArrayHasKey('user_progress', $lecons[0], 'progression de l\'apprenant non jointe');
    }

    #[DataProvider('horsPersonnel')]
    public function test_la_recherche_globale_exclut_les_brouillons(string $volet, string $role): void
    {
        $compte = $this->ecole($volet, $role);

        $resultats = $this->asTenant($compte)->getJson('/api/search?query=algebre&limit=20')->assertOk()->json('results');

        self::assertNotContains($this->brouillon->id, array_column($resultats['lessons'], 'id'), 'leçon brouillon trouvée');
        self::assertNotContains($this->evaluationBrouillon->id, array_column($resultats['evaluations'], 'id'), 'évaluation brouillon trouvée');
        // Fermer ne veut pas dire tout retirer : la leçon publiée reste trouvée.
        self::assertContains($this->publiee->id, array_column($resultats['lessons'], 'id'), 'leçon publiée introuvable');
    }

    #[DataProvider('horsPersonnel')]
    public function test_les_suggestions_excluent_les_brouillons(string $volet, string $role): void
    {
        $compte = $this->ecole($volet, $role);

        $suggestions = $this->asTenant($compte)->getJson('/api/search/suggestions?query=algebre')->assertOk()->json('suggestions');

        self::assertNotContains($this->brouillon->title, $suggestions, 'titre de leçon brouillon suggéré');
        self::assertNotContains($this->evaluationBrouillon->titre, $suggestions, 'titre d\'évaluation brouillon suggéré');
        self::assertContains($this->publiee->title, $suggestions, 'titre de leçon publiée non suggéré');
    }

    /**
     * La vue matière par la route réelle, `GET /lms/matieres/{id}`, vrai jeton.
     * Volet klassci seulement : la route exige un jeton KLASSCI (sans lui, 401,
     * MissingKlassciTokenException) ; la fiche matière d'une école autonome
     * relève de #953.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function horsPersonnelKlassci(): iterable
    {
        foreach (['Etudiant', 'eleve', 'SuperAdmin', 'Supradmin', 'Enseignant', 'ADMIN', '', 'etudiant'] as $role) {
            yield "« $role »" => [$role];
        }
    }

    #[DataProvider('horsPersonnelKlassci')]
    public function test_la_fiche_matiere_par_http_exclut_les_brouillons(string $role): void
    {
        $compte = $this->ecole('klassci', $role);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andReturnUsing(static function (string $jeton, string $endpoint): array {
                return $endpoint === 'matieres/9100'
                    ? ['data' => ['matiere' => ['id' => 9100, 'nom' => 'Algebre'], 'combinaisons' => [], 'enseignants' => [['id' => 1, 'nom' => 'Prof']]]]
                    : ['data' => []];
            });
        });

        $ids = array_column($this->asTenant($compte)->getJson('/api/lms/matieres/9100')->assertOk()->json('data.lessons'), 'id');

        self::assertContains($this->publiee->id, $ids);
        self::assertNotContains($this->brouillon->id, $ids, 'brouillon visible hors personnel');
    }

    /** L'enseignant, personnel, lit aussi la liste des progressions réduite à {id, name}. */
    public function test_l_enseignant_lit_les_progressions_reduites_au_nom(): void
    {
        $compte = $this->ecole('klassci', 'enseignant', inscrire: false);

        $data = $this->asTenant($compte)->getJson("/api/lessons/{$this->publiee->id}/progress")->assertOk()->json('data');

        self::assertCount(1, $data);
        self::assertSame(['id', 'name'], array_keys($data[0]['user']));
    }

    /** @return iterable<string, array{0: string}> */
    public static function personnel(): iterable
    {
        foreach (['coordinateur', 'admin', 'superAdmin'] as $role) {
            yield "personnel « $role »" => [$role];
        }
    }

    /**
     * Le personnel garde sa vue, mais la liste des progressions ne livre plus
     * de chaque élève que `{id, name}`.
     */
    #[DataProvider('personnel')]
    public function test_le_personnel_garde_sa_vue_avec_des_eleves_reduits_au_nom(string $role): void
    {
        $compte = $this->ecole('klassci', $role, inscrire: false);

        $data = $this->asTenant($compte)->getJson("/api/lessons/{$this->publiee->id}/progress")->assertOk()->json('data');
        self::assertCount(1, $data, 'la progression du camarade');
        self::assertSame(['id', 'name'], array_keys($data[0]['user']));
        self::assertSame($this->camarade->id, $data[0]['user']['id']);

        $lecons = $this->app->make(MatiereLessonsAndStatsBuilder::class)
            ->buildLessonsAndStats(9100, $compte, [], [], [], [], 0)['lessons'];
        self::assertContains($this->brouillon->id, array_column($lecons, 'id'));

        $resultats = $this->asTenant($compte)->getJson('/api/search?query=algebre&limit=20')->assertOk()->json('results');
        self::assertContains($this->brouillon->id, array_column($resultats['lessons'], 'id'));
        self::assertContains($this->brouillon->title, $this->asTenant($compte)->getJson('/api/search/suggestions?query=algebre')->json('suggestions'));
    }

    private function ecole(string $volet, string $role, bool $inscrire = true): User
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';

        $this->ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => $standalone ? null : 7001]);
        $matiere = Matiere::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 9100]);

        $attributs = ['institution_id' => $this->ecole->id, 'classe_id' => $classe->id, 'matiere_id' => $matiere->id];
        $this->publiee = Lesson::factory()->published()->create($attributs + ['title' => 'Algebre publiee']);
        $this->brouillon = Lesson::factory()->create($attributs + ['title' => 'Algebre brouillon', 'status' => 'draft']);
        $this->evaluationBrouillon = Evaluation::factory()->create([
            'institution_id' => $this->ecole->id,
            'titre' => 'Algebre controle brouillon',
            'status' => 'brouillon',
        ]);

        $this->camarade = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'etudiant', 'email' => 'camarade@ecole.test']);
        LessonProgress::query()->create(['user_id' => $this->camarade->id, 'lesson_id' => $this->publiee->id, 'institution_id' => $this->ecole->id]);

        $compte = User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => $role,
            'klassci_id' => $standalone ? null : 910001,
            'klassci_token' => $standalone ? null : 'jeton-klassci',
        ]);
        if ($inscrire && $standalone) {
            $classe->etudiants()->attach($compte->id, ['statut' => 'actif']);
        } elseif ($inscrire) {
            UserClass::create([
                'user_id' => $compte->id,
                'klassci_classe_id' => 7001,
                'classe_nom' => $classe->libelle,
                'institution_id' => $this->ecole->id,
                'synced_at' => now(),
            ]);
        }

        return $compte;
    }
}

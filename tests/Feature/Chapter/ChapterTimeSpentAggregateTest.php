<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Matiere;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Chapter\ChapterProgressService;
use App\Services\Chapter\ChapterTimeRecorder;
use App\Services\Lesson\LessonProgressService;
use App\Services\Matiere\MatiereLessonsAndStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #944 — le temps passé d'une leçon est l'agrégat du temps de ses chapitres.
 *
 * Avant #944, `lesson_progress.time_spent_minutes` était un cumul envoyé par le
 * client, toujours 0, pendant que le vrai temps vivait dans
 * `chapter_progress.time_spent_seconds`. Les six lecteurs de l'agrégat
 * affichaient 0. Chaque test ici est vu ROUGE sans le correctif.
 *
 * Les deux volets, par fournisseur de données : école klassci (inscription par
 * le cache `user_classes`) et école standalone (pivot local). Le chemin
 * d'écriture du temps ne lit pas le mode : on le prouve dans les deux.
 *
 * @see app/Services/Chapter/ChapterTimeRecorder.php
 */
final class ChapterTimeSpentAggregateTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    private User $apprenant;

    private Lesson $lecon;

    private Chapter $ch1;

    private Chapter $ch2;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci (cache user_classes)' => ['klassci'];
        yield 'ecole standalone (pivot local)' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_les_six_lecteurs_voient_la_somme_des_chapitres(string $volet): void
    {
        $this->ecoleAvecUneLecon($volet);
        $this->asTenant($this->apprenant)
            ->postJson("/api/lessons/{$this->lecon->id}/progress", ['progress_percentage' => 50])
            ->assertOk();

        // 400 s à la complétion du chapitre 1, 200 s par la route du temps, 300 s
        // à la complétion du chapitre 2 : 900 s, soit 15 minutes.
        $this->ecrire("/api/chapters/{$this->ch1->id}/complete", 400);
        $this->ecrire("/api/chapters/{$this->ch1->id}/time", 200);
        $this->ecrire("/api/chapters/{$this->ch2->id}/complete", 300);

        $this->assertSixLecteurs(15);
    }

    #[DataProvider('volets')]
    public function test_le_temps_d_un_quiz_entre_dans_l_agregat(string $volet): void
    {
        $this->ecoleAvecUneLecon($volet);
        $quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $this->ch1->id,
            'institution_id' => $this->ecole->id,
            'is_active' => true,
            'is_required' => false,
            'passing_score' => 50,
            'shuffle_questions' => false,
            'questions' => [[
                'question' => 'Capitale du Burkina Faso ?',
                'type' => 'single',
                'options' => ['Bobo-Dioulasso', 'Ouagadougou'],
                'correct_answer' => 'Ouagadougou',
                'points' => 1,
            ]],
        ]);

        $this->asTenant($this->apprenant)->postJson("/api/knowledge-checks/{$quiz->id}/start")->assertOk();
        $this->asTenant($this->apprenant)->postJson("/api/knowledge-checks/{$quiz->id}/submit", [
            'answers' => [0 => 'Ouagadougou'],
            'time_spent_seconds' => 180,
        ])->assertOk()->assertJsonPath('data.passed', true);

        self::assertSame(3, $this->agregat());
    }

    public function test_la_remise_a_zero_remet_l_agregat_a_zero(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        $this->ecrire("/api/chapters/{$this->ch1->id}/complete", 600);
        self::assertSame(10, $this->agregat());

        // Aucune route ne porte la remise à zéro (ChapterProgressController le note) :
        // on passe par le service, comme le ferait le contrôleur, avec un admin
        // qui vise l'apprenant.
        $admin = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'admin']);
        $resultat = $this->app->make(ChapterProgressService::class)
            ->resetLessonProgress($this->lecon->id, $admin, $this->apprenant->id);
        self::assertSame(200, $resultat['status']);

        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->apprenant->id)->count());
        self::assertSame(0, $this->agregat());
    }

    public function test_le_temps_envoye_par_le_client_est_ignore(): void
    {
        $this->ecoleAvecUneLecon('standalone');
        $this->ecrire("/api/chapters/{$this->ch1->id}/complete", 120);

        $this->asTenant($this->apprenant)
            ->postJson("/api/lessons/{$this->lecon->id}/progress", ['progress_percentage' => 50, 'time_spent_minutes' => 99])
            ->assertOk()
            ->assertJsonPath('data.time_spent_minutes', 2);

        self::assertSame(2, $this->agregat());
    }

    public function test_une_ligne_absente_est_creee_non_commencee(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        self::assertFalse(LessonProgress::query()->where('user_id', $this->apprenant->id)->exists());

        $this->ecrire("/api/chapters/{$this->ch1->id}/time", 125);

        $ligne = LessonProgress::query()->where('user_id', $this->apprenant->id)->where('lesson_id', $this->lecon->id)->firstOrFail();
        self::assertSame('not_started', $ligne->status);
        self::assertSame(0, $ligne->progress_percentage);
        self::assertSame(2, $ligne->time_spent_minutes);
        self::assertSame($this->ecole->id, $ligne->institution_id);
    }

    /**
     * Chaque écriture recalcule depuis la source : un agrégat faussé, quelle
     * qu'en soit la cause (écriture concurrente, ancien cumul client), redevient
     * juste à l'écriture suivante.
     */
    public function test_un_agregat_fausse_se_corrige_a_l_ecriture_suivante(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        $this->ecrire("/api/chapters/{$this->ch1->id}/complete", 600);
        LessonProgress::query()->where('user_id', $this->apprenant->id)->update(['time_spent_minutes' => 777]);

        $this->ecrire("/api/chapters/{$this->ch1->id}/time", 60);

        self::assertSame(11, $this->agregat());
    }

    /**
     * L'ordre de verrouillage, la partie mesurable de la parade contre
     * l'interblocage : la ligne parente `lesson_progress` est lue verrouillée
     * AVANT l'incrément du chapitre, et la somme APRÈS.
     */
    public function test_la_ligne_parente_est_verrouillee_avant_l_increment(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        $this->ecrire("/api/chapters/{$this->ch1->id}/complete", 60);

        $sql = [];
        DB::listen(function ($requete) use (&$sql): void {
            $sql[] = $this->sansGuillemets($requete->sql);
        });
        $this->app->make(ChapterTimeRecorder::class)->record($this->apprenant->id, $this->ch1->id, 30);

        $parent = $this->premierIndex($sql, fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from lesson_progress ') && str_contains($s, 'limit 1'));
        $increment = $this->premierIndex($sql, fn (string $s) => str_starts_with($s, 'update chapter_progress set time_spent_seconds'));
        $somme = $this->premierIndex($sql, fn (string $s) => str_contains($s, 'sum(time_spent_seconds)'));

        self::assertNotNull($parent, 'lecture de la ligne parente introuvable : '.implode(' | ', $sql));
        self::assertNotNull($increment);
        self::assertNotNull($somme);
        self::assertLessThan($increment, $parent, 'la ligne parente doit être verrouillée AVANT le chapitre');
        self::assertLessThan($somme, $increment, 'la somme doit être lue APRÈS l\'incrément');
        $this->assertVerrouillantSurMysql($sql[$parent]);
        $this->assertVerrouillantSurMysql($sql[$somme]);
    }

    /**
     * Même ordre pour la complétion d'un chapitre : aucune écriture de
     * `chapter_progress` avant le verrou parent. Sinon, le jour où
     * markAsCompleted serait enveloppée dans une transaction, l'ordre
     * deviendrait chapitre → parent, l'inverse de record(), et l'interblocage
     * reviendrait. La sûreté ne doit pas dépendre de l'absence de transaction.
     */
    public function test_la_completion_verrouille_la_ligne_parente_avant_d_ecrire_le_chapitre(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        $this->asTenant($this->apprenant)
            ->postJson("/api/lessons/{$this->lecon->id}/progress", ['progress_percentage' => 10])
            ->assertOk();

        $sql = [];
        DB::listen(function ($requete) use (&$sql): void {
            $sql[] = $this->sansGuillemets($requete->sql);
        });
        $this->ecrire("/api/chapters/{$this->ch1->id}/complete", 45);

        $parent = $this->premierIndex($sql, fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from lesson_progress ') && str_contains($s, 'limit 1'));
        $ecritureChapitre = $this->premierIndex($sql, fn (string $s) => preg_match('/^(insert into|update) chapter_progress /', $s) === 1);

        self::assertNotNull($parent, 'lecture de la ligne parente introuvable : '.implode(' | ', $sql));
        self::assertNotNull($ecritureChapitre);
        self::assertLessThan($ecritureChapitre, $parent, 'markAsCompleted écrit le chapitre AVANT de verrouiller la ligne parente');
        $this->assertVerrouillantSurMysql($sql[$parent]);
    }

    /**
     * #965 — la ligne du chapitre est relue par une lecture verrouillante, après
     * le verrou parent et après sa création, avant l'incrément. Un `firstOrCreate`
     * la lisait sur l'instantané de la transaction : le second de deux tout
     * premiers écrivains simultanés ne voyait pas la ligne du premier, et son
     * INSERT échouait sur la clé unique.
     *
     * Première écriture (ligne absente) : sans le correctif, aucune lecture ne
     * suit l'insertion, ROUGE sur les deux moteurs. Écriture suivante (ligne
     * présente) : la lecture doit être verrouillante, ROUGE sous MySQL.
     */
    public function test_la_ligne_du_chapitre_est_relue_verrouillee_avant_l_increment(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        $recorder = $this->app->make(ChapterTimeRecorder::class);

        $premiere = $this->sqlDe(fn () => $recorder->record($this->apprenant->id, $this->ch1->id, 30));
        $parent = $this->premierIndex($premiere, fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from lesson_progress ') && str_contains($s, 'limit 1'));
        $insertion = $this->premierIndex($premiere, fn (string $s) => preg_match('/^insert (or )?(ignore )?into chapter_progress /', $s) === 1);
        self::assertNotNull($parent, 'lecture de la ligne parente introuvable : '.implode(' | ', $premiere));
        self::assertNotNull($insertion, 'création de la ligne du chapitre introuvable : '.implode(' | ', $premiere));
        self::assertLessThan($insertion, $parent, 'la ligne du chapitre est créée AVANT le verrou parent');
        $this->assertRelueVerrouilleeAvantIncrement($premiere, $insertion);

        $suivante = $this->sqlDe(fn () => $recorder->record($this->apprenant->id, $this->ch1->id, 30));
        $parent = $this->premierIndex($suivante, fn (string $s) => str_starts_with($s, 'select') && str_contains($s, 'from lesson_progress ') && str_contains($s, 'limit 1'));
        self::assertNotNull($parent);
        $this->assertRelueVerrouilleeAvantIncrement($suivante, $parent);

        self::assertSame(60, ChapterProgress::query()->where('user_id', $this->apprenant->id)->where('chapter_id', $this->ch1->id)->value('time_spent_seconds'));
        self::assertSame(1, $this->agregat());
    }

    /** @param list<string> $sql */
    private function assertRelueVerrouilleeAvantIncrement(array $sql, int $apres): void
    {
        $increment = $this->premierIndex($sql, fn (string $s) => str_starts_with($s, 'update chapter_progress set time_spent_seconds'));
        $lecture = null;
        foreach ($sql as $i => $requete) {
            if ($i > $apres && str_starts_with($requete, 'select') && str_contains($requete, 'from chapter_progress ') && str_contains($requete, 'limit 1')) {
                $lecture = $i;
                break;
            }
        }

        self::assertNotNull($increment, 'incrément introuvable : '.implode(' | ', $sql));
        self::assertNotNull($lecture, 'la ligne du chapitre n\'est pas relue avant l\'incrément : '.implode(' | ', $sql));
        self::assertLessThan($increment, $lecture, 'la ligne du chapitre est relue APRÈS l\'incrément');
        $this->assertVerrouillantSurMysql($sql[$lecture]);
    }

    /** @return list<string> */
    private function sqlDe(\Closure $ecriture): array
    {
        $sql = [];
        DB::listen(function ($requete) use (&$sql): void {
            $sql[] = $this->sansGuillemets($requete->sql);
        });
        $ecriture();

        return $sql;
    }

    /**
     * SQL capturé, en minuscules et SANS délimiteurs d'identifiants : SQLite cite
     * entre guillemets doubles, MySQL entre accents graves. Le premier jet de ce
     * test cherchait `"lesson_progress"` et a échoué sur la jambe MySQL de la CI.
     */
    private function sansGuillemets(string $sql): string
    {
        return strtolower(str_replace(['"', '`'], '', $sql));
    }

    /**
     * Sous MySQL, la lecture DOIT être verrouillante. SQLite ignore `FOR UPDATE`
     * (un seul écrivain à la fois par base) : là, seul l'ordre se mesure.
     */
    private function assertVerrouillantSurMysql(string $sql): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            self::assertStringContainsString('for update', $sql, 'lecture non verrouillante sous MySQL : '.$sql);
        }
    }

    /**
     * La seconde écrivaine de la ligne parente, LessonProgressService::
     * updateProgress, ne touche jamais l'agrégat, même avec un modèle périmé
     * chargé AVANT une écriture de temps concurrente.
     */
    public function test_la_mise_a_jour_du_pourcentage_n_ecrase_jamais_l_agregat(): void
    {
        $this->ecoleAvecUneLecon('standalone');
        $this->ecrire("/api/chapters/{$this->ch1->id}/time", 120);
        $perime = LessonProgress::query()->where('user_id', $this->apprenant->id)->firstOrFail();
        self::assertSame(2, $perime->time_spent_minutes);

        $this->ecrire("/api/chapters/{$this->ch1->id}/time", 180); // 300 s : 5 min, écrites ailleurs
        $this->app->make(LessonProgressService::class)->updateProgress($perime, 80);

        self::assertSame(5, $this->agregat(), 'updateProgress a réécrit l\'agrégat depuis un modèle périmé');
        self::assertSame(80, LessonProgress::query()->whereKey($perime->id)->value('progress_percentage'));
    }

    public function test_le_rattrapage_est_idempotent(): void
    {
        $this->ecoleAvecUneLecon('klassci');
        // État d'avant #944 : du temps de chapitre, un agrégat resté à 0.
        ChapterProgress::query()->create([
            'user_id' => $this->apprenant->id,
            'chapter_id' => $this->ch1->id,
            'time_spent_seconds' => 900,
            'institution_id' => $this->ecole->id,
        ]);

        $this->artisan('lessons:recompute-time-spent')
            ->expectsOutputToContain('à corriger : 1')
            ->assertSuccessful();
        self::assertFalse(LessonProgress::query()->where('user_id', $this->apprenant->id)->exists(), 'la simulation n\'écrit rien');

        $this->artisan('lessons:recompute-time-spent --apply')->expectsOutputToContain('corrigés : 1')->assertSuccessful();
        self::assertSame(15, $this->agregat());

        $this->artisan('lessons:recompute-time-spent --apply')->expectsOutputToContain('à corriger : 0')->assertSuccessful();
        self::assertSame(15, $this->agregat());
    }

    private function assertSixLecteurs(int $minutes): void
    {
        $id = $this->lecon->id;
        $tenant = fn () => $this->asTenant($this->apprenant);

        self::assertSame($minutes, $tenant()->getJson("/api/lessons/{$id}/progress")->assertOk()->json('data.time_spent_minutes'), 'GET /lessons/{id}/progress');
        self::assertSame($minutes, $tenant()->getJson("/api/lessons/{$id}")->assertOk()->json('data.user_progress.time_spent_minutes'), 'GET /lessons/{id}');
        self::assertSame($minutes, $tenant()->getJson('/api/lessons')->assertOk()->json('data.data.0.user_progress.time_spent_minutes'), 'GET /lessons');

        $tableau = $tenant()->getJson('/api/dashboard/student')->assertOk();
        self::assertSame($minutes, $tableau->json('data.progression.lessons.total_time_spent_minutes'), 'tableau de bord : total');
        self::assertSame($minutes, $tableau->json('data.ongoing_lessons.0.time_spent_minutes'), 'tableau de bord : leçon en cours');

        $lecons = $this->app->make(MatiereLessonsAndStatsBuilder::class)
            ->buildLessonsAndStats(9100, $this->apprenant, [], [], [], [], 0)['lessons'];
        self::assertSame($minutes, $lecons[0]['user_progress']['time_spent_minutes'] ?? null, 'GET /lms/matieres/{id}');
    }

    private function ecoleAvecUneLecon(string $volet): void
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';

        $this->ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => $standalone ? null : 7001]);
        $matiere = Matiere::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 9100]);
        $enseignant = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant']);
        $this->apprenant = User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => 'etudiant',
            'klassci_id' => $standalone ? null : 910001,
        ]);

        if ($standalone) {
            $classe->etudiants()->attach($this->apprenant->id, ['statut' => 'actif']);
        } else {
            UserClass::create([
                'user_id' => $this->apprenant->id,
                'klassci_classe_id' => 7001,
                'classe_nom' => $classe->libelle,
                'institution_id' => $this->ecole->id,
                'synced_at' => now(),
            ]);
        }

        $this->lecon = Lesson::factory()->published()->create([
            'institution_id' => $this->ecole->id,
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
            'enseignant_id' => $enseignant->id,
        ]);
        foreach ([0 => 'ch1', 1 => 'ch2'] as $ordre => $nom) {
            $this->{$nom} = Chapter::factory()->create([
                'lesson_id' => $this->lecon->id,
                'institution_id' => $this->ecole->id,
                'enseignant_id' => $enseignant->id,
                'content_type' => 'text',
                'order' => $ordre,
            ]);
        }
    }

    private function ecrire(string $route, int $secondes): void
    {
        $this->asTenant($this->apprenant)->postJson($route, ['time_spent_seconds' => $secondes])->assertOk();
    }

    private function agregat(): int
    {
        return (int) LessonProgress::query()
            ->where('user_id', $this->apprenant->id)
            ->where('lesson_id', $this->lecon->id)
            ->value('time_spent_minutes');
    }

    /** @param list<string> $sql */
    private function premierIndex(array $sql, \Closure $critere): ?int
    {
        foreach ($sql as $i => $requete) {
            if ($critere($requete)) {
                return $i;
            }
        }

        return null;
    }
}

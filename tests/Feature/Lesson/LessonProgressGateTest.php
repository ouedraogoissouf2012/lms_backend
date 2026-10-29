<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #934 — qui lit et qui écrit la progression d'une leçon.
 *
 * - Écrire : l'apprenant doit pouvoir lire la leçon (même règle que les
 *   chapitres, ChapterReadGate). Sinon 404, et AUCUNE ligne n'est créée :
 *   c'est vérifié dans la BASE, et pas seulement sur le code HTTP. Le personnel
 *   n'écrit pas de progression (403), sauf l'aperçu d'un quiz depuis l'écran
 *   de gestion des chapitres.
 * - Lire la liste des progressions : l'auteur de la leçon et les gestionnaires.
 *
 * L'état de production est reproduit : l'inscription passe par le pivot
 * `classe_etudiant`, sans aucune ligne `user_classes`, dans les deux volets.
 * Vrai jeton porteur.
 *
 * @see app/Services/Lesson/LessonProgressGate.php
 */
final class LessonProgressGateTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private User $eleve;

    private User $auteur;

    private Lesson $sienne;

    private Lesson $autreClasse;

    private Lesson $brouillon;

    /** @var array<string, Chapter> */
    private array $chapitres = [];

    /** @var array<string, KnowledgeCheck> */
    private array $quiz = [];

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'école klassci (pivot, aucun cache user_classes)' => ['klassci'];
        yield 'école autonome (pivot)' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_l_eleve_inscrit_ecrit_sa_progression_par_les_six_chemins(string $volet): void
    {
        $this->ecole($volet);

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            $this->asTenant($this->eleve)->postJson($url, $corps)->assertOk();
        }

        self::assertSame(1, LessonProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(1, ChapterProgress::query()->where('user_id', $this->eleve->id)->count());
        self::assertSame(1, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->count());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function ciblesInterdites(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            yield "leçon d'une autre classe, école $volet" => [$volet, 'autreClasse'];
            yield "brouillon de sa classe, école $volet" => [$volet, 'brouillon'];
        }
    }

    #[DataProvider('ciblesInterdites')]
    public function test_hors_de_ce_qu_il_lit_rien_n_est_ecrit(string $volet, string $cible): void
    {
        $this->ecole($volet);

        $this->asTenant($this->eleve)->getJson("/api/lessons/{$this->{$cible}->id}/progress")->assertNotFound();
        foreach ($this->ecritures($cible) as $chemin => [$url, $corps]) {
            self::assertSame(404, $this->asTenant($this->eleve)->postJson($url, $corps)->status(), $chemin);
        }

        self::assertSame(0, LessonProgress::query()->where('user_id', $this->eleve->id)->count(), 'lesson_progress');
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->eleve->id)->count(), 'chapter_progress');
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->count(), 'tentatives');
    }

    public function test_le_personnel_n_ecrit_pas_de_progression(): void
    {
        $this->ecole('klassci');

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            if (str_starts_with($chemin, 'quiz')) {
                continue;
            }
            self::assertSame(403, $this->asTenant($this->auteur)->postJson($url, $corps)->status(), $chemin);
        }

        self::assertSame(0, LessonProgress::query()->where('user_id', $this->auteur->id)->count());
        self::assertSame(0, ChapterProgress::query()->where('user_id', $this->auteur->id)->count());
    }

    /** L'écran /teacher/lessons/:id/chapters joue le quiz en aperçu : il démarre et soumet. */
    public function test_l_enseignant_garde_l_apercu_du_quiz(): void
    {
        $this->ecole('klassci');

        foreach ($this->ecritures('sienne') as $chemin => [$url, $corps]) {
            if (str_starts_with($chemin, 'quiz')) {
                $this->asTenant($this->auteur)->postJson($url, $corps)->assertOk();
            }
        }
    }

    public function test_la_liste_des_progressions_est_reservee_a_l_auteur_et_aux_gestionnaires(): void
    {
        $this->ecole('klassci');
        $ecole = $this->sienne->institution_id;
        $coEnseignant = User::factory()->create(['institution_id' => $ecole, 'role' => 'enseignant']);
        $coordinateur = User::factory()->create(['institution_id' => $ecole, 'role' => 'coordinateur']);

        $this->asTenant($this->auteur)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertOk();
        $this->asTenant($coordinateur)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertOk();
        // Effet voulu : un co-enseignant ne lit pas les progressions de la leçon d'un collègue.
        $this->asTenant($coEnseignant)->getJson("/api/lessons/{$this->sienne->id}/progress")->assertForbidden();
    }

    /**
     * Les six chemins d'écriture vers la leçon `$cible` et son chapitre.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function ecritures(string $cible): array
    {
        $lecon = $this->{$cible};
        $chapitre = $this->chapitres[$cible];
        $quiz = $this->quiz[$cible];

        return [
            'progression' => ["/api/lessons/{$lecon->id}/progress", ['progress_percentage' => 30]],
            'complétion' => ["/api/lessons/{$lecon->id}/complete", []],
            'note' => ["/api/lessons/{$lecon->id}/rating", ['rating' => 4]],
            'temps du chapitre' => ["/api/chapters/{$chapitre->id}/time", ['time_spent_seconds' => 60]],
            'complétion du chapitre' => ["/api/chapters/{$chapitre->id}/complete", ['time_spent_seconds' => 30]],
            'quiz : démarrage' => ["/api/knowledge-checks/{$quiz->id}/start", []],
            'quiz : soumission' => ["/api/knowledge-checks/{$quiz->id}/submit", ['answers' => ['4', 'True'], 'time_spent_seconds' => 20]],
        ];
    }

    private function ecole(string $volet): void
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';
        $ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $classeA = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $standalone ? null : 7001]);
        $classeB = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $standalone ? null : 7002]);

        $this->auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);
        $this->eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'klassci_id' => $standalone ? null : 910001]);
        $classeA->etudiants()->attach($this->eleve->id, ['statut' => 'actif']);

        $base = ['institution_id' => $ecole->id, 'enseignant_id' => $this->auteur->id];
        $this->sienne = Lesson::factory()->published()->create($base + ['classe_id' => $classeA->id]);
        $this->autreClasse = Lesson::factory()->published()->create($base + ['classe_id' => $classeB->id]);
        $this->brouillon = Lesson::factory()->create($base + ['classe_id' => $classeA->id, 'status' => 'draft']);

        foreach (['sienne', 'autreClasse', 'brouillon'] as $nom) {
            $this->chapitres[$nom] = Chapter::factory()->create([
                'lesson_id' => $this->{$nom}->id,
                'institution_id' => $ecole->id,
                'enseignant_id' => $this->auteur->id,
                'content_type' => 'text',
                'order' => 0,
            ]);
            $this->quiz[$nom] = KnowledgeCheck::factory()->create([
                'chapter_id' => $this->chapitres[$nom]->id,
                'institution_id' => $ecole->id,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La date de fin d'un chapitre est celle de sa PREMIÈRE complétion (#955).
 *
 * Revalider un chapitre (`POST /chapters/{id}/complete`) ou réussir de nouveau
 * son quiz ne la déplace pas : un relevé daté (#717) lit une date stable.
 * La règle ne lit pas le mode de l'école : les deux volets.
 */
final class DateDeFinDuChapitreTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private User $eleve;

    private Chapter $texte;

    private Chapter $chapitreDuQuiz;

    private KnowledgeCheck $quiz;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_revalider_un_chapitre_ne_deplace_pas_sa_date_de_fin(InstitutionMode $mode): void
    {
        $this->lecon($mode);
        $this->asTenant($this->eleve)->postJson("/api/chapters/{$this->texte->id}/complete")->assertOk();
        $premiere = $this->dateDeFin($this->texte);

        $this->travel(1)->days();
        $this->asTenant($this->eleve)->postJson("/api/chapters/{$this->texte->id}/complete")->assertOk();

        self::assertNotNull($premiere);
        self::assertSame($premiere, $this->dateDeFin($this->texte), 'La revalidation a déplacé la date de fin.');
    }

    #[DataProvider('volets')]
    public function test_reussir_de_nouveau_le_quiz_ne_deplace_pas_la_date_de_fin(InstitutionMode $mode): void
    {
        $this->lecon($mode);
        $this->reussirLeQuiz();
        $premiere = $this->dateDeFin($this->chapitreDuQuiz);

        $this->travel(1)->days();
        $this->reussirLeQuiz();

        self::assertNotNull($premiere);
        self::assertSame($premiere, $this->dateDeFin($this->chapitreDuQuiz));
    }

    #[DataProvider('volets')]
    public function test_completer_apres_le_quiz_garde_la_date_du_quiz(InstitutionMode $mode): void
    {
        $this->lecon($mode);
        // La progression est séquentielle : le chapitre du quiz suit le texte.
        $this->asTenant($this->eleve)->postJson("/api/chapters/{$this->texte->id}/complete")->assertOk();
        $this->reussirLeQuiz();
        $premiere = $this->dateDeFin($this->chapitreDuQuiz);

        $this->travel(1)->days();
        $this->asTenant($this->eleve)->postJson("/api/chapters/{$this->chapitreDuQuiz->id}/complete")->assertOk();

        self::assertNotNull($premiere);
        self::assertSame($premiere, $this->dateDeFin($this->chapitreDuQuiz), 'La complétion a déplacé la date du quiz.');
    }

    // ───────────────────────── fixtures

    private function lecon(InstitutionMode $mode): void
    {
        $this->disableKlassciMiddleware();
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $enseignant = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);
        $this->eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']);

        $classe = Classe::factory()->create(['institution_id' => $ecole->id]);
        $classe->etudiants()->attach($this->eleve->id, ['statut' => 'actif']);

        $lecon = Lesson::factory()->create([
            'institution_id' => $ecole->id,
            'enseignant_id' => $enseignant->id,
            'classe_id' => $classe->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $this->texte = $this->chapitre($lecon, 'text', 0);
        $this->chapitreDuQuiz = $this->chapitre($lecon, 'quiz', 1);
        $this->quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $this->chapitreDuQuiz->id,
            'institution_id' => $ecole->id,
            'is_active' => true,
            'is_required' => true,
            'passing_score' => 70,
            'shuffle_questions' => false,
            'questions' => [[
                'question' => 'Quelle est la capitale du Burkina Faso ?',
                'type' => 'single',
                'options' => ['Bobo-Dioulasso', 'Ouagadougou'],
                'correct_answer' => 1,
                'points' => 1,
            ]],
        ]);
    }

    private function chapitre(Lesson $lecon, string $type, int $ordre): Chapter
    {
        return Chapter::factory()->create([
            'lesson_id' => $lecon->id,
            'institution_id' => $lecon->institution_id,
            'enseignant_id' => $lecon->enseignant_id,
            'content_type' => $type,
            'order' => $ordre,
        ]);
    }

    private function reussirLeQuiz(): void
    {
        $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz->id}/start")->assertOk();
        $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz->id}/submit", [
            'answers' => [0 => 1],
            'time_spent_seconds' => 30,
        ])->assertOk()->assertJsonPath('data.passed', true);
    }

    private function dateDeFin(Chapter $chapitre): ?string
    {
        $date = ChapterProgress::query()
            ->where('user_id', $this->eleve->id)
            ->where('chapter_id', $chapitre->id)
            ->value('completed_at');

        return $date === null ? null : (string) $date;
    }
}

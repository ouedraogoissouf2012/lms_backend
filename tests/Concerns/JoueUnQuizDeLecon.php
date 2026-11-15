<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Un élève inscrit, et le quiz du chapitre d'une leçon publiée qu'il lit, dans
 * l'un ou l'autre type d'école. L'inscription passe par le pivot
 * `classe_etudiant`, comme en production. Vrai jeton (`asTenant`).
 *
 * Requiert {@see ActsAsTenantUser}.
 */
trait JoueUnQuizDeLecon
{
    protected User $eleve;

    /** L'enseignant auteur de la leçon : il gère le quiz du chapitre. */
    protected User $auteur;

    protected Chapter $chapitre;

    protected KnowledgeCheck $quiz;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'école KLASSCI' => ['klassci'];
        yield 'école autonome' => ['standalone'];
    }

    /** @param  array<string, mixed>  $attributs  ceux du quiz */
    protected function ecoleEtQuiz(string $volet, array $attributs = []): void
    {
        $this->disableKlassciMiddleware();
        $autonome = $volet === 'standalone';
        $ecole = $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $autonome ? null : 7001]);
        $this->auteur = $auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);
        $this->eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'klassci_id' => $autonome ? null : 910001]);
        $classe->etudiants()->attach($this->eleve->id, ['statut' => 'actif']);

        $lecon = Lesson::factory()->published()->create([
            'institution_id' => $ecole->id, 'enseignant_id' => $auteur->id, 'classe_id' => $classe->id,
        ]);
        $this->chapitre = $chapitre = Chapter::factory()->create([
            'lesson_id' => $lecon->id, 'institution_id' => $ecole->id, 'enseignant_id' => $auteur->id,
            'content_type' => 'quiz', 'order' => 0,
        ]);
        $this->quiz = KnowledgeCheck::factory()->create(array_merge([
            'chapter_id' => $chapitre->id,
            'institution_id' => $ecole->id,
            'max_attempts' => null,
            'show_correct_answers' => true,
            'show_explanation' => true,
        ], $attributs));
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    protected function demarrer(): TestResponse
    {
        return $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz->id}/start")->assertOk();
    }

    /**
     * @param  array<int|string, mixed>  $reponses
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function soumettre(array $reponses): TestResponse
    {
        return $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz->id}/submit", [
            'answers' => $reponses,
            'time_spent_seconds' => 30,
        ]);
    }
}

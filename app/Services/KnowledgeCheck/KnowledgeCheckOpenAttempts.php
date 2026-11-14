<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Exceptions\BusinessException;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\User;
use App\Rules\OptionIndexAnswer;

/**
 * C44 — la tentative OUVERTE d'un quiz de leçon : elle naît au démarrage avec sa
 * présentation ({@see KnowledgeCheckPresentation}), et sa remise est notée dans
 * l'ordre que l'élève a vu.
 *
 * Une tentative ouverte par élève et par quiz : un redémarrage la reprend et lui
 * donne une nouvelle présentation. Limites déclarées :
 * - deux démarrages simultanés peuvent en créer deux ; la remise complète la
 *   plus récente, l'autre reste ouverte, hors de tout décompte ;
 * - deux onglets : le dernier démarrage gagne, car la remise ne dit pas de quel
 *   démarrage elle vient (dette tracée : un `attempt_id` optionnel à la remise).
 *
 * Les écritures se font dans la transaction de l'appelant
 * ({@see KnowledgeCheckAttemptService}), qui seule tient le verrou.
 */
final class KnowledgeCheckOpenAttempts
{
    public const QUIZ_MODIFIE = 'quiz_changed_since_start';

    public const HORS_OPTIONS = 'answer_not_an_option_index';

    public const PAS_OUVERTE = 'attempt_not_open';

    public function __construct(
        private readonly KnowledgeCheckPresentation $presentation,
        private readonly KnowledgeCheckGradingService $grader,
    ) {
    }

    /**
     * Ouvre (ou reprend) la tentative de l'élève, et tire sa présentation.
     *
     * @return array{attempt: KnowledgeCheckAttempt, questions: list<array<string, mixed>>}  les questions dans l'ordre affiché
     */
    public function ouvrir(KnowledgeCheck $quiz, User $user): array
    {
        /** @var array<int|string, mixed> $questions */
        $questions = $quiz->questions ?? [];
        $presentation = $this->presentation->tirer($questions, (bool) $quiz->shuffle_questions, (bool) $quiz->shuffle_options);
        $attributs = ['presentation' => $presentation, 'started_at' => now()];

        $attempt = $this->verrouiller($quiz, $user);
        if ($attempt instanceof KnowledgeCheckAttempt) {
            $attempt->update($attributs);
        } else {
            // Sans note ni remise : `score` nul, les compteurs à leur défaut.
            $attempt = KnowledgeCheckAttempt::create($attributs + [
                'knowledge_check_id' => $quiz->id,
                'user_id' => $user->id,
                'institution_id' => $user->institution_id,
                'total_questions' => count($questions),
            ]);
        }

        return ['attempt' => $attempt, 'questions' => $this->presentation->presenter($questions, $presentation)];
    }

    /** La tentative ouverte la plus récente, verrouillée jusqu'à la fin de la transaction. */
    public function verrouiller(KnowledgeCheck $quiz, User $user): ?KnowledgeCheckAttempt
    {
        return KnowledgeCheckAttempt::query()
            ->where('knowledge_check_id', $quiz->id)
            ->where('user_id', $user->id)
            ->open()
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Note la remise, traduite depuis l'ordre affiché, et rend le détail dans
     * l'ordre affiché. Sans tentative ouverte (démarrée avant C44, ou remise une
     * seconde fois) : sans mélange, l'ordre affiché est l'ordre enregistré, et
     * la remise est notée comme avant ; avec un mélange, ce que l'élève a vu est
     * inconnu, et la remise est refusée (D520) plutôt que notée de travers.
     *
     * @param  array<int|string, mixed>  $answers  dans l'ordre affiché
     * @return array{
     *     result: array{correct_answers: int, total_questions: int, score: int, passed: bool, detailed_answers: array<int, array<string, mixed>>},
     *     affiche: list<array<string, mixed>>
     * }
     *
     * @throws BusinessException 409 `attempt_not_open` ou `quiz_changed_since_start`, 422 `answer_not_an_option_index`
     */
    public function noter(KnowledgeCheck $quiz, ?KnowledgeCheckAttempt $attempt, array $answers): array
    {
        if ($attempt === null) {
            if ($quiz->shuffle_questions || $quiz->shuffle_options) {
                throw new BusinessException('Cette tentative n\'est plus ouverte : recommencez le quiz.', 409, reason: self::PAS_OUVERTE);
            }
            $result = $this->grader->gradeAttempt($quiz, $answers);

            return ['result' => $result, 'affiche' => array_values($result['detailed_answers'])];
        }

        /** @var array<int|string, mixed> $questions */
        $questions = $quiz->questions ?? [];
        $presentation = KnowledgeCheckPresentation::relire($attempt->presentation);
        if ($presentation === null || ! $this->presentation->concorde($questions, $presentation)) {
            throw new BusinessException('Ce quiz a été modifié depuis votre démarrage : recommencez-le.', 409, reason: self::QUIZ_MODIFIE);
        }

        $origine = $this->presentation->versOrigine($presentation, $answers);
        if ($origine === null) {
            throw new BusinessException(OptionIndexAnswer::MESSAGE, 422, reason: self::HORS_OPTIONS);
        }

        $result = $this->grader->gradeAttempt($quiz, $origine);

        return [
            'result' => $result,
            'affiche' => $this->presentation->versAffiche($presentation, array_values($result['detailed_answers']), $answers),
        ];
    }

    /**
     * Complète la tentative ouverte. Sans elle (démarrée avant C44, ou remise une
     * seconde fois), une tentative est créée remise d'emblée, et `started_at`
     * déduit du temps déclaré, comme avant. Les réponses sont gardées dans
     * l'ordre ENREGISTRÉ, à côté de la présentation qui les relie à l'écran.
     *
     * @param  array{correct_answers: int, total_questions: int, score: int, passed: bool, detailed_answers: array<int, array<string, mixed>>}  $result
     */
    public function completer(
        ?KnowledgeCheckAttempt $ouverte,
        KnowledgeCheck $quiz,
        User $user,
        array $result,
        int $timeSpentSeconds
    ): KnowledgeCheckAttempt {
        $attempt = $ouverte ?? new KnowledgeCheckAttempt([
            'knowledge_check_id' => $quiz->id,
            'user_id' => $user->id,
            'institution_id' => $user->institution_id,
            'started_at' => now()->subSeconds($timeSpentSeconds),
        ]);

        $attempt->fill([
            'score' => $result['score'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'answers' => $result['detailed_answers'],
            'time_spent_seconds' => $timeSpentSeconds,
            'passed' => $result['passed'],
            'completed_at' => now(),
        ])->save();

        return $attempt;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Logique de démarrage d'une tentative d'évaluation et lecture de sa
 * fenêtre temporelle — extrait de `EvaluationStudentController`
 * (startEvaluation + getTimeStatus) lors du split §5.
 *
 * Toutes les vérifications business (portée de l'élève, fenêtre, tentatives
 * max, mode entraînement) sont centralisées ici. Le caller map les `status`
 * en codes HTTP. La fenêtre est lue par {@see EvaluationWindowGate}, depuis la
 * source du type d'école (KLASSCI, ou calculée sur la ligne : D72, E2b).
 *
 * L'élève n'atteint que ce que SA liste lui propose : la portée
 * ({@see LearnerEvaluationScope}) est résolue AVANT la lecture. La remise
 * (`findOpenAttempt`) ne la relit pas : elle ne clôt qu'une tentative que le
 * démarrage a admise, et ne dépend ainsi d'aucun appel KLASSCI au moment où
 * l'élève rend sa copie.
 */
final class EvaluationAttemptStateService
{
    public function __construct(
        private readonly EvaluationWindowGate $windowGate,
        private readonly LearnerEvaluationScope $scope,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Tente de démarrer (ou reprendre) une soumission pour l'étudiant.
     *
     * Statuts possibles :
     *   - ok                 → tentative démarrée / reprise (200 ou 201)
     *   - not_found          → hors de la liste de l'élève : absente, d'une autre
     *                          classe ou d'une autre école, non publiée, ou sans
     *                          question (404, sans distinction)
     *   - no_token           → école créée depuis KLASSCI seulement : user sans
     *                          klassci_token (401)
     *   - window_closed      → fenêtre temporelle fermée hors mode entraînement (403)
     *   - window_check_failed → fenêtre non vérifiable (KLASSCI indisponible) hors entraînement (503)
     *   - max_attempts       → quota de tentatives atteint hors entraînement (403)
     *   - retake_forbidden   → allow_retake=false et une tentative déjà consommée (403)
     *   - offline_only       → is_online=false, pas de passage LMS (403)
     *   - attempt_conflict   → course perdue entre deux démarrages, et la
     *                          tentative gagnante est déjà remise (409)
     *
     * @return array{status: string, submission?: EvaluationSubmission, window?: ?array<string, mixed>, message?: string, is_practice?: bool, resumed?: bool}
     *
     * @throws RuntimeException  KLASSCI injoignable ou en erreur en lisant la
     *                           classe de l'élève (le caller la classe).
     */
    public function startAttempt(int $evaluationId, User $user): array
    {
        // Le refus avant lecture d'abord (le jeton, dans une école créée
        // depuis KLASSCI) : son absence ne dit rien de l'évaluation. Une école
        // créée depuis le LMS n'en exige aucun (D72, E2b).
        $refus = $this->windowGate->refusAvantLecture($user);
        if ($refus !== null) {
            return $refus;
        }

        $evaluation = $this->visibleEvaluation($evaluationId, $user);
        if ($evaluation === null) {
            return ['status' => 'not_found'];
        }

        $klassciEtudiantId = $user->klassci_id;
        $isPracticeMode = $evaluation->isTerminee();

        if (! $isPracticeMode && $evaluation->is_online === false) {
            return [
                'status' => 'offline_only',
                'message' => 'Cette évaluation ne se passe pas en ligne.',
            ];
        }

        [$windowError, $window] = $this->windowGate->resolveWindowGate(
            $user,
            $evaluation,
            $isPracticeMode,
            $evaluationId,
            $klassciEtudiantId
        );
        if ($windowError !== null) {
            return $windowError;
        }

        return $this->resolveOrCreateSubmission($evaluation, $user, $isPracticeMode, $window);
    }

    /**
     * La tentative OUVERTE de cet élève sur cette évaluation, s'il en a une.
     *
     * Une copie se rend ; elle ne s'invente pas au moment de la rendre. C'est
     * ici que `/submit` retrouve ce que `/start` a créé — par la même portée de
     * propriété, ce qui rend impossible la divergence d'espaces qui faisait
     * échouer toute remise de copie.
     */
    public function findOpenAttempt(int $evaluationId, User $student): ?EvaluationSubmission
    {
        return EvaluationSubmission::query()
            ->where('evaluation_id', $evaluationId)
            ->ownedBy($student)
            ->where('status', 'en_cours')
            ->first();
    }

    /**
     * Reprend la tentative `en_cours` de l'étudiant si elle existe, sinon
     * applique le quota `max_attempts` (hors entraînement) puis crée une nouvelle
     * soumission. Extrait de `startAttempt` pour respecter §1.1 (≤40 lignes).
     *
     * @param  ?array<string, mixed>  $window
     * @return array{status: string, submission?: EvaluationSubmission, window?: ?array<string, mixed>, message?: string, is_practice?: bool, resumed?: bool}
     */
    private function resolveOrCreateSubmission(Evaluation $evaluation, User $user, bool $isPracticeMode, ?array $window): array
    {
        $activeSubmission = $this->findOpenAttempt($evaluation->id, $user);

        if ($activeSubmission) {
            return $this->okResponse($activeSubmission, $window, true, $isPracticeMode);
        }

        $attemptsCount = EvaluationSubmission::query()
            ->where('evaluation_id', $evaluation->id)
            ->ownedBy($user)
            ->whereIn('status', ['soumis', 'corrige'])
            ->count();

        if (! $isPracticeMode && $attemptsCount > 0 && $evaluation->allow_retake === false) {
            return [
                'status' => 'retake_forbidden',
                'message' => 'Les reprises ne sont pas autorisées pour cette évaluation.',
            ];
        }

        if (!$isPracticeMode && $evaluation->max_attempts && $attemptsCount >= $evaluation->max_attempts) {
            return [
                'status' => 'max_attempts',
                'message' => 'Nombre maximum de tentatives atteint (' . $evaluation->max_attempts . ')',
            ];
        }

        return $this->creer($evaluation, $user, $attemptsCount + 1, $isPracticeMode, $window);
    }

    /**
     * Crée la tentative. Deux `/start` simultanés comptent le même nombre de
     * tentatives et créent le même numéro : l'unique des copies
     * (`eval_sub_unique`, `eval_sub_student_unique`) arbitre, sans verrou
     * (D72, E2b). Le perdant reprend la tentative ouverte de la requête
     * gagnante, relue par la même propriété (`ownedBy`) ; si elle a déjà été
     * remise, il reçoit un refus motivé, jamais un 500 ni la reprise d'une
     * copie remise. Seul le conflit d'unicité est attrapé, et seulement quand
     * la ligne en conflit est une copie de CET élève : une ligne que `ownedBy`
     * ne voit pas (copie héritée sans établissement, avant le rattrapage
     * `2026_10_29_200000`) est une anomalie de données, et l'exception remonte
     * pour être rapportée. Toute autre erreur de base remonte, comme la
     * violation d'un AUTRE unique ({@see self::surUnUniqueDesCopies()}).
     *
     * ## Hypothèse : aucune transaction autour de `/start`
     *
     * La relecture de la copie gagnante suppose qu'AUCUNE transaction
     * n'englobe ce chemin (c'est le cas : aucun `DB::transaction` sur `/start`).
     * Sous REPEATABLE READ (le défaut d'InnoDB), une transaction ouverte avant le
     * conflit relirait l'instantané pris avant lui et ne verrait pas la ligne
     * gagnante : le perdant tomberait dans la branche « anomalie », en 500. Ne
     * pas ajouter de transaction ici sans revoir cette reprise.
     *
     * @param  ?array<string, mixed>  $window
     * @return array{status: string, submission?: EvaluationSubmission, window?: ?array<string, mixed>, message?: string, is_practice?: bool, resumed?: bool}
     */
    private function creer(Evaluation $evaluation, User $user, int $tentative, bool $isPracticeMode, ?array $window): array
    {
        try {
            $submission = EvaluationSubmission::create([
                'evaluation_id' => $evaluation->id,
                // Les DEUX espaces sont écrits, et chacun pour sa raison :
                // `student_id` porte la propriété (EvaluationSubmission::
                // scopeOwnedBy, unique `eval_sub_student_unique`, D72) ;
                // `klassci_etudiant_id`, nul dans une école créée depuis le LMS,
                // sert l'unique `eval_sub_unique` et le renvoi des notes vers KLASSCI.
                'student_id' => $user->id,
                'klassci_etudiant_id' => $user->klassci_id,
                'attempt' => $tentative,
                'status' => 'en_cours',
                'started_at' => now(),
                // Scope tenant explicite herite de l'evaluation (fix E2E #211).
                'institution_id' => $evaluation->institution_id,
                'feedback' => $isPracticeMode ? '[PRACTICE] Entraînement - note non officielle' : null,
            ]);
        } catch (UniqueConstraintViolationException $conflit) {
            if (! self::surUnUniqueDesCopies($conflit)) {
                throw $conflit;
            }

            $concurrente = $this->findOpenAttempt($evaluation->id, $user);
            if ($concurrente !== null) {
                return $this->okResponse($concurrente, $window, true, $isPracticeMode);
            }

            // Le 409 seulement si la requête gagnante a réellement remis entre-temps :
            // les copies remises de CET élève atteignent le numéro disputé. Sinon
            // le conflit porte sur une ligne que `ownedBy` ne voit pas (anomalie de
            // données), et l'exception remonte pour être rapportée.
            $remises = EvaluationSubmission::query()->where('evaluation_id', $evaluation->id)
                ->ownedBy($user)->whereIn('status', ['soumis', 'corrige'])->count();
            if ($remises < $tentative) {
                throw $conflit;
            }

            $this->logger->warning('Démarrage perdu dans une course : la tentative concurrente est déjà remise', [
                'evaluation_id' => $evaluation->id,
                'student_id' => $user->id,
                'attempt' => $tentative,
            ]);

            return ['status' => 'attempt_conflict', 'message' => 'Une autre tentative vient d\'être remise. Rechargez l\'évaluation.'];
        }

        return $this->okResponse($submission, $window, false, $isPracticeMode);
    }

    /**
     * Le conflit porte-t-il sur l'un des deux uniques des copies
     * (`eval_sub_unique`, `eval_sub_student_unique`) ? Seul celui-là est une
     * course (D188). MySQL et PostgreSQL nomment la contrainte ; SQLite cite ses
     * colonnes.
     */
    private static function surUnUniqueDesCopies(UniqueConstraintViolationException $conflit): bool
    {
        $message = $conflit->getMessage();

        return str_contains($message, 'eval_sub_unique')
            || str_contains($message, 'eval_sub_student_unique')
            || preg_match(
                '/evaluation_submissions\.evaluation_id, evaluation_submissions\.(klassci_etudiant_id|student_id), evaluation_submissions\.attempt/',
                $message,
            ) === 1;
    }

    /**
     * Réponse `ok` (tentative démarrée ou reprise) — construit le payload commun
     * aux deux chemins (reprise / création) pour éviter la duplication.
     *
     * @param  ?array<string, mixed>  $window
     * @return array{status: string, submission: EvaluationSubmission, window: ?array<string, mixed>, resumed: bool, is_practice: bool}
     */
    private function okResponse(EvaluationSubmission $submission, ?array $window, bool $resumed, bool $isPracticeMode): array
    {
        return [
            'status' => 'ok',
            'submission' => $submission,
            'window' => $window,
            'resumed' => $resumed,
            'is_practice' => $isPracticeMode,
        ];
    }

    /**
     * @return array{window: ?array<string, mixed>, server_time: string}
     * @throws RuntimeException  Pas de klassci_token, évaluation hors de la
     *                           liste de l'élève, ou KLASSCI en échec
     */
    public function getTimeStatus(int $evaluationId, User $user): array
    {
        if ($this->windowGate->refusAvantLecture($user) !== null) {
            throw MissingKlassciTokenException::forUser($user->id);
        }

        $evaluation = $this->visibleEvaluation($evaluationId, $user);
        if ($evaluation === null) {
            throw new RuntimeException('Évaluation non trouvée');
        }

        // Endpoint informatif : on tolère `null` (pas de fenêtre OU KLASSCI
        // indisponible) — ce n'est pas un gate (#499, getTimeStatus inchangé).
        $window = $this->windowGate->fetchWindow($user, $evaluation)['window'];
        return [
            'window' => $window,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * L'évaluation, si elle figure dans la liste de l'élève ; sinon null, sans
     * dire pourquoi. La portée est posée AVANT la lecture : rien n'est chargé
     * hors de ce que la liste propose.
     */
    private function visibleEvaluation(int $evaluationId, User $user): ?Evaluation
    {
        $evaluation = $this->scope->visibleFor($user)?->find($evaluationId);

        return $evaluation instanceof Evaluation ? $evaluation : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * Liste enrichie des évaluations d'un étudiant — extrait de
 * `EvaluationStudentController::studentEvaluationsForUser` (split §5).
 *
 * Enrichit chaque évaluation LMS avec : fenêtre temporelle KLASSCI,
 * lms_integration, classe, matière, questions_count, dernière soumission
 * de l'étudiant.
 *
 * Returns `null` quand aucune classe n'est résolue pour l'étudiant
 * (caller render 404). Throws `MissingKlassciTokenException` si pas de klassci_token
 * (caller render 401).
 */
final class StudentEvaluationsListService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null  null = classe non trouvée
     * @throws MissingKlassciTokenException  Si pas de klassci_token sur le user
     */
    public function getEnrichedEvaluationsForStudent(User $user): ?array
    {
        $klassciToken = $user->klassci_token;
        if (!$klassciToken) {
            throw MissingKlassciTokenException::forUser($user->id);
        }

        $klassciEtudiantId = $user->klassci_id;

        $this->logger->info('Student Evaluations request', [
            'user_id' => $user->id,
            'klassci_id' => $klassciEtudiantId,
        ]);

        $dashboard = $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');
        $classe = $dashboard['data']['classe'] ?? null;
        if (!is_array($classe) || !is_numeric($classe['id'] ?? null)) {
            return null;
        }
        $classeId = (int) $classe['id'];

        // La seule chose que la liste dira de la classe : son identité, déjà en
        // main. L'enveloppe `classes/{id}`, qui porte le roster, n'est plus
        // demandée (GHSA-gg7j).
        $classeIdentite = ['id' => $classeId, 'nom' => $this->nomDeClasse($classe)];

        // Ni `questions` ni `submissions` ne sont chargées : elles étaient
        // sérialisées telles quelles par `toArray()`, livrant à l'élève le
        // corrigé de chaque question AVANT l'épreuve, et les copies de TOUS
        // ses camarades — réponses, score et note.
        //
        // Ce que cette liste doit vraiment porter est plus étroit : le NOMBRE
        // de questions, et la propre copie de l'élève, que `enrichEvaluation`
        // allait déjà chercher séparément.
        $evaluationsLMS = Evaluation::withCount('questions')
            ->where('klassci_classe_id', $classeId)
            ->where('is_published', true)
            ->whereIn('status', ['planifiee', 'en_cours', 'terminee'])
            ->whereHas('questions')
            ->orderBy('date_evaluation', 'desc')
            ->get();

        $klassciEvaluations = $this->fetchKlassciEvaluationsSafe($klassciToken);

        return $evaluationsLMS->map(
            fn ($evalLMS) => $this->enrichEvaluation($evalLMS, $klassciEvaluations, $klassciEtudiantId, $klassciToken, $classeIdentite)
        )->values()->toArray();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fetchKlassciEvaluationsSafe(string $klassciToken): \Illuminate\Support\Collection
    {
        try {
            $response = $this->klassciService->requestWithUserToken($klassciToken, 'evaluations', 'GET');
            return collect($response['data'] ?? []);
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch KLASSCI evaluations for windows', ['error' => $e->getMessage()]);
            return collect([]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $klassciEvaluations
     * @param  array{id: int, nom: ?string}  $classeIdentite
     * @return array<string, mixed>
     */
    private function enrichEvaluation(
        Evaluation $evalLMS,
        \Illuminate\Support\Collection $klassciEvaluations,
        ?int $klassciEtudiantId,
        string $klassciToken,
        array $classeIdentite
    ): array {
        $evalArray = $evalLMS->toArray();
        $klassciEval = $klassciEvaluations->firstWhere('id', $evalLMS->klassci_evaluation_id);

        if ($klassciEval) {
            $evalArray['programmation'] = $klassciEval['programmation'] ?? null;
            if ($evalArray['programmation']) {
                $evalArray['programmation']['date_evaluation'] = $evalLMS->date_evaluation;
            }
            $evalArray['lms_integration'] = $klassciEval['lms_integration'] ?? null;
            $evalArray['classe'] = $klassciEval['classe'] ?? null;
            $evalArray['matiere'] = $klassciEval['matiere'] ?? null;
        } else {
            $this->enrichPureLmsEvaluation($evalArray, $evalLMS, $klassciToken, $classeIdentite);
        }

        // `withCount` a déjà posé le compte : plus besoin de charger les
        // questions elles-mêmes pour les dénombrer.
        $evalArray['questions_count'] = (int) $evalLMS->questions_count;
        $evalArray['student_submission'] = $evalLMS->submissions()
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->latest()
            ->first();

        return $evalArray;
    }

    /**
     * Évaluation sans correspondance KLASSCI : la matière est demandée, la
     * classe ne l'est PLUS. `classes/{id}` livre l'enveloppe entière de la
     * classe — roster, e-mails, téléphones — et elle partait telle quelle vers
     * l'élève (GHSA-gg7j). Son identité suffit, et elle est déjà connue.
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     * @param  array{id: int, nom: ?string}  $classeIdentite
     */
    private function enrichPureLmsEvaluation(
        array &$evalArray,
        Evaluation $evalLMS,
        string $klassciToken,
        array $classeIdentite
    ): void {
        $evalArray['classe'] = $classeIdentite;

        if (!$evalLMS->klassci_matiere_id) {
            return;
        }

        try {
            $matiereResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$evalLMS->klassci_matiere_id}",
                'GET'
            );
            $evalArray['matiere'] = $matiereResponse['data']['matiere'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch matiere for pure LMS eval', ['error' => $e->getMessage()]);
        }
    }

    /**
     * KLASSCI nomme la classe `nom`, `name` ou `libelle` selon l'endpoint.
     *
     * @param  array<string, mixed>  $classe
     */
    private function nomDeClasse(array $classe): ?string
    {
        foreach (['nom', 'name', 'libelle'] as $cle) {
            if (is_string($classe[$cle] ?? null)) {
                return $classe[$cle];
            }
        }

        return null;
    }
}

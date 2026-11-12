<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Support\Logging\CaughtExceptionLog;
use Throwable;

/**
 * École créée depuis KLASSCI : on met en ligne une évaluation PROGRAMMÉE dans
 * KLASSCI (D72, Q1).
 *
 * - L'auteur doit porter une identité enseignant KLASSCI (#124) : elle est
 *   dérivée du jeton Sanctum, jamais du corps.
 * - `klassci_evaluation_id` est exigé : la saisie libre n'appartient qu'à
 *   l'école créée depuis le LMS. Les deux appelants du front l'envoient
 *   toujours (`useCreateQuestions.js:198`, `useTeacherEvaluationActions.js:65`).
 * - Les libellés sont lus chez KLASSCI, fail-soft : une panne laisse des
 *   libellés nuls et ne bloque pas la mise en ligne.
 */
final class KlassciEvaluationProgramming implements EvaluationProgramming
{
    private const CHAMPS_LOCAUX = ['classe_id', 'matiere_id'];

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function programmer(array $donnees, User $auteur): ProgrammedEvaluation|EvaluationProgrammingRefusal
    {
        if ($auteur->klassci_enseignant_id === null) {
            return new EvaluationProgrammingRefusal(
                403,
                'Vous devez être un enseignant KLASSCI synchronisé pour créer une évaluation.',
                'klassci_teacher_identity_missing',
            );
        }

        $horsEspace = EvaluationProgrammingRefusal::horsEspace($donnees, self::CHAMPS_LOCAUX);
        if ($horsEspace !== null) {
            return $horsEspace;
        }

        if (! is_numeric($donnees['klassci_evaluation_id'] ?? null)) {
            return new EvaluationProgrammingRefusal(
                422,
                'Une évaluation se programme dans KLASSCI avant d\'être mise en ligne.',
                'klassci_scheduling_required',
            );
        }

        $matiereId = is_numeric($donnees['klassci_matiere_id'] ?? null) ? (int) $donnees['klassci_matiere_id'] : null;
        $classeId = is_numeric($donnees['klassci_classe_id'] ?? null) ? (int) $donnees['klassci_classe_id'] : null;
        [$matiereNom, $classeNom] = $this->libelles($auteur, $matiereId, $classeId);

        return new ProgrammedEvaluation([
            'klassci_evaluation_id' => (int) $donnees['klassci_evaluation_id'],
            'klassci_matiere_id'    => $matiereId,
            'klassci_classe_id'     => $classeId,
            'klassci_enseignant_id' => $auteur->klassci_enseignant_id,
            'matiere_nom'           => $matiereNom,
            'classe_nom'            => $classeNom,
        ]);
    }

    /**
     * Libellés lus chez KLASSCI, chacun fail-soft : une lecture en échec laisse
     * son libellé nul sans effacer l'autre.
     *
     * @return array{0: ?string, 1: ?string}  [matiereNom, classeNom]
     */
    private function libelles(User $auteur, ?int $matiereId, ?int $classeId): array
    {
        $jeton = $auteur->klassci_token;
        $jeton = is_string($jeton) && $jeton !== '' ? $jeton : null;

        $matiere = $matiereId !== null && $jeton !== null
            ? $this->ligne(fn (): array => $this->klassciService->getMatieres($jeton), $matiereId)
            : [];
        $classe = $classeId !== null
            ? $this->ligne(fn (): array => $this->klassciService->getClasses(), $classeId)
            : [];

        return [
            $this->premier($matiere, ['nom', 'libelle']),
            $this->premier($classe, ['libelle', 'nom'])
                ?? $this->premier(KlassciPayload::asArray($classe['niveau'] ?? null), ['code', 'libelle']),
        ];
    }

    /**
     * La ligne de `data` dont l'id est celui demandé, ou `[]`.
     *
     * @param  \Closure(): array<mixed>  $lecture
     * @return array<string, mixed>
     */
    private function ligne(\Closure $lecture, int $id): array
    {
        try {
            $lignes = KlassciPayload::listOfArrays(KlassciPayload::asArray($lecture())['data'] ?? null);
        } catch (Throwable $e) {
            $this->journal->warning('Impossible de récupérer les noms depuis KLASSCI', $e);

            return [];
        }

        foreach ($lignes as $ligne) {
            if (KlassciPayload::toInt($ligne['id'] ?? null) === $id) {
                return $ligne;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @param  list<string>  $cles
     */
    private function premier(array $ligne, array $cles): ?string
    {
        foreach ($cles as $cle) {
            $valeur = KlassciPayload::toStringOrNull($ligne[$cle] ?? null);
            if ($valeur !== null && $valeur !== '') {
                return $valeur;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * École créée depuis le LMS : l'évaluation se programme dans le LMS, sur une
 * classe et une matière LOCALES (D72).
 *
 * - `classe_id` et `matiere_id` sont exigés, et appartiennent à l'établissement
 *   de l'auteur ; sinon 422, erreurs de champ.
 * - Le couple doit être programmé dans `classe_matiere`, le pivot local que lit
 *   déjà la page matière (`LocalMatiereDetailsReader`). Un enseignant doit y
 *   être LE formateur désigné ; la gestion crée sur tout couple programmé de
 *   son école. Sinon 403 `non_designe`.
 * - Les libellés viennent des tables locales : aucun appel à KLASSCI, même
 *   pour un compte porteur d'un jeton.
 * - `klassci_*` est refusé : un id local n'y a pas sa place (D132).
 */
final class LocalEvaluationProgramming implements EvaluationProgramming
{
    private const CHAMPS_KLASSCI = ['klassci_evaluation_id', 'klassci_classe_id', 'klassci_matiere_id'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function programmer(array $donnees, User $auteur): ProgrammedEvaluation|EvaluationProgrammingRefusal
    {
        $horsEspace = EvaluationProgrammingRefusal::horsEspace($donnees, self::CHAMPS_KLASSCI);
        if ($horsEspace !== null) {
            return $horsEspace;
        }

        $classeId = $this->identifiant($donnees, 'classe_id');
        $matiereId = $this->identifiant($donnees, 'matiere_id');
        $classe = $classeId === null ? null : $this->classe($classeId, $auteur);
        $matiere = $matiereId === null ? null : $this->matiere($matiereId, $auteur);

        if ($classeId === null || $matiereId === null || $classe === null || $matiere === null) {
            return new EvaluationProgrammingRefusal(422, 'La classe et la matière sont requises.', null, array_filter([
                'classe_id' => $classe === null ? ['Choisissez une classe de votre établissement.'] : null,
                'matiere_id' => $matiere === null ? ['Choisissez une matière de votre établissement.'] : null,
            ]));
        }

        if (! $this->estProgramme($classeId, $matiereId, $auteur)) {
            return new EvaluationProgrammingRefusal(
                403,
                'Vous n\'êtes pas désigné pour enseigner cette matière dans cette classe.',
                'non_designe',
            );
        }

        return new ProgrammedEvaluation([
            'classe_id'   => $classeId,
            'matiere_id'  => $matiereId,
            'classe_nom'  => $classe->libelle,
            'matiere_nom' => $matiere->libelle,
        ]);
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function identifiant(array $donnees, string $champ): ?int
    {
        $valeur = $donnees[$champ] ?? null;

        return is_numeric($valeur) ? (int) $valeur : null;
    }

    private function classe(int $id, User $auteur): ?Classe
    {
        return Classe::query()->whereKey($id)->where('institution_id', $auteur->institution_id)->first();
    }

    private function matiere(int $id, User $auteur): ?Matiere
    {
        return Matiere::query()->whereKey($id)->where('institution_id', $auteur->institution_id)->first();
    }

    private function estProgramme(int $classeId, int $matiereId, User $auteur): bool
    {
        $couple = $this->db->table('classe_matiere')
            ->where('classe_id', $classeId)
            ->where('matiere_id', $matiereId);

        if (! $auteur->isAdmin()) {
            $couple->where('enseignant_id', $auteur->getKey());
        }

        return $couple->exists();
    }
}

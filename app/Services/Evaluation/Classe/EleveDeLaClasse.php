<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Models\DispenseDEvaluation;
use App\Models\EvaluationSubmission;

/**
 * #1676 : un élève de la classe, tel que le bilan le reconnaît sur ses copies.
 *
 * Une copie lui appartient par son auteur LOCAL (`student_id`) ; une copie d'avant les comptes
 * locaux (sans auteur) lui appartient par l'une de ses identités KLASSCI. C'est la règle de
 * `DernieresCopies::appartient()` (résultats d'une évaluation), appliquée ici à toutes les
 * évaluations d'une classe.
 */
final class EleveDeLaClasse
{
    /** @param  list<int>  $identitesKlassci */
    public function __construct(
        public readonly ?int $compte,
        public readonly array $identitesKlassci,
        public readonly string $nom,
    ) {}

    public function possede(EvaluationSubmission $copie): bool
    {
        $auteur = is_numeric($copie->student_id) ? (int) $copie->student_id : null;
        if ($auteur !== null) {
            return $this->compte !== null && $auteur === $this->compte;
        }

        return is_numeric($copie->klassci_etudiant_id)
            && in_array((int) $copie->klassci_etudiant_id, $this->identitesKlassci, true);
    }

    /**
     * #1698 (C2) : cette dispense le désigne-t-elle ? Par son compte, ou par l'une de ses
     * identités KLASSCI : la règle de `CopiesFinales` sur les copies, côté élève.
     */
    public function estDesignePar(DispenseDEvaluation $dispense): bool
    {
        return ($this->compte !== null && $dispense->student_id === $this->compte)
            || ($dispense->klassci_etudiant_id !== null && in_array($dispense->klassci_etudiant_id, $this->identitesKlassci, true));
    }

    /** La clé stable de la ligne : le compte local, sinon la première identité KLASSCI. */
    public function cle(): string
    {
        return $this->compte !== null ? 'compte:'.$this->compte : 'klassci:'.($this->identitesKlassci[0] ?? 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Illuminate\Support\Collection;

/**
 * La dernière copie officielle de chaque élève d'une évaluation, pour les
 * résultats de l'enseignant (D72, E3, C26).
 *
 * ## La même règle que `ownedBy`
 *
 * Une copie appartient à un élève par son auteur (`student_id`, écrit à chaque
 * création, seul présent dans une école créée depuis le LMS) OU par son
 * identité KLASSCI (`klassci_etudiant_id`, pour une ligne d'avant D72). C'est
 * la règle de {@see EvaluationSubmission::scopeOwnedBy()}, appliquée ici en
 * mémoire : la requête servirait un élève à la fois, et l'écran en sert une
 * classe (#503, aucune requête par élève).
 *
 * Avant, les copies étaient regroupées par `klassci_etudiant_id` seul : les
 * copies locales, toutes à NULL, se fondaient en une seule.
 *
 * L'établissement n'est pas refiltré : ce sont les copies de CETTE évaluation,
 * déjà chargées par sa relation.
 *
 * Les tentatives d'entraînement (`feedback` commençant par `[PRACTICE]`) sont
 * écartées : leur note n'est pas officielle. La plus récente gagne
 * (`created_at`, puis l'id à égalité).
 */
final class DernieresCopies
{
    /**
     * @param  Collection<int, EvaluationSubmission>  $copies  la plus récente en tête
     */
    private function __construct(private readonly Collection $copies) {}

    public static function de(Evaluation $evaluation): self
    {
        return new self($evaluation->submissions
            ->reject(static fn (EvaluationSubmission $copie): bool
                => str_starts_with((string) $copie->feedback, '[PRACTICE]'))
            ->sortBy([['created_at', 'desc'], ['id', 'desc']])
            ->values());
    }

    /**
     * La dernière copie de l'élève désigné par son compte local, ou à défaut
     * par une de ses identités KLASSCI. `null` ne désigne personne.
     */
    public function pour(?int $auteur, ?int ...$identitesKlassci): ?EvaluationSubmission
    {
        $identites = array_values(array_filter($identitesKlassci, static fn (?int $id): bool => $id !== null));

        return $this->copies->first(static fn (EvaluationSubmission $copie): bool
            => ($auteur !== null && self::entier($copie->student_id) === $auteur)
            || in_array(self::entier($copie->klassci_etudiant_id), $identites, true));
    }

    /**
     * Sans cast sur le modèle, un pilote peut rendre l'id en chaîne.
     */
    private static function entier(mixed $valeur): ?int
    {
        return is_numeric($valeur) ? (int) $valeur : null;
    }
}

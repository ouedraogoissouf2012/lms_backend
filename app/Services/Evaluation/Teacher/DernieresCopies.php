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
 * création, seul présent dans une école créée depuis le LMS) OU, pour une ligne
 * sans auteur (d'avant D72), par son identité KLASSCI (`klassci_etudiant_id`). C'est
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
        return $this->copies->first(static fn (EvaluationSubmission $copie): bool
            => self::appartient($copie, $auteur, $identitesKlassci));
    }

    /**
     * Les copies qu'aucun élève du roster ne réclame : la dernière de chaque
     * auteur, dans l'établissement de l'évaluation.
     *
     * Règle : aucune copie remise n'est jamais invisible pour l'enseignant. Un
     * élève peut avoir passé l'évaluation sans figurer au roster : admis par le
     * repli `user_classes` de l'audience (C24), désinscrit après sa remise, ou
     * sorti du roster KLASSCI. Sa ligne apparaît, marquée hors roster.
     *
     * L'auteur est désigné par `student_id`, sinon par son identité KLASSCI ;
     * une copie sans l'un ni l'autre reste seule.
     *
     * @param  list<array{0: ?int, 1: list<?int>}>  $roster  pour chaque élève, son compte et ses identités KLASSCI
     * @return list<EvaluationSubmission>
     */
    public function horsRoster(array $roster, ?int $etablissement): array
    {
        return array_values($this->copies
            ->filter(static fn (EvaluationSubmission $copie): bool
                => $etablissement !== null && self::entier($copie->institution_id) === $etablissement)
            ->reject(static fn (EvaluationSubmission $copie): bool => collect($roster)
                ->contains(static fn (array $eleve): bool => self::appartient($copie, $eleve[0], $eleve[1])))
            ->unique(static fn (EvaluationSubmission $copie): string => match (true) {
                self::entier($copie->student_id) !== null => 'auteur:'.$copie->student_id,
                self::entier($copie->klassci_etudiant_id) !== null => 'klassci:'.$copie->klassci_etudiant_id,
                default => 'copie:'.$copie->id,
            })
            ->all());
    }

    /**
     * La règle de `ownedBy` : par l'auteur, ou, pour une ligne SANS auteur
     * seulement, par une identité KLASSCI. Une copie qui a un auteur n'est
     * jamais rattachée à un autre élève qui porterait son identité KLASSCI.
     *
     * @param  array<int|string, ?int>  $identitesKlassci
     */
    private static function appartient(EvaluationSubmission $copie, ?int $auteur, array $identitesKlassci): bool
    {
        $identites = array_values(array_filter($identitesKlassci, static fn (?int $id): bool => $id !== null));

        $auteurDeLaCopie = self::entier($copie->student_id);

        return $auteurDeLaCopie === null
            ? in_array(self::entier($copie->klassci_etudiant_id), $identites, true)
            : $auteur !== null && $auteurDeLaCopie === $auteur;
    }

    /**
     * Sans cast sur le modèle, un pilote peut rendre l'id en chaîne.
     */
    private static function entier(mixed $valeur): ?int
    {
        return is_numeric($valeur) ? (int) $valeur : null;
    }
}

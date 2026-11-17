<?php

declare(strict_types=1);

namespace App\Services\Import\Fields;

use App\Enums\ClasseEtudiantStatut;
use App\Models\Classe;

/**
 * Résout la colonne `statut` d'un fichier d'import (#718).
 *
 * ## Ce que cette colonne commande réellement
 *
 * `classe_etudiant.statut` n'est pas une étiquette : il filtre
 * {@see Classe::etudiantsActifs()} et l'audience locale des séances
 * (#712). Un abandon écrit « actif » remet un absent dans les listes d'appel.
 *
 * ## Ce qu'un fichier pose, et ce qu'il ne pose pas (#920)
 *
 * Les statuts sont ceux de {@see ClasseEtudiantStatut} (ADR-711-02), et la
 * table en dérive : un statut nouveau doit être classé ici, ce que le test
 * vérifie. Un fichier en pose trois. Il en refuse deux, pour une raison MÉTIER
 * qu'il dit à la ligne refusée :
 * - `expire` : l'expiration découle d'une date de fin (`ends_on`), que le
 *   fichier ne porte pas ;
 * - `suspendu` : aucune règle ne le distingue d'`inactif`, et l'accepter
 *   créerait un état que rien ne gouverne.
 *
 * Le refus a lieu à l'analyse, avant toute écriture. Il ne tient plus à un
 * ENUM MySQL : la colonne est un `varchar` depuis `2026_09_16_140000`.
 *
 * Classe pure, sans dépendance — testable unitairement.
 */
final class StatutField
{
    /**
     * Graphies du tableur → statut. « abandonné » à côté de « abandonne » : la
     * base s'écrit sans accent, mais personne ne tape le mot ainsi.
     *
     * @var array<string, string>
     */
    private const GRAPHIES = [
        'active' => ClasseEtudiantStatut::Actif->value,
        'inactive' => ClasseEtudiantStatut::Inactif->value,
        'abandonné' => ClasseEtudiantStatut::Abandonne->value,
        'abandon' => ClasseEtudiantStatut::Abandonne->value,
    ];

    /**
     * Statuts connus qu'un fichier ne pose pas, et pourquoi.
     *
     * @var array<string, string>
     */
    private const NON_IMPORTABLES = [
        'expire' => "l'expiration découle d'une date de fin, que le fichier ne porte pas",
        'suspendu' => "aucune règle ne le distingue d'« inactif »",
    ];

    public function resolve(string $raw): FieldOutcome
    {
        $declared = mb_strtolower(trim($raw));

        // Colonne absente ou cellule vide : le fichier ne dit rien, comme pour la
        // date. Le défaut `actif` ne vaut qu'à la CRÉATION de l'adhésion, décidé
        // par `StudentEnrolmentService::inscrire()` (#1237) : le poser ici
        // réactivait un suspendu à chaque réimportation sans statut.
        if ($declared === '') {
            return FieldOutcome::accepted(ClasseEtudiantStatut::Actif->value);
        }

        $statut = ClasseEtudiantStatut::tryFrom(self::GRAPHIES[$declared] ?? $declared);

        if ($statut === null) {
            return FieldOutcome::rejected(
                'statut_inconnu',
                sprintf('Statut « %s » inconnu. Valeurs acceptées : %s.', trim($raw), implode(', ', self::importables())),
            );
        }

        if (isset(self::NON_IMPORTABLES[$statut->value])) {
            return FieldOutcome::rejected(
                'statut_non_importable',
                sprintf('Statut « %s » non importable : %s.', trim($raw), self::NON_IMPORTABLES[$statut->value]),
            );
        }

        return FieldOutcome::accepted($statut->value);
    }

    /**
     * @return list<string>
     */
    private static function importables(): array
    {
        $valeurs = array_map(static fn (ClasseEtudiantStatut $cas): string => $cas->value, ClasseEtudiantStatut::cases());

        return array_values(array_filter($valeurs, static fn (string $valeur): bool => ! isset(self::NON_IMPORTABLES[$valeur])));
    }
}

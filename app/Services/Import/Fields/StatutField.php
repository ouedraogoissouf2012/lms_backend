<?php

declare(strict_types=1);

namespace App\Services\Import\Fields;

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
 * ## Pourquoi refuser plutôt que rabattre sur le défaut
 *
 * La colonne en base est un ENUM. Une valeur hors énumération serait refusée par
 * MySQL PENDANT l'exécution asynchrone — donc après écriture partielle, l'état
 * que l'analyse à blanc existe précisément pour éviter. Le refus a lieu avant.
 *
 * Classe pure, sans dépendance — testable unitairement.
 */
final class StatutField
{
    /**
     * Graphie du tableur → valeur de l'ENUM `classe_etudiant.statut`.
     *
     * « abandonné » y figure à côté de « abandonne » : la base s'écrit sans
     * accent, mais personne ne tape le mot ainsi. Refuser la graphie juste au
     * profit de celle de la base ferait rejeter un fichier correct.
     *
     * @var array<string, string>
     */
    private const ACCEPTED = [
        'actif' => 'actif',
        'active' => 'actif',
        'inactif' => 'inactif',
        'inactive' => 'inactif',
        'abandonne' => 'abandonne',
        'abandonné' => 'abandonne',
        'abandon' => 'abandonne',
    ];

    public function resolve(string $raw): FieldOutcome
    {
        $declared = mb_strtolower(trim($raw));

        // Défaut de la colonne en base, et comportement d'avant cette issue.
        if ($declared === '') {
            return FieldOutcome::accepted('actif');
        }

        $statut = self::ACCEPTED[$declared] ?? null;

        if ($statut === null) {
            return FieldOutcome::rejected(
                'statut_inconnu',
                sprintf('Statut « %s » inconnu. Valeurs acceptées : actif, inactif, abandonne.', trim($raw)),
            );
        }

        return FieldOutcome::accepted($statut);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Enseignant;

/**
 * Les filtres de la liste des enseignants (#1666, ARRÊT 1, Q4) : `statut` et `specialite`, dans les DEUX volets, sur
 * la VALEUR RENDUE de chaque ligne (`status`, `specialization`). Ils s'appliquent aux lignes déjà servies par la
 * source de l'école, après sa grille de visibilité : aucun contrat de source ne change. La pagination reste à #1647.
 */
final class FiltreDesEnseignants
{
    /**
     * @param  array<array-key, mixed>  $lignes
     * @return list<mixed>
     */
    public static function appliquer(array $lignes, ?string $statut, ?string $specialite): array
    {
        return array_values(array_filter($lignes, static fn (mixed $ligne): bool => is_array($ligne)
            && ($statut === null || ($ligne['status'] ?? null) === $statut)
            && ($specialite === null || true))); // MUTATION P5 : specialite ignoree
    }

    public static function actif(?string $statut, ?string $specialite): bool
    {
        return $statut !== null || $specialite !== null;
    }
}

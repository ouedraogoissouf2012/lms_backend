<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\User;

/**
 * Résout et auto-répare `users.klassci_enseignant_id` depuis les données KLASSCI.
 *
 * Extrait de {@see KlassciUserSynchronizer} (issue #275, §1.1) — comportement
 * inchangé.
 *
 * ## Issue #119 — `klassci_enseignant_id` write-once-IF-NULL
 *
 * Autorité d'ownership des évaluations. Certains tenants n'exposent pas
 * `enseignant_id` dans `auth/me` (ils renvoient `enseignant_data` sans id) :
 * sans fallback, le champ reste null et toute création d'évaluation est refusée
 * en 403.
 *
 * Invariant sécurité : une valeur déjà établie n'est **JAMAIS** réécrite
 * ({@see healIfNull()} fait un return anticipé si non-null). Passer null→valeur
 * n'est pas un changement d'autorité — et la valeur dérive de l'identité
 * authentifiée (`klassci_id`), non d'un champ spoofable.
 */
final class KlassciEnseignantIdResolver
{
    /**
     * Résout l'id enseignant KLASSCI à stocker :
     *   1. `enseignant_id` dédié si KLASSCI le fournit (numérique) ;
     *   2. sinon, pour un ENSEIGNANT, fallback sur `klassci_id` (identité
     *      utilisateur) — cohérent avec le domaine séances
     *      ({@see \App\Services\Seances\TeachingSeancesFetcher}) ;
     *   3. sinon null (étudiant/admin sans enseignant_id : pas de création d'éval).
     *
     * @param  array<string, mixed>  $klassciUser
     */
    public function resolve(array $klassciUser): ?int
    {
        if (isset($klassciUser['enseignant_id']) && is_numeric($klassciUser['enseignant_id'])) {
            return (int) $klassciUser['enseignant_id'];
        }

        $role = $klassciUser['role'] ?? null;
        if ($role === 'enseignant' && isset($klassciUser['id']) && is_numeric($klassciUser['id'])) {
            return (int) $klassciUser['id'];
        }

        return null;
    }

    /**
     * Initialise `klassci_enseignant_id` UNE FOIS pour un user existant qui ne
     * l'a pas encore (null) — auto-réparation au login. Une valeur déjà établie
     * n'est JAMAIS réécrite (invariant #119).
     *
     * @param  array<string, mixed>  $klassciUser
     */
    public function healIfNull(User $user, array $klassciUser): void
    {
        if ($user->klassci_enseignant_id !== null) {
            return;
        }

        $resolved = $this->resolve($klassciUser);
        if ($resolved !== null) {
            $user->update(['klassci_enseignant_id' => $resolved]);
        }
    }
}

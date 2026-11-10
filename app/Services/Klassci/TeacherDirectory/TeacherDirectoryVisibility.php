<?php

declare(strict_types=1);

namespace App\Services\Klassci\TeacherDirectory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * La grille des rôles de la liste des enseignants : la règle UNIQUE, appliquée par
 * chaque lecture de cette liste, après son cache.
 *
 * | Lecteur | Lignes |
 * |---|---|
 * | gestion (`isManager()` : coordinateur, admin, superAdmin) | toutes |
 * | enseignant (`isTeacher()`) | la sienne |
 * | tout autre rôle (élève, rôle non reconnu) | aucune |
 *
 * La ligne d'un enseignant se reconnaît à son identité, et chaque clé n'est
 * comparée qu'à l'identifiant du MÊME espace :
 * - `id` et `enseignant_id` (l'espace des enseignants KLASSCI) à
 *   `users.klassci_enseignant_id` ;
 * - `user_id` et `klassci_id` (l'espace des comptes KLASSCI) à `users.klassci_id`.
 *
 * `klassci_enseignant_id` vaut `klassci_id` quand KLASSCI n'a pas fourni
 * d'identifiant enseignant (`KlassciEnseignantIdResolver`) : il n'appartient
 * alors à aucun espace sûr, et n'est pas lu. Un identifiant n'est retenu que
 * s'il est un entier ≥ 1. Sans identifiant retenu, aucune ligne : l'échec est
 * fermé.
 *
 * Hors de la gestion, la réponse est reconstruite à partir d'une liste blanche
 * (`success`, `data`, `meta.total`) : rien d'autre de la réponse source n'est
 * servi.
 */
final class TeacherDirectoryVisibility
{
    /** Clés d'une ligne dans l'espace des enseignants KLASSCI. */
    private const CLES_ENSEIGNANT = ['id', 'enseignant_id'];

    /** Clés d'une ligne dans l'espace des comptes KLASSCI. */
    private const CLES_COMPTE = ['user_id', 'klassci_id'];

    /** Le lecteur peut-il voir au moins une ligne ? Sinon, la liste n'est pas lue. */
    public function readsAnything(User $lecteur): bool
    {
        return $lecteur->isManager() || $lecteur->isTeacher();
    }

    /**
     * La réponse servie pour une réponse source `{success, data, meta…}`.
     *
     * @param  array<array-key, mixed>  $reponse
     * @return array<array-key, mixed>
     */
    public function envelopeFor(User $lecteur, array $reponse): array
    {
        if ($lecteur->isManager()) {
            return $reponse;
        }

        $lignes = $reponse['data'] ?? [];
        $servies = is_array($lignes) ? $this->visibleFor($lecteur, $lignes) : [];

        return ['success' => true, 'data' => $servies, 'meta' => ['total' => count($servies)]];
    }

    /**
     * @param  array<array-key, mixed>  $lignes
     * @return array<array-key, mixed>
     */
    public function visibleFor(User $lecteur, array $lignes): array
    {
        if ($lecteur->isManager()) {
            return $lignes;
        }

        if (! $lecteur->isTeacher()) {
            return [];
        }

        return array_values(array_filter(
            $lignes,
            fn (mixed $ligne): bool => is_array($ligne) && $this->estSaLigne($lecteur, $ligne),
        ));
    }

    /**
     * La même grille sur une source locale : les enseignants de l'établissement
     * du lecteur, borné explicitement (sans s'en remettre au scope global).
     *
     * @param  Builder<User>  $enseignants
     * @return Builder<User>
     */
    public function restrictLocal(User $lecteur, Builder $enseignants): Builder
    {
        $etablissement = $this->identifiant($lecteur->institution_id);
        if ($etablissement === null || ! $this->readsAnything($lecteur)) {
            return $enseignants->whereRaw('1 = 0');
        }

        $enseignants->where('institution_id', $etablissement);

        return $lecteur->isManager() ? $enseignants : $enseignants->whereKey($lecteur->getKey());
    }

    /** @param  array<array-key, mixed>  $ligne */
    private function estSaLigne(User $lecteur, array $ligne): bool
    {
        $compte = $this->identifiant($lecteur->klassci_id);
        $enseignant = $this->identifiant($lecteur->klassci_enseignant_id);
        if ($enseignant !== null && $enseignant === $compte) {
            $enseignant = null;
        }

        return $this->correspond($ligne, self::CLES_ENSEIGNANT, $enseignant)
            || $this->correspond($ligne, self::CLES_COMPTE, $compte);
    }

    /**
     * @param  array<array-key, mixed>  $ligne
     * @param  list<string>  $cles
     */
    private function correspond(array $ligne, array $cles, ?int $identifiant): bool
    {
        if ($identifiant === null) {
            return false;
        }

        foreach ($cles as $cle) {
            if ($this->identifiant($ligne[$cle] ?? null) === $identifiant) {
                return true;
            }
        }

        return false;
    }

    /** Un identifiant entier ≥ 1, ou null. */
    private function identifiant(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur >= 1 ? $valeur : null;
        }

        if (is_string($valeur) && ctype_digit($valeur) && strlen($valeur) < 19) {
            $entier = (int) $valeur;

            return $entier >= 1 ? $entier : null;
        }

        return null;
    }
}

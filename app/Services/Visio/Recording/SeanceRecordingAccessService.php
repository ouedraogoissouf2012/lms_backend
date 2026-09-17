<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;

final class SeanceRecordingAccessService
{
    public function canControl(Seance $seance, User $user): bool
    {
        return $user->isTeacher() && $this->teacherOwnsSeance($seance, $user);
    }

    public function canRead(Seance $seance, User $user): bool
    {
        if ($user->isManager() || $this->teacherOwnsSeance($seance, $user)) {
            return true;
        }

        if ($seance->klassci_classe_id !== null && $this->studentBelongsToSeanceClass($seance, $user)) {
            return true;
        }

        return ESBTPAttendance::query()
            ->where('seance_id', $seance->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Propriétaire par l'identité KLASSCI, OU par `created_by`.
     *
     * Le critère n'est pas inventé ici : `ManagerSeancesLocalFetcher:47-50`
     * l'applique déjà, et `TeachingSeancesFetcher` écrit les deux colonnes
     * (lignes 207 et 212). Ce service était le seul à ne connaître que la
     * moitié KLASSCI — une incohérence entre deux services sur la même
     * question, pas un manque d'architecture.
     *
     * Sans le second critère, un formateur d'école autonome ne pouvait ni
     * démarrer, ni arrêter, ni lire l'enregistrement de sa propre séance :
     * `klassci_enseignant_id` est nul par construction sur une séance créée
     * hors KLASSCI (`LocalSeanceCreator:28`).
     *
     * Les deux valeurs sont RESTREINTES avant comparaison, pas converties :
     * `created_by` est nullable et `getKey()` rend `mixed`. PHPStan niveau 9
     * refuse la conversion — c'est la raison vérifiable de `is_numeric`.
     *
     * Elle écarte aussi le nul, ce qu'une conversion ne ferait pas : `(int) null`
     * vaut 0. Cette seconde propriété n'est PAS prouvable par test — il faudrait
     * un utilisateur d'identifiant 0, qu'aucun auto-incrément ne produit.
     * Falsification exercée : retirer `is_numeric` ne fait rougir aucun test.
     * On l'écrit plutôt que de laisser croire le contraire.
     */
    private function teacherOwnsSeance(Seance $seance, User $user): bool
    {
        if ($this->ownsByKlassciIdentity($seance, $user)) {
            return true;
        }

        $createur = $seance->created_by;
        $acteur = $user->getKey();

        return is_numeric($createur)
            && is_numeric($acteur)
            && (int) $createur === (int) $acteur;
    }

    private function ownsByKlassciIdentity(Seance $seance, User $user): bool
    {
        if ($seance->klassci_enseignant_id === null) {
            return false;
        }

        return in_array((int) $seance->klassci_enseignant_id, $this->userTeacherIds($user), true);
    }

    /**
     * @return list<int>
     */
    private function userTeacherIds(User $user): array
    {
        $ids = [];
        foreach (['klassci_id', 'klassci_enseignant_id'] as $key) {
            $value = $user->getAttribute($key);
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private function studentBelongsToSeanceClass(Seance $seance, User $user): bool
    {
        if (! $user->isStudent()) {
            return false;
        }

        return UserClass::query()
            ->where('institution_id', $seance->institution_id)
            ->where('user_id', $user->id)
            ->where('klassci_classe_id', $seance->klassci_classe_id)
            ->exists();
    }
}

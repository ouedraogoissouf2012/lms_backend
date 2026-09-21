<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Enrollment\EnrollmentSource;

final class SeanceRecordingAccessService
{
    public function __construct(private readonly EnrollmentSource $inscriptions) {}

    public function canControl(Seance $seance, User $user): bool
    {
        return $user->isTeacher() && $this->teacherOwnsSeance($seance, $user);
    }

    public function canRead(Seance $seance, User $user): bool
    {
        if ($user->isManager() || $this->teacherOwnsSeance($seance, $user)) {
            return true;
        }

        // Le court-circuit `klassci_classe_id !== null` fermait cette porte a
        // TOUTE seance locale (#846) : LocalSeanceCreator ecrit ce champ a null
        // par construction. La classe de la seance se resout desormais dans les
        // deux mondes, et l appartenance passe par le composite.
        if ($this->studentBelongsToSeanceClass($seance, $user)) {
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

    /**
     * L'apprenant est-il inscrit dans la classe de cette seance ? (#846)
     *
     * ## Deux portes, parce que la seance declare son espace
     *
     * ADR-760-01, accepte : « quand une meme ressource est designee dans deux
     * espaces d'identifiants, chaque espace a sa porte ; aucune ne devine ».
     * Ici rien n'est a deviner : une seance locale porte `classe_id`, une seance
     * miroitee porte `klassci_classe_id`. Deux colonnes distinctes, deux portes.
     *
     * ## La porte KLASSCI etait MORTE en production
     *
     * Elle filtrait `user_classes.institution_id`. Or `StudentClassSynchronizer`
     * — SEUL ecrivain de cette table — n'ecrit jamais cette colonne. Mesure du
     * 2026-09-21 : sur la donnee que la production ecrit reellement, la requete
     * d'origine rend FAUX. Aucun apprenant ne passait par cette porte ; seules
     * les presences ouvraient l'acces.
     *
     * Le test qui la couvrait posait `institution_id` a la main, et prouvait
     * donc un etat que la production n'atteint jamais.
     *
     * L'isolation est desormais portee par la comparaison des etablissements de
     * l'apprenant et de la seance — fiable — et non par une colonne laissee
     * nulle.
     */
    private function studentBelongsToSeanceClass(Seance $seance, User $user): bool
    {
        if (! $user->isStudent()) {
            return false;
        }

        if ((int) $user->institution_id !== (int) $seance->institution_id) {
            return false;
        }

        if (is_numeric($seance->classe_id)) {
            return $this->parLaPorteLocale((int) $seance->classe_id, $user);
        }

        if ($seance->klassci_classe_id === null) {
            return false;
        }

        return $this->parLaPorteKlassci((int) $seance->klassci_classe_id, $user);
    }

    /**
     * Espace LOCAL : l'appartenance passe par le composite, comme l'exige
     * ADR-803-03 — « aucune lecture d'inscription ne le contourne ».
     */
    private function parLaPorteLocale(int $classeId, User $user): bool
    {
        return in_array($classeId, $this->inscriptions->localClasseIdsFor($user), true);
    }

    /**
     * Espace KLASSCI : la comparaison reste dans cet espace, sans traduction.
     *
     * Traduire vers `classes.id` exigerait une classe miroir locale, que rien
     * dans le parcours d'un apprenant ne cree — mesure du 2026-09-21. Le
     * composite ne peut donc pas servir cette porte : il rend des identifiants
     * locaux, et sa jambe KLASSCI depend elle-meme de ce miroir.
     */
    private function parLaPorteKlassci(int $klassciClasseId, User $user): bool
    {
        return UserClass::query()
            ->where('user_id', $user->id)
            ->where('klassci_classe_id', $klassciClasseId)
            // Aucune tolerance au NULL : #877 en avait ajoute une, et la mesure
            // du 21/09 a montre qu'elle ne servait a rien — le scope global de
            // `UserClass` filtre `institution_id` AVANT elle. Depuis #878 la
            // colonne est ecrite, et le scope borne deja a l'etablissement
            // courant. Garder la clause laisserait croire a une garde absente.
            ->exists();
    }
}

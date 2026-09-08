<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre le lien enseignant ↔ matière dans `matiere_enseignant` (#712).
 *
 * ## L'écrivain qui manquait
 *
 * La table existait mais restait VIDE — mesure du 2026-09-08 sur la base de
 * dev : 0 ligne. Sans elle, {@see KlassciEnrollmentSource} ne peut résoudre
 * aucune classe, et l'écran « Mes Classes » affiche « Aucune classe assignée »
 * pendant que le tableau de bord en annonce quatre.
 *
 * La donnée existait pourtant : `MatiereSyncService` interroge `matieres` avec
 * le jeton de l'utilisateur CONNECTÉ à chaque login. Ce qu'il rapporte, ce sont
 * précisément « les matières de cet enseignant ». Il ne restait qu'à
 * l'enregistrer.
 *
 * ## Pourquoi cette table, et pas `classe_matiere.enseignant_id`
 *
 * `classe_matiere` porte une ligne par couple (classe, matière) : elle ne peut
 * désigner qu'UN enseignant. Y écrire l'utilisateur connecté ferait gagner le
 * dernier à s'être connecté et effacerait le précédent — une fuite entre
 * enseignants, de la même famille que #707. `matiere_enseignant` est un
 * many-to-many : chaque enseignant a ses propres lignes.
 *
 * `classe_matiere.enseignant_id` garde son sens — l'affectation LOCALE, celle
 * du mode autonome — et reste lue par {@see LocalEnrollmentSource}. Les deux
 * sources répondent enfin à deux questions différentes, ce qui rend le repli
 * du {@see CompositeEnrollmentSource} réel.
 *
 * Vérifié par tests/Feature/Enrollment/TeacherMatieresLinkerTest.php.
 */
final class TeacherMatieresLinker
{
    /**
     * Rejoue le lien pour cet enseignant, à partir des matières que KLASSCI
     * vient de lui reconnaître.
     *
     * @param  list<int>  $klassciMatiereIds  Identifiants KLASSCI, tels que
     *                                        rapportés par l'endpoint `matieres`.
     */
    public function link(User $teacher, array $klassciMatiereIds): void
    {
        $klassciEnseignantId = $teacher->klassci_enseignant_id;

        // Sans identité KLASSCI, une ligne serait orpheline : personne ne
        // pourrait plus la retrouver, et elle fausserait les comptes.
        if (! is_numeric($klassciEnseignantId) || $teacher->institution_id === null) {
            return;
        }

        // ⚠️ Une liste VIDE ne dit PAS « cet enseignant n'enseigne plus rien ».
        // Elle dit « KLASSCI n'a rien répondu » — appel dégradé, 503, jeton
        // expiré. Désactiver sur cette base transformerait ce service en
        // effaceur, exactement comme `StaleSeanceArchiver` archivait tout un
        // établissement parce qu'une clé morte rendait toujours `[]`.
        if ($klassciMatiereIds === []) {
            return;
        }

        $enseignantId = (int) $klassciEnseignantId;
        $institutionId = $teacher->institution_id;

        foreach (array_unique($klassciMatiereIds) as $klassciMatiereId) {
            $this->activate($klassciMatiereId, $enseignantId, $institutionId);
        }

        $this->deactivateAbsent($klassciMatiereIds, $enseignantId, $institutionId);
    }

    /**
     * La clé de rapprochement est EXPLICITE, jamais déléguée à la contrainte :
     * l'unique en base porte sur `(klassci_matiere_id, klassci_enseignant_id,
     * annee_universitaire_id)`, or cette dernière est nulle ici — et SQL ne
     * dédoublonne pas les NULL. S'y fier créerait un doublon à chaque login.
     */
    private function activate(int $klassciMatiereId, int $enseignantId, int $institutionId): void
    {
        DB::table('matiere_enseignant')->updateOrInsert(
            [
                'klassci_matiere_id' => $klassciMatiereId,
                'klassci_enseignant_id' => $enseignantId,
                'institution_id' => $institutionId,
            ],
            [
                'status' => 'active',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Une matière qu'on n'enseigne plus passe `inactive`, sans quoi un
     * enseignant garderait éternellement des classes qui ne sont plus les
     * siennes.
     *
     * Désactiver plutôt que supprimer : la ligne porte un historique, et une
     * réaffectation ultérieure la réactive au lieu d'en recréer une.
     *
     * @param  list<int>  $klassciMatiereIds  Garanti non vide par l'appelant.
     */
    private function deactivateAbsent(array $klassciMatiereIds, int $enseignantId, int $institutionId): void
    {
        DB::table('matiere_enseignant')
            ->where('klassci_enseignant_id', $enseignantId)
            ->where('institution_id', $institutionId)
            ->whereNotIn('klassci_matiere_id', $klassciMatiereIds)
            ->update(['status' => 'inactive', 'updated_at' => now()]);
    }
}

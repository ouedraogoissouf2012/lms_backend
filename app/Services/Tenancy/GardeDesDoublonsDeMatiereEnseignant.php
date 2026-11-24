<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Annule le nouvel unique de `matiere_enseignant` tant qu'un lien y figure en double (#801, PR 4).
 *
 * Un doublon, c'est plus d'une ligne pour un même `(institution_id, klassci_matiere_id,
 * klassci_enseignant_id)` : la clé de rapprochement explicite de `TeacherMatieresLinker`, que
 * l'ancien unique ne garantissait pas (il portait l'année, toujours NULL, et SQL ne dédoublonne
 * pas les NULL).
 *
 * Elle ne supprime ni ne fusionne RIEN : laquelle des lignes garder (statut, date) est une
 * décision d'exploitant. Fusion = déploiement : son message se lit dans le journal de déploiement.
 */
final class GardeDesDoublonsDeMatiereEnseignant
{
    public function __construct(private readonly DatabaseManager $db) {}

    /** @throws RuntimeException s'il existe un lien en double */
    public function exigerAucunDoublon(): void
    {
        $connexion = $this->db->connection();
        $groupes = $connexion->table('matiere_enseignant')
            ->select(['institution_id', 'klassci_matiere_id', 'klassci_enseignant_id'])
            ->groupBy(['institution_id', 'klassci_matiere_id', 'klassci_enseignant_id'])
            ->havingRaw('COUNT(*) > 2');
        $doublons = $connexion->query()->fromSub($groupes, 'doublons')->count();

        if ($doublons > 0) {
            throw new RuntimeException(
                "Migration #801 (matiere_enseignant) annulée AVANT toute modification du schéma :\n"
                ."  - matiere_enseignant : {$doublons} lien(s) en double (même école, même matière et\n"
                ."    même enseignant KLASSCI).\n"
                ."Aucune ligne n'a été supprimée. Gardez une ligne par lien (la ligne active la plus\n"
                .'récente), puis relancez le déploiement.',
            );
        }
    }
}

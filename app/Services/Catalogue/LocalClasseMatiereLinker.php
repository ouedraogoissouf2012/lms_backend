<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;

/**
 * Rattacher une matière à une classe, et y désigner un formateur (#848).
 *
 * ## Pourquoi `classe_matiere` et SURTOUT pas `matiere_enseignant`
 *
 * L'ADR-848-01 annonçait qu'il faudrait créer des colonnes locales dans
 * `matiere_enseignant`. **C'était faux**, et corrigé dans l'ADR même.
 *
 * `matiere_enseignant` est le MIROIR KLASSCI : ses deux colonnes sont des
 * identifiants KLASSCI, et `KlassciEnrollmentSource` — qui se décrit elle-même
 * comme « cache de sync KLASSCI » — l'interroge par `klassci_enseignant_id`. Y
 * mêler des identités locales referait la faute qu'ADR-803-03 nomme : deux
 * espaces dans une table, puis un composite pour réconcilier après coup.
 *
 * `classe_matiere` est au contraire un pivot ENTIÈREMENT local — `classe_id`,
 * `matiere_id`, `enseignant_id` — et `LocalEnrollmentSource:35-40` le lit DÉJÀ
 * pour répondre « quelles classes ce formateur enseigne-t-il ». Le chemin de
 * lecture existait ; seul l'écrivain manquait, comme pour la Classe en #860.
 *
 * ## Trois appartenances vérifiées, et pas une seule
 *
 * La classe, la matière ET le formateur doivent appartenir à l'établissement
 * courant. Contrôler la classe seule laisserait rattacher la matière d'une
 * autre école — une fuite silencieuse, puisque la ligne écrite paraîtrait
 * normale. Le scope global ne suffit pas : il est fail-OPEN quand aucun tenant
 * n'est résolu.
 *
 * Les trois appartenances vivent dans `AppartenancesALEcole` (#1653), partagées avec
 * `ProgrammeDeLaClasse` qui modifie et retire une matière d'une classe.
 *
 * ## Le rattachement est idempotent, l'affectation ne l'est pas
 *
 * `classe_matiere` porte un unique `(classe_id, matiere_id)` : rejouer le même
 * rattachement met à jour le formateur au lieu d'échouer. C'est le geste voulu —
 * réaffecter un cours est une opération courante, pas une erreur.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalClasseMatiereLinker
{
    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly AppartenancesALEcole $appartenances,
    ) {}

    /**
     * @throws BusinessException
     */
    public function rattacher(int $classeId, int $matiereId, ?int $enseignantId = null): Classe
    {
        $institution = $this->porte->etablissement();

        $classe = $this->appartenances->classe($classeId, $institution);
        $this->appartenances->matiere($matiereId, $institution);

        // L'école explicitement : le pivot ne passe pas par `BelongsToInstitution`, et la ligne
        // s'écrivait sans elle (#801). `ClasseMatiere` la dériverait de la classe ; elle est
        // posée ici quand même, là où l'écrivain la connaît.
        $classe->matieres()->syncWithoutDetaching([
            $matiereId => [
                'enseignant_id' => $this->appartenances->formateur($enseignantId, $institution),
                'institution_id' => $institution,
            ],
        ]);

        return $classe;
    }
}

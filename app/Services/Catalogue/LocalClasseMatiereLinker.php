<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use App\Services\TenantManager;

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
 * Le message d'erreur ne distingue PAS « n'existe pas » de « appartient à
 * quelqu'un d'autre » : les séparer dirait à un établissement quels
 * identifiants existent chez ses voisins.
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
        private readonly CatalogueAuthority $autorite,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * @throws BusinessException
     */
    public function rattacher(int $classeId, int $matiereId, ?int $enseignantId = null): Classe
    {
        if (! $this->autorite->allowsLocalCatalogue()) {
            throw new BusinessException(
                'Le programme de cet établissement vient de KLASSCI et ne se compose pas ici.',
                403
            );
        }

        $institution = $this->tenants->id();

        if ($institution === null) {
            throw new BusinessException('Aucun établissement résolu.', 409);
        }

        $classe = $this->saClasse($classeId, $institution);
        $this->saMatiere($matiereId, $institution);

        $classe->matieres()->syncWithoutDetaching([
            $matiereId => ['enseignant_id' => $this->formateur($enseignantId, $institution)],
        ]);

        return $classe;
    }

    /**
     * `withoutGlobalScopes` parce que le scope multi-tenant est fail-OPEN : on
     * borne ici, explicitement, plutôt que de dépendre d'une garde qui se tait
     * quand aucun tenant n'est résolu.
     *
     * Deux méthodes jumelles plutôt qu'une générique : celle-ci rendait `mixed`,
     * et PHPStan n9 l'a refusée à juste titre — le gain d'une ligne partagée ne
     * valait pas la perte du type sur le chemin d'une garde d'accès.
     *
     * @throws BusinessException
     */
    private function saClasse(int $id, int $institution): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()
            ->where('id', $id)
            ->where('institution_id', $institution)
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        return $classe;
    }

    /**
     * @throws BusinessException
     */
    private function saMatiere(int $id, int $institution): Matiere
    {
        $matiere = Matiere::query()->withoutGlobalScopes()
            ->where('id', $id)
            ->where('institution_id', $institution)
            ->first();

        if (! $matiere instanceof Matiere) {
            throw new BusinessException('Matière introuvable dans cet établissement.', 404);
        }

        return $matiere;
    }

    /**
     * Le formateur est FACULTATIF — on compose souvent un programme avant de
     * savoir qui l'assurera. Mais s'il est nommé, il doit être de la maison et
     * enseigner : `classe_matiere.enseignant_id` ne désigne qu'UNE personne par
     * couple, et y laisser entrer un étudiant lui ouvrirait la classe.
     *
     * @throws BusinessException
     */
    private function formateur(?int $enseignantId, int $institution): ?int
    {
        if ($enseignantId === null) {
            return null;
        }

        $user = User::query()->withoutGlobalScopes()
            ->where('id', $enseignantId)
            ->where('institution_id', $institution)
            ->first();

        if (! $user instanceof User || Role::tryFromString($user->role) !== Role::Enseignant) {
            throw new BusinessException('Formateur introuvable dans cet établissement.', 404);
        }

        return $enseignantId;
    }
}

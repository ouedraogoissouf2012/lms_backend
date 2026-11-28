<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;

/**
 * Les gestes communs sur l'adhésion d'un élève à une classe LOCALE de son école (#1619), un seul exemplaire pour la
 * mutation (#1652), l'inscription directe et le retrait.
 *
 * - La classe et l'élève sont bornés EXPLICITEMENT à l'école. Absents ou d'ailleurs, ils répondent le MÊME 404 : le
 *   corps ne dit pas lequel des deux manque, ni qu'il existe ailleurs.
 * - Une classe miroir de KLASSCI répond 403 `catalogue_managed_by_klassci`.
 * - Une adhésion se ferme `inactif`, datée (`ends_on`, ADR-711-02), avec son motif (`motif_fin`).
 */
final class AdhesionDeLEcole
{
    public const INTROUVABLE = 'Classe ou élève introuvable dans cet établissement.';

    private const REFUS_MIROIR = 'Cette classe vient de KLASSCI : ses élèves se gèrent dans KLASSCI.';

    /** @throws BusinessException 404 hors de l'école, 403 classe miroir */
    public function classeLocale(int $ecole, int $classeId): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()->whereKey($classeId)->where('institution_id', $ecole)->first();
        if (! $classe instanceof Classe) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }
        if ($classe->klassci_id !== null) {
            throw new BusinessException(self::REFUS_MIROIR, 403, reason: PorteDuCatalogueLocal::REASON);
        }

        return $classe;
    }

    /** @throws BusinessException 404 hors de l'école */
    public function eleve(int $ecole, int $eleveId): User
    {
        $eleve = User::query()->withoutGlobalScopes()->whereKey($eleveId)->where('institution_id', $ecole)->first();
        if (! $eleve instanceof User) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }

        return $eleve;
    }

    /** @throws BusinessException 404 sans adhésion, 409 `enrolment_not_active` */
    public function exigerActive(Classe $classe, User $eleve): void
    {
        $statut = $classe->etudiants()->newPivotStatementForId($eleve->id)->value('statut');

        if ($statut === null) {
            throw new BusinessException('Cet élève n\'est pas inscrit dans cette classe.', 404);
        }

        if ($statut !== ClasseEtudiantStatut::Actif->value) {
            throw new BusinessException('L\'inscription de cet élève dans cette classe n\'est pas active.', 409, reason: InscriptionNonActive::REASON);
        }
    }

    /** Ferme l'adhésion : `inactif`, datée d'aujourd'hui, avec son motif. */
    public function clore(Classe $classe, User $eleve, string $motif): void
    {
        $classe->etudiants()->newPivotStatementForId($eleve->id)->update([
            'statut' => ClasseEtudiantStatut::Inactif->value,
            'ends_on' => now()->toDateString(),
            'motif_fin' => $motif,
            'updated_at' => now(),
        ]);
    }
}

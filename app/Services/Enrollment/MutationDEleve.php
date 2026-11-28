<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Database\DatabaseManager;

/**
 * Un élève change de classe dans son école (#1652, PR C), en UNE transaction.
 *
 * 1. Entrer dans la cible, par {@see AdmissionParLEcole} : archive, Période close, déjà
 *    inscrit, capacité.
 * 2. Fermer l'adhésion source : `inactif`, datée (`ends_on`, ADR-711-02), motif `mutation`.
 *
 * Un refus annule tout : rien ne change ni à la source ni à la cible. Les deux classes sont
 * verrouillées dans l'ordre de leur identifiant, pour que deux mutations croisées ne
 * s'attendent pas l'une l'autre.
 *
 * Le droit vient de la capacité (`PorteDuCatalogueLocal`) : école KLASSCI 403 ; une classe
 * miroir de KLASSCI aussi. Classes et élève sont bornés EXPLICITEMENT à l'école : une
 * classe ou un élève d'ailleurs répond 404.
 */
final class MutationDEleve
{
    public const MOTIF = 'mutation';

    private const REFUS_MIROIR = 'Cette classe vient de KLASSCI : ses élèves se gèrent dans KLASSCI.';

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly AdmissionParLEcole $admission,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{eleve_id: int, depuis: int, vers: int}
     *
     * @throws BusinessException 403, 404, 409 (`enrolment_not_active` et ceux de l'admission)
     */
    public function muter(int $sourceId, int $eleveId, int $cibleId): array
    {
        $ecole = $this->porte->etablissement();
        $source = $this->classeLocale($ecole, $sourceId);
        $cible = $this->classeLocale($ecole, $cibleId);
        $eleve = User::query()->withoutGlobalScopes()->whereKey($eleveId)->where('institution_id', $ecole)->first();

        if (! $eleve instanceof User) {
            throw new BusinessException('Élève introuvable dans cet établissement.', 404);
        }

        $this->db->transaction(function () use ($source, $cible, $eleve): void {
            Classe::query()->withoutGlobalScopes()->whereKey([$source->id, $cible->id])->orderBy('id')->lockForUpdate()->get();
            $this->exigerUneAdhesionActive($source, $eleve);
            $this->admission->admettre($eleve, $cible);

            $source->etudiants()->newPivotStatementForId($eleve->id)->update([
                'statut' => ClasseEtudiantStatut::Inactif->value,
                'ends_on' => now()->toDateString(),
                'motif_fin' => self::MOTIF,
                'updated_at' => now(),
            ]);
        });

        return ['eleve_id' => $eleve->id, 'depuis' => $source->id, 'vers' => $cible->id];
    }

    /** @throws BusinessException 404 sans adhésion, 409 `enrolment_not_active` */
    private function exigerUneAdhesionActive(Classe $source, User $eleve): void
    {
        $statut = $source->etudiants()->newPivotStatementForId($eleve->id)->value('statut');

        if ($statut === null) {
            throw new BusinessException('Cet élève n\'est pas inscrit dans cette classe.', 404);
        }

        if ($statut !== ClasseEtudiantStatut::Actif->value) {
            throw new BusinessException(
                'L\'inscription de cet élève dans cette classe n\'est pas active.',
                409,
                reason: InscriptionNonActive::REASON,
            );
        }
    }

    /** @throws BusinessException 404 hors de l'école, 403 classe miroir */
    private function classeLocale(int $ecole, int $classeId): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()
            ->where('id', $classeId)
            ->where('institution_id', $ecole)
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        if ($classe->klassci_id !== null) {
            throw new BusinessException(self::REFUS_MIROIR, 403, reason: PorteDuCatalogueLocal::REASON);
        }

        return $classe;
    }
}

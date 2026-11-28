<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Exceptions\BusinessException;
use App\Models\Classe;
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
 * classe ou un élève d'ailleurs répond 404. Ces gestes vivent dans {@see AdhesionDeLEcole},
 * partagés avec l'inscription et le retrait par l'administration (#1619).
 */
final class MutationDEleve
{
    public const MOTIF = 'mutation';

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly AdhesionDeLEcole $adhesions,
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
        $source = $this->adhesions->classeLocale($ecole, $sourceId);
        $cible = $this->adhesions->classeLocale($ecole, $cibleId);
        $eleve = $this->adhesions->eleve($ecole, $eleveId);

        $this->db->transaction(function () use ($source, $cible, $eleve): void {
            Classe::query()->withoutGlobalScopes()->whereKey([$source->id, $cible->id])->orderBy('id')->lockForUpdate()->get();
            $this->adhesions->exigerActive($source, $eleve);
            $this->admission->admettre($eleve, $cible);
            $this->adhesions->clore($source, $eleve, self::MOTIF);
        });

        return ['eleve_id' => $eleve->id, 'depuis' => $source->id, 'vers' => $cible->id];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;

/**
 * Archiver une classe LOCALE, et la désarchiver (#1652, PR B).
 *
 * ## Archiver ferme la porte, n'expulse personne
 *
 * La classe reçoit sa date d'archive et son code d'inscription est retiré dans la même
 * écriture : la valeur du code DEMEURE (#846, elle garde son unicité), seule la date de retrait
 * est posée. `classe_etudiant` n'est pas touchée : élèves, notes et présences restent lisibles.
 * Archiver deux fois garde la PREMIÈRE date : un second envoi n'efface pas l'histoire.
 *
 * ## Désarchiver ne rouvre pas le code
 *
 * La date s'efface ; le code reste retiré. Rouvrir les inscriptions est un geste distinct et
 * explicite (émettre un nouveau code), pas l'effet de bord d'un désarchivage : l'ancien code a
 * pu circuler pendant que la classe était close.
 *
 * ## Le droit vient de la capacité, jamais du mode
 *
 * `PorteDuCatalogueLocal` (#910) : une école KLASSCI reçoit 403. Une classe miroir de KLASSCI,
 * dans une école qui tient son catalogue, aussi : elle se gère dans KLASSCI. Une classe d'une
 * autre école : 404, bornée explicitement (le scope multi-tenant est fail-OPEN).
 */
final class ArchiveDeClasse
{
    private const REFUS_MIROIR = 'Cette classe vient de KLASSCI : elle s\'archive dans KLASSCI.';

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * @throws BusinessException 403 école KLASSCI ou classe miroir, 404 hors de l'école
     */
    public function archiver(int $classeId): Classe
    {
        $classe = $this->saClasse($classeId);

        if ($classe->estArchivee()) {
            return $classe;
        }

        $classe->archivee_le = now();

        if ($classe->code_inscription !== null && $classe->code_inscription_revoque_le === null) {
            $classe->code_inscription_revoque_le = now();
        }

        $classe->save();

        return $classe;
    }

    /**
     * @throws BusinessException 403 école KLASSCI ou classe miroir, 404 hors de l'école
     */
    public function desarchiver(int $classeId): Classe
    {
        $classe = $this->saClasse($classeId);
        $classe->archivee_le = null;
        $classe->save();

        return $classe;
    }

    /**
     * @throws BusinessException
     */
    private function saClasse(int $classeId): Classe
    {
        $ecole = $this->porte->etablissement();

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

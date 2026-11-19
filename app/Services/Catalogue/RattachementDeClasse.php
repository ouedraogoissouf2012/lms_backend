<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\TrainingSession;
use Illuminate\Validation\ValidationException;

/**
 * Rattacher une classe à une Période de formation (#1208, point 1).
 *
 * `classes.training_session_id` existait sans écrivain : les règles qui le lisent (une
 * Période close ferme les codes de ses classes, #897 ; `is_active` d'une classe) ne
 * s'appliquaient donc jamais. Ce service est leur écrivain, à la création d'une classe
 * et par un geste NOMMÉ pour les classes déjà créées.
 *
 * Il passe par la porte du catalogue au point d'écriture : une école KLASSCI est
 * refusée (403 `catalogue_managed_by_klassci`), comme tout le catalogue local (#1184).
 *
 * ## Une Période refusée est une saisie invalide
 *
 * Une Période d'un autre établissement, inexistante, ou qui ne reçoit plus
 * d'inscriptions (annulée, close, archivée, purgée) rend le MÊME 422 sur
 * `training_session_id`. Le même message pour toutes ces causes : un identifiant de
 * Période d'une autre école ne se distingue pas d'un identifiant inexistant.
 *
 * Détacher (`null`) est toujours permis, et les inscriptions déjà faites sont
 * conservées : rien ici ne touche `classe_etudiant`.
 */
final class RattachementDeClasse
{
    public const REFUS_PERIODE = 'Cette période n\'existe pas dans l\'établissement, ou ne reçoit plus d\'inscriptions.';

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * Rattache la classe à la Période, ou l'en détache (`null`).
     *
     * @throws BusinessException 403 en école KLASSCI, 404 pour une classe hors de l'établissement.
     * @throws ValidationException 422 sur `training_session_id`.
     */
    public function rattacher(int $classeId, ?int $periodeId): Classe
    {
        $ecole = $this->porte->etablissement();

        $classe = Classe::query()->withoutGlobalScope('institution')
            ->where('id', $classeId)
            ->where('institution_id', $ecole)
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        // Par les attributs : la colonne est non signée pour Larastan, et l'identifiant
        // d'une Période un `int` (#1208).
        $classe->update(['training_session_id' => $this->periodeQuiRecoit($ecole, $periodeId)]);

        return $classe;
    }

    /**
     * La Période, si elle est de cet établissement et reçoit encore des inscriptions.
     *
     * @throws ValidationException 422 sur `training_session_id`.
     */
    public function periodeQuiRecoit(int $ecole, ?int $periodeId): ?int
    {
        if ($periodeId === null) {
            return null;
        }

        $periode = TrainingSession::query()->withoutGlobalScope('institution')
            ->where('id', $periodeId)
            ->where('institution_id', $ecole)
            ->first();

        if (! $periode instanceof TrainingSession) {
            throw ValidationException::withMessages(['training_session_id' => self::REFUS_PERIODE]);
        }

        return $periode->id;
    }
}

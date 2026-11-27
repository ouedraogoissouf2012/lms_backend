<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Exceptions\BusinessException;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;

/**
 * La période de l'établissement de l'acteur, ou rien (#845, #1651).
 *
 * D'abord la porte du catalogue local (#1184) : une école KLASSCI reçoit
 * `catalogue_managed_by_klassci`, et l'établissement est celui de l'acteur.
 *
 * Le scope multi-tenant est fail-OPEN : il est retiré, et l'école est filtrée EXPLICITEMENT. Seul
 * ce scope-là l'est : une période supprimée en douceur (#1651) est introuvable, comme une période
 * d'une autre école. Le message ne distingue pas les deux cas : les séparer dirait à un
 * établissement quels identifiants existent chez ses voisins.
 */
final class PeriodesDeLEcole
{
    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /** @throws BusinessException */
    public function sienne(User $acteur, int $periodeId): TrainingSession
    {
        $periode = TrainingSession::query()->withoutGlobalScope('institution')
            ->whereKey($periodeId)
            ->where('institution_id', $this->porte->etablissementDe($acteur))
            ->first();

        if (! $periode instanceof TrainingSession) {
            throw new BusinessException('Période introuvable dans cet établissement.', 404);
        }

        return $periode;
    }
}

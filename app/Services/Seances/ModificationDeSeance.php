<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;

/**
 * Modifier une séance (`PUT|PATCH /lms/seances/{seanceId}`, #1121) : même route,
 * autre source selon l'école (ADR-EI-01), décision du user du 17/11.
 *
 * - École autonome : {@see LocalModificationDeSeance}, l'enseignant ne dépend
 *   d'aucun KLASSCI.
 * - École KLASSCI : {@see KlassciModificationDeSeance}, la séance se modifie dans
 *   KLASSCI et le LMS la récupère ; le LMS refuse.
 *
 * Liée par `CatalogueServiceProvider::SOURCES` ; le contrôleur la reçoit par sa
 * MÉTHODE (Laravel garde le contrôleur sur l'objet Route).
 */
interface ModificationDeSeance
{
    /**
     * Modifie la séance de l'école du compte et la rend relue.
     *
     * @param  array<string, mixed>  $champs  validés par `UpdateLocalSeanceRequest`
     *
     * @throws BusinessException le refus, avec son statut et son motif
     */
    public function modifier(int $seanceId, User $acteur, array $champs): Seance;

    /**
     * Le marqueur `can_be_edited` d'une carte : ce que {@see modifier()} accepterait
     * de ce lecteur, hors validation des champs.
     */
    public function peutModifier(Seance $ligne, User $lecteur): bool;
}

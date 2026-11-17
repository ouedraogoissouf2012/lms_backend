<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;

/**
 * Créer une séance (`POST /lms/seances`) : même route, autre source selon l'école
 * (ADR-EI-01), décision du user du 17/11 (#1182).
 *
 * - École autonome : {@see LocalSeanceCreator}, la création locale (#710, #846).
 * - École KLASSCI : {@see KlassciCreationDeSeance}, « on crée la séance depuis
 *   KLASSCI, et elle est juste récupérée par le LMS » ; le LMS refuse.
 *
 * Liée par `CatalogueServiceProvider::SOURCES` ; le contrôleur la reçoit par sa
 * MÉTHODE (Laravel garde le contrôleur sur l'objet Route).
 */
interface CreationDeSeance
{
    /**
     * @param  array<string, mixed>  $payload  validé par `StoreLocalSeanceRequest`
     *
     * @throws BusinessException le refus, avec son statut et son motif
     */
    public function create(User $author, array $payload): Seance;
}

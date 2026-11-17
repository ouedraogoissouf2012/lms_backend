<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;

/**
 * École KLASSCI (#1182) : la séance se crée dans KLASSCI, le LMS la récupère, sans
 * plus (décision du user du 17/11). La création locale est refusée, rien n'est
 * écrit.
 *
 * Le motif est celui de la modification ({@see KlassciModificationDeSeance}, #1121) :
 * un concept — les séances de cette école vivent dans KLASSCI —, un motif.
 */
final class KlassciCreationDeSeance implements CreationDeSeance
{
    public function create(User $author, array $payload): Seance
    {
        throw new BusinessException(
            'Les séances de cette école se créent et se modifient dans KLASSCI.',
            403,
            null,
            KlassciModificationDeSeance::REASON,
        );
    }
}

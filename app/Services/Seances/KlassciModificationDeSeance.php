<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;

/**
 * École KLASSCI (#1121) : la séance se crée et se modifie dans KLASSCI, le LMS la
 * récupère, sans plus (décision du user du 17/11). Toute séance de l'école est
 * refusée, y compris une séance créée dans le LMS : la règle porte sur l'école,
 * pas sur la provenance de la ligne. Rien n'est écrit.
 */
final class KlassciModificationDeSeance implements ModificationDeSeance
{
    public const REASON = 'seance_managed_by_klassci';

    public function __construct(private readonly KlassciSeanceDesignee $seances) {}

    public function modifier(int $seanceId, User $acteur, array $champs): Seance
    {
        if (! $this->seances->pour($seanceId, $acteur) instanceof Seance) {
            throw new BusinessException('Séance non trouvée', 404);
        }

        throw new BusinessException('Cette séance se modifie dans KLASSCI.', 403, null, self::REASON);
    }

    public function peutModifier(Seance $ligne, User $lecteur): bool
    {
        return false;
    }
}

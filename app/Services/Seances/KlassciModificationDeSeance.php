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
 *
 * Aucune ligne n'est donc DÉSIGNÉE : la seule question est « cet id est-il une
 * séance de l'école ? », dans l'un ou l'autre espace ({@see Seance::existsFor()},
 * borné à l'école). Sur une collision (l'id local de A est le `klassci_seance_id`
 * de B), la réponse est la même, 403 : aucune séance ne peut être la mauvaise
 * (#1566, qui retire ici l'ancienne désignation sur les deux espaces).
 */
final class KlassciModificationDeSeance implements ModificationDeSeance
{
    public const REASON = 'seance_managed_by_klassci';

    public function modifier(int $seanceId, User $acteur, array $champs): Seance
    {
        if (! is_int($acteur->institution_id) || ! Seance::existsFor($seanceId, $acteur->institution_id)) {
            throw new BusinessException('Séance non trouvée', 404);
        }

        throw new BusinessException('Cette séance se modifie dans KLASSCI.', 403, null, self::REASON);
    }

    public function peutModifier(Seance $ligne, User $lecteur): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Attendances\Heures;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Les heures des enseignants (#1682), nommées par ce qu'elles font, jamais par le mode : une école créée depuis le LMS
 * les tient dans le LMS ({@see LocalHeuresDeLEnseignant}), une école KLASSCI dans KLASSCI
 * ({@see KlassciHeuresDeLEnseignant}). La règle de comptage est UNE : {@see HeuresEmargees}.
 */
interface HeuresDeLEnseignant
{
    /**
     * Les séances passées du mois de l'enseignant, leur statut et leurs minutes, et les totaux.
     *
     * @return array{mois: string, depuis: ?string, seances: list<array{seance_id: int, titre: ?string, date_seance: ?string, statut: string, minutes: int}>, totaux: array{minutes: int, comptees: int, non_comptees: int}}
     *
     * @throws BusinessException
     */
    public function mesHeures(User $enseignant, string $mois): array;

    /**
     * Par enseignant : séances, séances comptées, minutes.
     *
     * @return array{du: string, au: string, depuis: ?string, enseignants: list<array{enseignant_id: int, nom: ?string, seances: int, comptees: int, minutes: int}>}
     *
     * @throws BusinessException
     */
    public function rapport(User $gestion, FiltresDHeures $filtres): array;
}

<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\User;
use App\Services\Seances\EmploiTempsSeanceLocator;
use App\Services\Seances\KlassciPayload;

/**
 * École KLASSCI : la séance sans ligne locale est cherchée dans l'emploi du temps, comme
 * le détail la cherche ({@see EmploiTempsSeanceLocator}, #1094). Le localisateur lit la
 * liste de matières du rôle : le formateur, son tableau de bord ; la gestion, le
 * catalogue. Il ne rend donc que ce que le détail rend déjà à cet appelant.
 */
final class KlassciSeanceSansLigneLocale implements SeanceSansLigneLocale
{
    public function __construct(private readonly EmploiTempsSeanceLocator $locator) {}

    public function decrire(int $seanceId, User $user): ?array
    {
        $jeton = $user->klassci_token;
        [$seance, $matiere] = $this->locator->locate($seanceId, $user, is_string($jeton) ? $jeton : null);
        if ($seance === null) {
            return null;
        }
        $programmation = KlassciPayload::asArray($seance['programmation'] ?? null);

        return [
            'klassci_seance_id' => $seanceId,
            'enseignant_nom' => KlassciPayload::toStringOrNull(KlassciPayload::asArray($seance['enseignant'] ?? null)['nom'] ?? null),
            'matiere_nom' => KlassciPayload::toStringOrNull(KlassciPayload::asArray($seance['matiere'] ?? null)['nom'] ?? ($matiere['nom'] ?? null)),
            'date' => KlassciPayload::toStringOrNull($programmation['date'] ?? $programmation['date_seance'] ?? $programmation['date_cours'] ?? null),
        ];
    }
}

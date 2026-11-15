<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;

/**
 * L'identité d'une séance KLASSCI (matière, classe, noms), lue dans l'emploi du temps.
 *
 * Il n'y a pas d'endpoint KLASSCI `GET /seances/{id}`. La bascule visio d'une séance
 * sans ligne locale la cherchait dans `matieres/{id}.data.seances_programmees`, une
 * clé que KLASSCI laisse toujours vide : sa classe restait inconnue, et aucun élève
 * n'était averti (D610). Elle passe par {@see EmploiTempsSeanceLocator}, qui lit pour
 * la gestion le catalogue `matieres`, comme avant : aucun élargissement.
 *
 * L'identifiant d'enseignant n'est PAS lu : `emploi-temps` porte `enseignant.id` et
 * `enseignant.teacher_id`, et rien n'établit lequel est celui qu'écrit la
 * synchronisation (le `klassci_id` de l'utilisateur, `SeanceUpsertService`). Le
 * deviner ferait mentir le filtre de la gestion par enseignant (#1078).
 */
final class SeanceDeLEmploiDuTemps
{
    public function __construct(private readonly EmploiTempsSeanceLocator $locator) {}

    /**
     * @return array{matiere_id: int|null, matiere_nom: string|null, classe_id: int|null, enseignant_nom: string|null}|null
     */
    public function identite(int $seanceId, User $user, string $klassciToken): ?array
    {
        [$seance, $matiere] = $this->locator->locate($seanceId, $user, $klassciToken);
        if ($seance === null) {
            return null;
        }
        $matiereDeLaSeance = KlassciPayload::asArray($seance['matiere'] ?? null);
        $matiere ??= [];

        return [
            'matiere_id' => KlassciPayload::toInt($matiereDeLaSeance['id'] ?? $matiere['id'] ?? null),
            'matiere_nom' => KlassciPayload::toStringOrNull($matiereDeLaSeance['nom'] ?? $matiere['nom'] ?? null),
            'classe_id' => KlassciPayload::toInt(KlassciPayload::asArray($seance['classe'] ?? null)['id'] ?? null),
            'enseignant_nom' => KlassciPayload::toStringOrNull(KlassciPayload::asArray($seance['enseignant'] ?? null)['nom'] ?? null),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\RecordingFailureReason;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\LocalSeanceCreator;

/**
 * Les cibles d'une séance d'école AUTONOME (#1483) : ses colonnes locales, celles qu'écrit
 * {@see LocalSeanceCreator}. Rien n'y est jamais rangé dans `klassci_*`.
 *
 * ## L'enseignant est `created_by`
 *
 * La règle de `VisioActorAuthorization::teacherOwns` et de `SeanceRecordingAccessService` :
 * pour une séance sans identité KLASSCI, son propriétaire est son auteur. Un auteur absent ou
 * hors de l'école donne `[]`, donc AUCUN candidat : jamais une recherche élargie, qui
 * enverrait la vidéo dans le cours d'un collègue de la même matière (#707).
 */
final class LocaleCiblesDeLaSeance implements CiblesDeLaSeance
{
    public function matiere(Seance $seance): Matiere|RecordingFailureReason
    {
        if ($seance->matiere_id === null) {
            return RecordingFailureReason::MissingLocalMatiereId;
        }

        return Matiere::query()
            ->where('institution_id', $seance->institution_id)
            ->whereKey($seance->matiere_id)
            ->first() ?? RecordingFailureReason::MatiereNotFound;
    }

    public function classe(Seance $seance): ?Classe
    {
        if ($seance->classe_id === null) {
            return null;
        }

        return Classe::query()
            ->where('institution_id', $seance->institution_id)
            ->whereKey($seance->classe_id)
            ->first();
    }

    /**
     * Jamais `null` : une séance locale déclare toujours son enseignant, son auteur.
     *
     * @return list<int>
     */
    public function enseignants(Seance $seance): array
    {
        $auteur = is_numeric($seance->created_by)
            ? User::query()->where('institution_id', $seance->institution_id)->whereKey((int) $seance->created_by)->value('id')
            : null;

        return is_numeric($auteur) ? [(int) $auteur] : [];
    }
}

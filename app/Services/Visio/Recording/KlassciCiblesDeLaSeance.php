<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\RecordingFailureReason;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;

/**
 * Les cibles d'une séance d'école KLASSCI : l'espace KLASSCI (`klassci_*`).
 *
 * Le code est celui que portait {@see SeanceRecordingAttachmentResolver} avant #1483, déplacé
 * sans changement de règle.
 */
final class KlassciCiblesDeLaSeance implements CiblesDeLaSeance
{
    public function matiere(Seance $seance): Matiere|RecordingFailureReason
    {
        if ($seance->klassci_matiere_id === null) {
            return RecordingFailureReason::MissingKlassciMatiereId;
        }

        return Matiere::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_id', $seance->klassci_matiere_id)
            ->first() ?? RecordingFailureReason::MatiereNotFound;
    }

    public function classe(Seance $seance): ?Classe
    {
        if ($seance->klassci_classe_id === null) {
            return null;
        }

        return Classe::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_id', $seance->klassci_classe_id)
            ->first();
    }

    /**
     * ## Le défaut corrigé (#707)
     *
     * Cette lecture renvoyait l'identifiant KLASSCI BRUT puis y ajoutait les `users.id`
     * locaux, et le tout était confronté à `lessons.enseignant_id`, un `users.id` local :
     * deux espaces d'identifiants dans la même comparaison. Un collègue dont le `users.id`
     * égalait le `klassci_enseignant_id` de la séance recevait la vidéo dans son cours.
     */
    public function enseignants(Seance $seance): ?array
    {
        if ($seance->klassci_enseignant_id === null) {
            return null;
        }

        $ids = [];
        foreach (User::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_enseignant_id', (int) $seance->klassci_enseignant_id)
            ->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

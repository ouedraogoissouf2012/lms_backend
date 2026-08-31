<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\Seance;
use App\Models\SeanceRecording;

/**
 * #469 — traduit un **salon** visio en enregistrement actif.
 *
 * ## Pourquoi ce pont existe
 *
 * Le fournisseur d'enregistrement connaît le salon et rien d'autre :
 * `recording_id` est un entier de notre base, qu'il n'a aucun moyen d'apprendre.
 * Exiger cet identifiant dans la notification reviendrait à demander au
 * fournisseur de connaître notre schéma.
 *
 * ## Refuser plutôt que choisir
 *
 * Zéro correspondance et plusieurs correspondances renvoient toutes deux `null`.
 * Trancher au hasard entre deux enregistrements actifs rattacherait peut-être
 * l'enregistrement d'un cours à un autre — et, dans le cas pathologique de deux
 * établissements partageant un salon, le cours d'une école à une autre. Un refus
 * laisse le fichier intact et l'anomalie visible.
 *
 * ## Absence de tenant, et pourquoi le cloisonnement tient quand même
 *
 * Cette requête s'exécute **sans tenant résolu** : la route du webhook est
 * authentifiée par HMAC et non par jeton porteur, donc `ResolveInstitution` ne
 * pose aucune institution. Le `withoutGlobalScope` est ici explicite plutôt que
 * subi — c'est déjà le choix du service appelant.
 *
 * Le cloisonnement repose alors sur la donnée : `visio_room_id` vaut `lms_` + 20
 * octets tirés de `random_bytes()`, soit 160 bits. Un salon n'appartient qu'à une
 * séance, donc à un seul établissement. Et connaître un salon ne suffit pas : il
 * faut aussi le secret HMAC.
 *
 * @see \App\Services\Visio\SecureVisioRoomIdGenerator
 * @see \App\Services\Visio\Recording\SeanceRecordingWebhookService
 */
final class RoomRecordingResolver
{
    /**
     * L'unique enregistrement actif de ce salon, ou `null` s'il n'y en a pas
     * exactement un.
     */
    public function resolve(string $room): ?SeanceRecording
    {
        // Un salon vide ferait correspondre les séances dont `visio_room_id` est
        // vide ou nul selon le moteur : on n'interroge jamais la base avec ça.
        if (trim($room) === '') {
            return null;
        }

        // Deux temps plutôt qu'un `whereHas` : le trajet salon → séances →
        // enregistrements se lit dans l'ordre où on le raisonne, et la seconde
        // requête n'est même pas émise quand le salon n'existe pas. Les deux
        // colonnes interrogées sont indexées (`visio_room_id` depuis #469,
        // `seance_id` par sa clé étrangère).
        $seanceIds = Seance::withoutGlobalScope('institution')
            ->where('visio_room_id', $room)
            ->pluck('id');

        if ($seanceIds->isEmpty()) {
            return null;
        }

        $matches = SeanceRecording::withoutGlobalScope('institution')
            ->whereIn('seance_id', $seanceIds)
            ->whereIn('status', SeanceRecordingStatus::activeValues())
            // Deux suffisent pour trancher : inutile de charger davantage pour
            // conclure « plus d'un ».
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}

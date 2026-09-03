<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;

/**
 * La participation visio en cours de L'APPELANT, et de personne d'autre (#673).
 *
 * ## Pourquoi ce service existe
 *
 * La salle est désormais embarquée dans le LMS : un rechargement complet de
 * page la détruit, là où l'onglet séparé y survivait. C'est la régression
 * assumée du chantier. Au démarrage, l'application demande donc au serveur si
 * une participation est ouverte, et remonte la salle le cas échéant.
 *
 * Le serveur fait autorité. Aucune persistance côté client : le dépôt a
 * délibérément démonté celle qui existait, et `ESBTPAttendance` sait déjà qui
 * est entré où.
 *
 * ## La borne, et pourquoi ce n'est PAS la fraîcheur du heartbeat
 *
 * Une ligne peut rester `connected` indéfiniment si le heartbeat meurt — c'est
 * exactement la classe de défaut corrigée par #680 côté enregistrement. Borner
 * la reprise sur `last_seen_at` reviendrait à choisir un délai arbitraire :
 * trop court, on refuse une reprise légitime après une coupure réseau ; trop
 * long, on fait rentrer l'utilisateur de force à chaque chargement de page.
 *
 * La borne retenue est un **fait observable** : la visio de la séance est-elle
 * encore `active` ? Si l'enseignant l'a terminée, il n'y a plus rien à
 * rejoindre, quelle que soit l'heure du dernier heartbeat.
 *
 * ## Pourquoi une sous-requête de modèle, et non une jointure SQL
 *
 * Une jointure brute sur `seances` court-circuite les scopes du modèle, **y
 * compris le SoftDeletes** : une séance supprimée correspondrait encore. La
 * sous-requête Eloquent les applique, sans rien à retenir.
 *
 * Elle évite en outre un faux positif de larastan : `seances.institution_id`
 * est ajoutée par une migration qui BOUCLE sur une liste de tables
 * (`2026_02_11_000002_add_institution_id_to_all_tables`), donc invisible à
 * l'analyse statique — alors que la colonne existe bel et bien, vérifié en
 * production et sur base fraîchement migrée.
 *
 * Le cloisonnement, lui, ne repose PAS sur le scope global d'institution —
 * fail-open par conception quand aucun tenant n'est résolu. Il repose sur le
 * filtrage EXPLICITE par l'institution de l'utilisateur authentifié, posé des
 * deux côtés.
 *
 * @see \Tests\Feature\LMS\Visio\ActiveVisioParticipationTest
 */
final class ActiveVisioParticipationResolver
{
    private const CONNECTED = 'connected';

    private const VISIO_ACTIVE = 'active';

    /**
     * @return array{seance_id: int}|null
     */
    public function forUser(User $user): ?array
    {
        $institutionId = $user->institution_id;

        // Un utilisateur sans institution ne peut avoir aucune participation
        // cloisonnée : refuser vaut mieux que d'interroger sans borne.
        if (! is_int($institutionId)) {
            return null;
        }

        $seancesEnCours = Seance::query()
            ->where('institution_id', $institutionId)
            ->where('visio_status', self::VISIO_ACTIVE)
            ->select('id');

        $seanceId = ESBTPAttendance::query()
            ->where('user_id', $user->id)
            ->where('institution_id', $institutionId)
            ->where('status', self::CONNECTED)
            ->whereIn('seance_id', $seancesEnCours)
            // La plus récente : un utilisateur ne devrait avoir qu'une
            // participation ouverte, mais un heartbeat perdu peut en laisser
            // traîner une ancienne. On remonte celle qu'il vient de rejoindre.
            ->orderByDesc('joined_at')
            ->value('seance_id');

        return is_numeric($seanceId) ? ['seance_id' => (int) $seanceId] : null;
    }
}

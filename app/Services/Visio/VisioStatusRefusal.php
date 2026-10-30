<?php

declare(strict_types=1);

namespace App\Services\Visio;

/**
 * Le motif d'un refus dû à l'ÉTAT de la visio d'une séance (ADR-906-01).
 *
 * `seances.visio_status` vaut `programmee`, `active`, `terminee`, ou null quand
 * la séance n'a pas de visio. Deux sites refusaient selon cet état, chacun à sa
 * façon :
 *   - `VisioParticipantSessionService::join` rendait un 400 « La visio n'est pas
 *     active », sans motif, pour les trois états non actifs ;
 *   - `ParticipantValidationService::authorize` rendait `visio_not_started`
 *     (« n'a pas encore démarré ») pour tout état hors `active`/`programmee`,
 *     c'est-à-dire pour une visio TERMINÉE : la consigne mentait.
 *
 * Cette table est leur source unique. Un motif dit à l'utilisateur s'il doit
 * attendre (`visio_not_started`), renoncer (`visio_ended`), ou s'il n'y a pas de
 * visio du tout (`visio_not_enabled`). Un état inconnu est traité comme
 * l'absence de visio : jamais un refus sans motif.
 */
final class VisioStatusRefusal
{
    public const NOT_STARTED = 'visio_not_started';

    public const ENDED = 'visio_ended';

    public const NOT_ENABLED = 'visio_not_enabled';

    /** Le motif du refus, ou null quand l'état n'en appelle aucun (visio active). */
    public static function reasonFor(?string $status): ?string
    {
        return match ($status) {
            'active' => null,
            'programmee' => self::NOT_STARTED,
            'terminee' => self::ENDED,
            default => self::NOT_ENABLED,
        };
    }

    /** Le message affiché avec ce motif. */
    public static function messageFor(string $reason): string
    {
        return match ($reason) {
            self::NOT_STARTED => "La visioconférence n'a pas encore démarré",
            self::ENDED => 'La visioconférence est terminée',
            default => 'Visioconférence non activée pour cette séance',
        };
    }
}

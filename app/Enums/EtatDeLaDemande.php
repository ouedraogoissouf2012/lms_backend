<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * L'état d'une demande d'inscription (#1659) : `recue` → `en_examen` → `acceptee` → `inscrite`, ou `rejetee` (avec
 * motif). `acceptee` et `inscrite` sont posés par « Accepter et inscrire » (PR 2).
 */
enum EtatDeLaDemande: string
{
    case Recue = 'recue';
    case EnExamen = 'en_examen';
    case Acceptee = 'acceptee';
    case Inscrite = 'inscrite';
    case Rejetee = 'rejetee';

    /** Une demande encore à traiter. */
    public function aTraiter(): bool
    {
        return $this === self::Recue || $this === self::EnExamen;
    }

    /** Une demande décidée : elle ne change plus, et sa rétention court depuis sa décision. */
    public function decidee(): bool
    {
        return $this === self::Rejetee || $this === self::Inscrite;
    }
}

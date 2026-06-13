<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Observers\AuditableObserver;

/**
 * Marque un modèle comme auditable (#215) : branche l'AuditableObserver qui
 * journalise create/update/delete dans `audit_logs`.
 *
 * Le trait ne contient AUCUNE logique métier — il se contente d'attacher
 * l'observer (infrastructure transversale), conformément à §5 (les modèles
 * ne portent pas de logique). Tout l'enregistrement vit dans l'observer + le
 * service AuditLogger.
 *
 * Usage : `use Auditable;` dans le modèle sensible.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditableObserver::class);
    }
}

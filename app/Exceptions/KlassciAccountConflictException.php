<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée quand le sync KLASSCI détecte qu'un email confirmé pour le compte qui
 * se connecte est déjà détenu localement par un AUTRE compte de la même
 * institution.
 *
 * Comme un email = un compte à vie côté KLASSCI, ce conflit est toujours une
 * anomalie de données (doublon, import incohérent). On échoue proprement (409)
 * plutôt que :
 *   - laisser la contrainte DB remonter en 500 (incident 2026-06-20) ;
 *   - écraser/voler l'email de l'autre compte sur une supposition.
 *
 * La résolution passe par la commande `klassci:reconcile-email-conflicts`
 * (diagnostic) + intervention admin.
 *
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php
 * @see app/Http/Controllers/API/AuthController.php::login
 */
final class KlassciAccountConflictException extends RuntimeException
{
}

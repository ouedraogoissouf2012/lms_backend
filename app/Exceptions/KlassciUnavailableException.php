<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée quand l'API KLASSCI est totalement injoignable (#243) : aucun tenant
 * actif n'a répondu lors de la discovery (toutes ConnectionException — coupure
 * réseau, DNS, service down).
 *
 * À distinguer du cas « identifiants inconnus » (tenants joignables mais aucun
 * match) qui reste un 401. Cette exception, elle, doit se traduire en 503 :
 * la panne est externe et temporaire, pas une erreur de l'utilisateur.
 *
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see app/Http/Controllers/API/AuthController.php::login
 */
final class KlassciUnavailableException extends RuntimeException
{
}

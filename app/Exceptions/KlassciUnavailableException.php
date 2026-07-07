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
 * ## #270 — URL de base absente/invalide
 *
 * Même sémantique 503 quand `KLASSCI_API_URL` (config `services.klassci.url`)
 * est vide ou ne porte pas de scheme http(s) : sans URL exploitable, aucun appel
 * proxy ne peut aboutir. C'est une panne/mauvaise config EXTERNE, pas un bug du
 * serveur LMS — on dégrade donc en 503, jamais en 500. Voir
 * {@see self::missingBaseUrl()} et {@see \App\Services\Klassci\KlassciConfigResolver::requireBaseUrl()}.
 *
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see app/Http/Controllers/API/AuthController.php::login
 */
final class KlassciUnavailableException extends RuntimeException
{
    /**
     * Message générique présenté au client (FR). Source unique partagée par le
     * trait de présentation proxy et le handler global — jamais le body brut
     * KLASSCI ni le détail technique (§1.2 Sécurité Absolue).
     */
    public const CLIENT_MESSAGE = 'Service KLASSCI temporairement indisponible. Veuillez réessayer dans quelques instants.';

    /**
     * Fabrique pour le cas #270 : URL de base KLASSCI absente ou invalide.
     * Le message interne (diagnostic ops, jamais exposé au client) nomme la clé
     * de config à corriger.
     */
    public static function missingBaseUrl(): self
    {
        return new self('URL de base KLASSCI absente ou invalide (config services.klassci.url / KLASSCI_API_URL).');
    }

    public static function upstreamFailure(int $status): self
    {
        return new self("API KLASSCI indisponible ou en erreur serveur ({$status}).", $status);
    }

    public static function retryAfterSeconds(): int
    {
        $value = config('services.klassci.retry_after', 30);

        return is_numeric($value) ? max(1, (int) $value) : 30;
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
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

    public static function circuitOpen(int $secondsUntilRetry): self
    {
        return new self("Circuit KLASSCI ouvert temporairement ({$secondsUntilRetry}s avant retry).", 503);
    }

    /**
     * Panne de TRANSPORT : KLASSCI n'a pas répondu du tout (#685).
     *
     * `ConnectionException` descend de `HttpClientException`, donc d'`Exception`
     * — et non de `RuntimeException`. Relancée telle quelle, elle échappait au
     * `catch (RuntimeException)` des contrôleurs LMS, seul chemin menant à cette
     * réponse canonique, et finissait en 500 muet.
     *
     * Le traitement était donc inversé par rapport à la gravité : KLASSCI
     * répondant 500 produisait un 503 annoncé, KLASSCI ne répondant PAS du tout
     * produisait un écran vide.
     *
     * Le détail réseau reste dans la cause chaînée, pour le journal ; il ne sort
     * jamais vers le client (§1.2).
     */
    public static function transportFailure(\Throwable $cause): self
    {
        return new self("API KLASSCI injoignable (panne de transport) : {$cause->getMessage()}", 503, $cause);
    }

    /**
     * Reponse HTTP canonique d'une indisponibilite KLASSCI.
     *
     * Source unique partagee par le handler global (bootstrap/app.php), le trait
     * proxy et le trait LMS. L'enveloppe etait auparavant reconstruite a chaque
     * appelant : un seul qui oubliait `Retry-After` transformait une panne
     * temporaire en erreur definitive cote client.
     */
    public static function jsonResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::CLIENT_MESSAGE,
        ], 503, [
            'Retry-After' => (string) self::retryAfterSeconds(),
        ]);
    }

    public static function retryAfterSeconds(): int
    {
        $value = config('services.klassci.retry_after', 30);

        return is_numeric($value) ? max(1, (int) $value) : 30;
    }
}

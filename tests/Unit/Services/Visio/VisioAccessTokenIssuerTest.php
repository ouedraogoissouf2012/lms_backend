<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Visio;

use App\Services\Visio\VisioAccessTokenIssuer;
use PHPUnit\Framework\TestCase;

/**
 * Le jeton d'accès à une salle Jitsi.
 *
 * ## Ce que ce jeton porte
 *
 * Depuis le passage de la visio sur `visio.klassci.com` avec `ENABLE_AUTH=1` et
 * `ENABLE_GUESTS=0`, aucune salle ne s'ouvre sans jeton signé. Ce jeton n'est
 * donc pas un confort : c'est la clé, et c'est le LMS qui la taille.
 *
 * ## L'invariant qui compte
 *
 * Le claim `room` porte le cloisonnement entre classes. Un jeton émis pour la
 * séance A ne doit JAMAIS ouvrir la séance B — et surtout jamais valoir `*`,
 * qui ouvrirait toutes les salles de l'établissement.
 *
 * ## Pourquoi la signature est écrite ici plutôt que déléguée
 *
 * On ne fait que SIGNER, jamais vérifier. Les failles classiques des jetons
 * (confusion d'algorithme, algorithme `none`, confusion de clé) vivent toutes
 * du côté de la vérification. Signer en HS256 est un `hash_hmac`, et le dépôt
 * le fait déjà ainsi pour le webhook d'enregistrement
 * ({@see \App\Services\Visio\Recording\SeanceRecordingWebhookService}).
 *
 * @see PRODUCTION_STANDARDS.md §1.2 (sécurité absolue) · §1.6 D (DI stricte)
 */
final class VisioAccessTokenIssuerTest extends TestCase
{
    private const SECRET = '7c6f975c477d82082dc00418276bc8b2e53db075c49eac1c98b3f06b1f2961ab';

    private const APP_ID = 'lms-klassci';

    private const AUDIENCE = 'visio-klassci';

    private function issuer(?string $secret = self::SECRET): VisioAccessTokenIssuer
    {
        return new VisioAccessTokenIssuer(
            appId: self::APP_ID,
            appSecret: $secret,
            audience: self::AUDIENCE,
            xmppDomain: 'meet.jitsi',
            lifetimeSeconds: 7200,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'Un JWT porte exactement trois segments.');

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($json);

        $payload = json_decode($json, true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    // ───────────────────── L'invariant de cloisonnement ─────────────────────

    public function test_the_room_claim_is_the_exact_room_never_a_wildcard(): void
    {
        $token = $this->issuer()->issue('lms_abc123', 'Awa Traoré', 'awa@ecole.ci', false);

        $payload = $this->decodePayload($token);

        self::assertSame('lms_abc123', $payload['room']);
        self::assertNotSame('*', $payload['room'], 'Un joker ouvrirait TOUTES les salles.');
    }

    public function test_two_rooms_never_share_a_token(): void
    {
        $issuer = $this->issuer();

        $a = $this->decodePayload($issuer->issue('lms_classe_a', 'Awa', 'a@e.ci', false));
        $b = $this->decodePayload($issuer->issue('lms_classe_b', 'Awa', 'a@e.ci', false));

        self::assertNotSame($a['room'], $b['room']);
    }

    // ───────────────────── La signature ─────────────────────

    public function test_the_signature_is_verifiable_with_the_shared_secret(): void
    {
        $token = $this->issuer()->issue('lms_abc', 'Awa', 'a@e.ci', false);

        [$header, $payload, $signature] = explode('.', $token);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$payload, self::SECRET, true)
        ), '+/', '-_'), '=');

        self::assertSame($expected, $signature);
    }

    public function test_a_tampered_payload_breaks_the_signature(): void
    {
        $token = $this->issuer()->issue('lms_abc', 'Awa', 'a@e.ci', false);
        [$header, $payload, $signature] = explode('.', $token);

        // Un attaquant remplace la salle par un joker.
        $forged = rtrim(strtr(base64_encode('{"room":"*"}'), '+/', '-_'), '=');
        $recomputed = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$forged, self::SECRET, true)
        ), '+/', '-_'), '=');

        self::assertNotSame($signature, $recomputed);
    }

    public function test_the_algorithm_is_pinned_to_hs256(): void
    {
        $token = $this->issuer()->issue('lms_abc', 'Awa', 'a@e.ci', false);
        $header = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true);

        self::assertIsArray($header);
        self::assertSame('HS256', $header['alg']);
        self::assertSame('JWT', $header['typ']);
    }

    // ───────────────────── Les claims attendus par prosody ─────────────────────

    public function test_issuer_audience_and_subject_match_the_server_configuration(): void
    {
        $payload = $this->decodePayload($this->issuer()->issue('lms_abc', 'Awa', 'a@e.ci', false));

        self::assertSame(self::APP_ID, $payload['iss'], 'Doit valoir JWT_APP_ID.');
        self::assertSame(self::AUDIENCE, $payload['aud'], 'Doit figurer dans JWT_ACCEPTED_AUDIENCES.');
        self::assertSame('meet.jitsi', $payload['sub'], 'XMPP_DOMAIN. Absent = rejet.');
    }

    /**
     * Un jeton sans expiration serait une clé perdue qui ouvre à vie.
     */
    public function test_the_token_expires_and_is_not_valid_before_now(): void
    {
        $before = time();
        $payload = $this->decodePayload($this->issuer()->issue('lms_abc', 'Awa', 'a@e.ci', false));
        $after = time();

        self::assertIsInt($payload['exp']);
        self::assertIsInt($payload['nbf']);
        self::assertGreaterThanOrEqual($before + 7200, $payload['exp']);
        self::assertLessThanOrEqual($after + 7200, $payload['exp']);
        self::assertLessThanOrEqual($before, $payload['nbf']);
    }

    // ───────────────────── L'identité vient du serveur ─────────────────────

    public function test_the_displayed_identity_comes_from_the_payload_given_by_the_server(): void
    {
        $payload = $this->decodePayload(
            $this->issuer()->issue('lms_abc', 'Awa Traoré', 'awa@ecole.ci', false)
        );

        $user = $payload['context']['user'];
        self::assertIsArray($user);
        self::assertSame('Awa Traoré', $user['name']);
        self::assertSame('awa@ecole.ci', $user['email']);
    }

    public function test_moderator_is_false_for_a_plain_participant(): void
    {
        $payload = $this->decodePayload($this->issuer()->issue('lms_abc', 'Awa', 'a@e.ci', false));

        self::assertSame('false', $payload['context']['user']['moderator']);
    }

    public function test_moderator_is_true_only_when_the_caller_says_so(): void
    {
        $payload = $this->decodePayload($this->issuer()->issue('lms_abc', 'Prof', 'p@e.ci', true));

        self::assertSame('true', $payload['context']['user']['moderator']);
    }

    // ───────────────────── Configuration absente ─────────────────────

    /**
     * Sans secret, on ne peut pas signer. Émettre un jeton vide laisserait
     * l'élève devant une porte close sans explication : on refuse bruyamment.
     */
    public function test_issuing_without_a_secret_is_refused_loudly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->issuer(secret: null)->issue('lms_abc', 'Awa', 'a@e.ci', false);
    }

    public function test_is_configured_reports_the_missing_secret(): void
    {
        self::assertTrue($this->issuer()->isConfigured());
        self::assertFalse($this->issuer(secret: null)->isConfigured());
        self::assertFalse($this->issuer(secret: '')->isConfigured());
    }

    // ───────────────────── Encodage ─────────────────────

    /**
     * Le base64 standard produit `+`, `/` et `=`, qui cassent une URL. Un jeton
     * mal encodé serait rejeté par prosody sans message exploitable.
     */
    public function test_the_token_is_url_safe(): void
    {
        $token = $this->issuer()->issue('lms_abc', 'Prénom Accentué É', 'a@e.ci', true);

        self::assertDoesNotMatchRegularExpression('#[+/=]#', $token);
        self::assertSame($token, rawurlencode($token));
    }
}

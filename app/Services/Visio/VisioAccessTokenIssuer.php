<?php

declare(strict_types=1);

namespace App\Services\Visio;

use RuntimeException;

/**
 * Taille la clé d'entrée d'une salle Jitsi pour UN utilisateur et UNE séance.
 *
 * ## Pourquoi ce service existe
 *
 * Le serveur `visio.klassci.com` tourne avec `ENABLE_AUTH=1` et
 * `ENABLE_GUESTS=0` : aucune salle ne s'ouvre sans jeton signé, et il n'existe
 * aucun couple identifiant/mot de passe capable d'entrer. Le LMS, qui sait déjà
 * qui suit quel cours, est donc le seul à pouvoir ouvrir la porte.
 *
 * ## L'invariant porté par ce code
 *
 * Le claim `room` vaut TOUJOURS l'identifiant exact de la séance, jamais `*`.
 * C'est lui — et lui seul — qui empêche un jeton émis pour la 6ᵉ d'ouvrir la
 * salle de la 5ᵉ. Prosody vérifie ce claim contre la salle demandée.
 *
 * ## Pourquoi la signature est écrite ici plutôt que déléguée à une dépendance
 *
 * Ce service ne fait que SIGNER, jamais vérifier. Les failles classiques des
 * jetons — confusion d'algorithme, algorithme `none`, confusion de clé — vivent
 * toutes du côté de la vérification, qui est ici assurée par prosody. Signer en
 * HS256 tient en un `hash_hmac`, et le dépôt procède déjà ainsi pour le webhook
 * d'enregistrement ({@see Recording\SeanceRecordingWebhookService} ligne 113).
 * Ajouter une dépendance pour dix lignes maîtrisées élargirait la chaîne
 * d'approvisionnement sans rien sécuriser.
 *
 * @see PRODUCTION_STANDARDS.md §1.2 (sécurité absolue) · §1.6 D (DI stricte)
 * @see \Tests\Unit\Services\Visio\VisioAccessTokenIssuerTest
 */
final class VisioAccessTokenIssuer
{
    public function __construct(
        private readonly string $appId,
        private readonly ?string $appSecret,
        private readonly string $audience,
        private readonly string $xmppDomain,
        private readonly int $lifetimeSeconds,
    ) {}

    /**
     * Vrai lorsque la configuration permet d'émettre. Un appelant doit s'en
     * servir pour expliquer l'indisponibilité plutôt que laisser l'utilisateur
     * devant une porte close sans motif.
     */
    public function isConfigured(): bool
    {
        return is_string($this->appSecret) && $this->appSecret !== '';
    }

    /**
     * @param  string  $room  Identifiant EXACT de la salle, jamais un joker.
     * @param  bool  $isModerator  Décidé par le serveur, jamais par le client.
     *
     * @throws RuntimeException si le secret partagé n'est pas configuré.
     */
    public function issue(string $room, string $displayName, string $email, bool $isModerator): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Secret Jitsi absent : impossible de signer un accès visio '
                .'(configurer JITSI_APP_SECRET).'
            );
        }

        $now = time();

        $payload = [
            // Doit valoir JWT_APP_ID côté prosody, sinon rejet sans message.
            'iss' => $this->appId,
            // Doit figurer dans JWT_ACCEPTED_AUDIENCES.
            'aud' => $this->audience,
            // XMPP_DOMAIN. Le claim doit être PRÉSENT, sinon rejet.
            'sub' => $this->xmppDomain,
            // LE cloisonnement entre classes. Jamais '*'.
            'room' => $room,
            'iat' => $now,
            // Marge de 10 s : une horloge serveur légèrement en avance ferait
            // rejeter un jeton pourtant valide.
            'nbf' => $now - 10,
            'exp' => $now + $this->lifetimeSeconds,
            'context' => [
                'user' => [
                    'name' => $displayName,
                    'email' => $email,
                    // Prosody attend une CHAÎNE, pas un booléen JSON.
                    'moderator' => $isModerator ? 'true' : 'false',
                ],
            ],
        ];

        $signingInput = $this->segment(['alg' => 'HS256', 'typ' => 'JWT'])
            .'.'.$this->segment($payload);

        /** @var string $secret */
        $secret = $this->appSecret;

        return $signingInput.'.'.$this->base64Url(
            hash_hmac('sha256', $signingInput, $secret, true)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function segment(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Encodage JSON du jeton visio impossible.');
        }

        return $this->base64Url($json);
    }

    /**
     * Base64 URL-safe (RFC 7515 §2) : le base64 standard produit `+`, `/` et
     * `=`, qui cassent une URL et font rejeter le jeton sans message exploitable.
     */
    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * KLASSCI a refusé la connexion d'un compte pour une raison CONCLUSIVE autre que le mot de passe (#1739) : un 403
 * (« pas encore réinscrit », compte inactif…). Ce n'est ni « identifiants incorrects » (401) ni une panne (503).
 *
 * Le message de KLASSCI est montré à la personne : il est NETTOYÉ ici, une fois pour toutes. Les blocs `<script>` et
 * `<style>` sont retirés avec leur contenu, puis les balises, les entités sont décodées, les caractères de contrôle
 * ôtés, les espaces resserrés, la longueur bornée. Sans message lisible, un texte de repli.
 */
final class KlassciCompteRefuseException extends RuntimeException
{
    public const REASON = 'klassci_account_refused';

    public const LONGUEUR_MAX = 300;

    public const REPLI = 'Votre école refuse la connexion de ce compte. Contactez son secrétariat.';

    private function __construct(public readonly string $messageNettoye)
    {
        parent::__construct($messageNettoye);
    }

    public static function depuisReponse(Response $response): self
    {
        $corps = $response->json();
        $brut = is_array($corps) && is_string($corps['message'] ?? null) ? $corps['message'] : '';

        return new self(self::nettoyer($brut));
    }

    public static function nettoyer(string $brut): string
    {
        $sansBlocs = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $brut);
        $texte = html_entity_decode(strip_tags($sansBlocs), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $texte);
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        if ($texte === '') {
            return self::REPLI;
        }

        return mb_strlen($texte) > self::LONGUEUR_MAX
            ? rtrim(mb_substr($texte, 0, self::LONGUEUR_MAX - 1)).'…'
            : $texte;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import\Fields;

/**
 * Normalise le téléphone qui sert de clé de déduplication (#718).
 *
 * ## La dette payée
 *
 * L'ancien `normalizePhone()` retirait TOUS les non-chiffres, `+` compris. Le
 * même abonné écrit `+22670000000` puis `0022670000000` produisait deux clés,
 * donc deux comptes. Or #718 fait de l'idempotence sur cette clé la propriété
 * qui permet « de corriger trois lignes et de renvoyer tout le fichier ».
 *
 * ## Ce qui n'est PAS fait, et pourquoi
 *
 * Une normalisation E.164 complète exige un INDICATIF PAYS. Le dépôt n'en a
 * aucune source : ni configuration, ni colonne sur `institutions`, `locale`
 * vaut `en` et le fuseau `UTC`. Et le parc est mixte — `esbtp-abidjan` est
 * ivoirien (+225), les jeux d'essai burkinabè (+226).
 *
 * Choisir un pays par défaut le rendrait FAUX pour une partie du parc, en
 * silence, et fusionnerait deux abonnés distincts portant le même numéro
 * national. **Deviner est ici pire que s'abstenir.**
 *
 * La règle est donc étroite et déterministe :
 *
 *   - forme INTERNATIONALE (`+` ou préfixe d'accès `00`) → E.164 ;
 *   - forme NATIONALE → chiffres seuls, inchangée.
 *
 * Le jour où une institution portera son pays, cette classe est le seul endroit
 * à reprendre — et elle est pure, donc éprouvable sans base ni HTTP.
 *
 * @see docs/adr/2026-09-19-718-03-cle-de-deduplication.md
 */
final class PhoneNormalizer
{
    /** Préfixe d'accès international : `00` désigne le même numéro que `+`. */
    private const ACCES_INTERNATIONAL = '00';

    public function normalize(string $raw): string
    {
        $compact = trim($raw);

        // Lu AVANT de ne garder que les chiffres : c'est le seul moment où la
        // marque d'internationalité est encore là.
        $international = str_starts_with($compact, '+')
            || str_starts_with($compact, self::ACCES_INTERNATIONAL);

        // Un seul mécanisme retire tout ce qui n'est pas un chiffre — espaces,
        // points, tirets, parenthèses que sème un tableur. Une constante de
        // séparateurs en plus de ce filtre serait un second mécanisme pour le
        // même office : la falsification l'a montrée inutile en restant verte
        // quand on la neutralisait.
        $chiffres = preg_replace('/\D+/', '', $compact) ?? '';

        if (! $international) {
            // Sans le moindre chiffre — « à demander », « néant » — la chaîne
            // vide sort d'elle-même, et c'est ce qu'il faut : une telle cellule
            // ne doit produire AUCUNE clé, sinon tous les apprenants sans
            // numéro se fondraient en un seul compte.
            return $chiffres;
        }

        if (str_starts_with($compact, self::ACCES_INTERNATIONAL)) {
            $chiffres = substr($chiffres, strlen(self::ACCES_INTERNATIONAL));
        }

        return $chiffres === '' ? '' : '+'.$chiffres;
    }
}

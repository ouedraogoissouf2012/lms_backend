<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Le lien d'action d'une notification : un chemin INTERNE du LMS, ou une
 * adresse `https://`. Rien d'autre.
 *
 * La règle `url` du framework ne convient pas ici : elle exige un schéma et un
 * hôte, donc refuse le chemin interne, forme naturelle d'un lien du LMS
 * (`/student/evaluations/3`). Et elle accepte quelque 300 schémas, dont
 * `data://`, `file://` et `view-source://`
 * (`Illuminate\Support\Str::isUrl`).
 *
 * - **Adresse externe** : le validateur du framework lui-même, restreint au
 *   seul schéma `https` (`Str::isUrl($valeur, ['https'])`), sans motif recopié,
 *   et sans identifiants dans l'autorité (`https://x@y`).
 * - **Chemin interne** : il commence par UN `/`, jamais `//` ni `/\`, qu'un
 *   navigateur lirait comme une autre origine. Il ne contient ni antislash ni
 *   blanc. Sa partie CHEMIN (avant `?` et `#`) n'a aucun segment `.` ou `..`,
 *   et le reste une fois décodée (`%2F%2F`, `%5C`, `%2E%2E`).
 *
 * Aucun caractère de contrôle, nulle part. C'est vérifié AVANT, car le `$`
 * final du motif du framework accepte un saut de ligne en fin de chaîne.
 *
 * Échoue fermé : une valeur qui n'est pas une chaîne est refusée.
 *
 * @see app/Http/Requests/CreateNotificationRequest.php
 * @see tests/Unit/Rules/NotificationActionUrlTest.php
 */
final class NotificationActionUrl implements ValidationRule
{
    public const MESSAGE = "L'URL d'action doit être un chemin interne (/…) ou une adresse https://.";

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $fail(self::MESSAGE);

            return;
        }

        if (! self::isInternalPath($value) && ! self::isHttpsAddress($value)) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * Un chemin du LMS. La valeur entière ne porte ni antislash, ni blanc, et ne
     * commence ni par `//` ni par `/\`. La partie CHEMIN, avant `?` et `#`, est
     * vérifiée telle quelle ET une fois décodée : c'est elle qu'un navigateur
     * résout. La requête et le fragment peuvent porter des valeurs encodées
     * (`?q=a%20b`) ou un chemin en paramètre (`?next=/../x`).
     */
    private static function isInternalPath(string $value): bool
    {
        if (preg_match(self::CHEMIN, $value) !== 1) {
            return false;
        }

        $chemin = preg_split('~[?#]~', $value, 2)[0] ?? '';

        foreach ([$chemin, rawurldecode($chemin)] as $forme) {
            if (preg_match(self::CHEMIN, $forme) !== 1 || preg_match('~(^|/)\.{1,2}(/|\z)~', $forme) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Une adresse `https://`, sans identifiants dans l'autorité (`https://x@y`) :
     * le validateur du framework les accepte, et ils masquent l'hôte réel.
     */
    private static function isHttpsAddress(string $value): bool
    {
        return Str::isUrl($value, ['https']) && preg_match('~^https://[^/?#]*@~i', $value) !== 1;
    }

    /** Commence par UN `/`, jamais `//` ni `/\`, et ne porte ni antislash, ni blanc, ni caractère de contrôle. */
    private const CHEMIN = '~^/(?![/\\\\])[^\\\\\s\x00-\x1F\x7F]*\z~u';
}

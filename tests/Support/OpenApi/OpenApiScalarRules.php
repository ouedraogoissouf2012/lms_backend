<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

/**
 * Les contraintes SCALAIRES d'un schéma OpenAPI 3.0 : `enum`, `format`,
 * `minLength` / `maxLength`, `minimum` / `maximum`, `pattern`. Une fonction par
 * mot-clé. Un format inconnu, ou posé hors de son type, est refusé avant
 * (OpenApiSchemaShape) : l'aide échoue fermé. Formats lus : `date-time`, `date`,
 * `email`, `uri` sur `string` ; `int32` (bornes vérifiées) et `int64` sur
 * `integer` ; `float` et `double` sur `number`. `binary` et `password` sont
 * inconnus ici.
 *
 * Les ancres de fin sont strictes (`\z`, modificateur `D` pour `pattern`) : un
 * saut de ligne final ne passe pas. Le calendrier réel n'est PAS vérifié : un
 * `2026-13-45` a la forme d'une date (limite déclarée).
 *
 * @see OpenApiSchemaValidator
 */
final class OpenApiScalarRules
{
    /** Les bornes du format int32. */
    private const INT32_MIN = -2147483648;

    private const INT32_MAX = 2147483647;

    /**
     * @param  array<mixed>  $schema
     * @return list<string>
     */
    public static function erreurs(mixed $valeur, array $schema, string $ou): array
    {
        $erreurs = [];
        self::enum($valeur, $schema, $ou, $erreurs);
        self::format($valeur, $schema, $ou, $erreurs);
        self::longueurs($valeur, $schema, $ou, $erreurs);
        self::bornes($valeur, $schema, $ou, $erreurs);
        self::motif($valeur, $schema, $ou, $erreurs);

        return $erreurs;
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $erreurs
     */
    private static function enum(mixed $valeur, array $schema, string $ou, array &$erreurs): void
    {
        if (isset($schema['enum']) && ! in_array($valeur, (array) $schema['enum'], true)) {
            $erreurs[] = "$ou : ".json_encode($valeur).' hors de enum '.json_encode($schema['enum']);
        }
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $erreurs
     */
    private static function format(mixed $valeur, array $schema, string $ou, array &$erreurs): void
    {
        if (! isset($schema['format'])) {
            return;
        }
        // Le format est connu et posé sur son type, et la valeur a ce type :
        // OpenApiSchemaShape et le contrôle du type sont passés avant.
        $format = $schema['format'];

        $conforme = match ($format) {
            'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/', (string) $valeur) === 1,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}\z/', (string) $valeur) === 1,
            'email' => filter_var($valeur, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($valeur, FILTER_VALIDATE_URL) !== false,
            'int32' => $valeur >= self::INT32_MIN && $valeur <= self::INT32_MAX,
            // int64 : tout entier PHP ; float et double : tout nombre.
            default => true,
        };

        if (! $conforme) {
            $erreurs[] = "$ou : ".json_encode($valeur).' ne suit pas le format '.$format;
        }
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $erreurs
     */
    private static function longueurs(mixed $valeur, array $schema, string $ou, array &$erreurs): void
    {
        if (! is_string($valeur)) {
            return;
        }
        $longueur = mb_strlen($valeur);
        if (isset($schema['minLength']) && $longueur < $schema['minLength']) {
            $erreurs[] = "$ou : $longueur caractères, moins que minLength {$schema['minLength']}";
        }
        if (isset($schema['maxLength']) && $longueur > $schema['maxLength']) {
            $erreurs[] = "$ou : $longueur caractères, plus que maxLength {$schema['maxLength']}";
        }
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $erreurs
     */
    private static function bornes(mixed $valeur, array $schema, string $ou, array &$erreurs): void
    {
        if (! is_int($valeur) && ! is_float($valeur)) {
            return;
        }
        if (isset($schema['minimum']) && $valeur < $schema['minimum']) {
            $erreurs[] = "$ou : $valeur sous minimum {$schema['minimum']}";
        }
        if (isset($schema['maximum']) && $valeur > $schema['maximum']) {
            $erreurs[] = "$ou : $valeur au-dessus de maximum {$schema['maximum']}";
        }
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $erreurs
     */
    private static function motif(mixed $valeur, array $schema, string $ou, array &$erreurs): void
    {
        if (isset($schema['pattern']) && is_string($valeur) && preg_match('~'.str_replace('~', '\~', (string) $schema['pattern']).'~uD', $valeur) !== 1) {
            $erreurs[] = "$ou : ".json_encode($valeur).' ne suit pas le motif '.$schema['pattern'];
        }
    }
}

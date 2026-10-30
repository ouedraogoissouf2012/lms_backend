<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

/**
 * La FORME d'un nœud de schéma, avant toute validation d'une valeur : chaque
 * mot-clé est connu, et sa valeur a le type qu'OpenAPI 3.0 lui donne. Un nœud
 * mal formé n'est pas lu : il est signalé, et l'aide échoue fermé.
 *
 * Un `type` absent ou inconnu est une erreur (hors `$ref`), comme un format
 * inconnu, et un mot-clé propre à un type (`items`, `properties`, `minLength`,
 * `format`…) posé sur un autre type : il ne s'appliquerait à rien, en silence.
 * Ces contrôles portent sur le SCHÉMA : ils valent aussi quand la valeur est
 * nulle.
 *
 * @see OpenApiSchemaValidator
 */
final class OpenApiSchemaShape
{
    private const ANNOTATIONS = ['description', 'example', 'default', 'title', 'deprecated'];

    /** Chaque mot-clé lu, et la forme exigée de sa valeur. */
    private const MOTS_CLES = [
        'type' => 'texte',
        'nullable' => 'booleen',
        'required' => 'liste de textes',
        'properties' => 'table de schemas',
        'additionalProperties' => 'booleen ou schema',
        'items' => 'schema',
        'enum' => 'liste',
        'format' => 'texte',
        'minLength' => 'entier',
        'maxLength' => 'entier',
        'minimum' => 'nombre',
        'maximum' => 'nombre',
        'pattern' => 'texte',
    ];

    /** Les types connus. */
    private const TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean'];

    /** Les formats connus, et le type sur lequel chacun est légal (OpenAPI 3.0). */
    private const FORMATS = [
        'date-time' => ['string'],
        'date' => ['string'],
        'email' => ['string'],
        'uri' => ['string'],
        'int32' => ['integer'],
        'int64' => ['integer'],
        'float' => ['number'],
        'double' => ['number'],
    ];

    /** Les mots-clés propres à certains types : posés ailleurs, ils ne s'appliqueraient à rien. */
    private const TYPES_ADMIS = [
        'properties' => ['object'],
        'required' => ['object'],
        'additionalProperties' => ['object'],
        'items' => ['array'],
        'minLength' => ['string'],
        'maxLength' => ['string'],
        'pattern' => ['string'],
        'minimum' => ['integer', 'number'],
        'maximum' => ['integer', 'number'],
    ];

    /**
     * @param  array<mixed>  $schema
     * @return list<string>
     */
    public static function erreurs(array $schema, string $ou): array
    {
        $erreurs = [];

        foreach ($schema as $cle => $valeur) {
            if (in_array($cle, self::ANNOTATIONS, true)) {
                continue;
            }
            $forme = self::MOTS_CLES[$cle] ?? null;
            if ($forme === null) {
                $erreurs[] = "$ou : mot-clé non pris en charge « $cle »";
            } elseif (! self::aLaForme($valeur, $forme)) {
                $erreurs[] = "$ou : valeur malformée pour « $cle » (attendu : $forme)";
            }
        }

        if (! array_key_exists('type', $schema)) {
            $erreurs[] = "$ou : « type » absent";

            return $erreurs;
        }
        $type = $schema['type'];
        if (! in_array($type, self::TYPES, true)) {
            $erreurs[] = "$ou : type non pris en charge ".json_encode($type);

            return $erreurs;
        }

        foreach (self::TYPES_ADMIS as $cle => $types) {
            if (array_key_exists($cle, $schema) && ! in_array($type, $types, true)) {
                $erreurs[] = "$ou : « $cle » sur un type ".json_encode($type).', il ne s\'appliquerait à rien';
            }
        }

        if (array_key_exists('format', $schema)) {
            $typesDuFormat = self::FORMATS[$schema['format']] ?? null;
            if ($typesDuFormat === null) {
                $erreurs[] = "$ou : format non pris en charge ".json_encode($schema['format']);
            } elseif (! in_array($type, $typesDuFormat, true)) {
                $erreurs[] = "$ou : « format » ".json_encode($schema['format']).' sur un type '.json_encode($type).', il ne s\'appliquerait à rien';
            }
        }

        return $erreurs;
    }

    private static function aLaForme(mixed $valeur, string $forme): bool
    {
        return match ($forme) {
            'texte' => is_string($valeur),
            'booleen' => is_bool($valeur),
            'entier' => is_int($valeur),
            'nombre' => is_int($valeur) || is_float($valeur),
            'liste' => is_array($valeur) && array_is_list($valeur),
            'liste de textes' => is_array($valeur) && array_is_list($valeur) && $valeur === array_filter($valeur, 'is_string'),
            'schema' => self::estUnSchema($valeur),
            'booleen ou schema' => is_bool($valeur) || self::estUnSchema($valeur),
            'table de schemas' => is_array($valeur) && ($valeur === [] || ! array_is_list($valeur))
                && $valeur === array_filter($valeur, static fn (mixed $sous): bool => self::estUnSchema($sous)),
            default => false,
        };
    }

    private static function estUnSchema(mixed $valeur): bool
    {
        return is_array($valeur) && ($valeur === [] || ! array_is_list($valeur));
    }
}

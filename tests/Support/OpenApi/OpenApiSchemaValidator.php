<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Valide une valeur JSON décodée contre un schéma de `docs/openapi.yaml`
 * (OpenAPI 3.0), pour les tests de contrat.
 *
 * La valeur se décode en OBJETS (`json_decode($json, false)`) : un objet JSON
 * est un `stdClass`, une liste un tableau. `{}` et `[]` ne se confondent donc
 * pas.
 *
 * Elle ne connaît QUE les mots-clés que la spec emploie : `type`, `nullable`,
 * `required`, `properties`, `additionalProperties`, `items`, `enum`, `format`,
 * `minLength`, `maxLength`, `minimum`, `maximum`, `pattern` et `$ref`. Les
 * annotations (`description`, `example`, `default`, `title`, `deprecated`)
 * sont sans effet.
 *
 * ## Elle échoue FERMÉ
 *
 * Sur chaque nœud qu'elle PARCOURT, elle signale comme une ERREUR, jamais comme
 * un silence :
 * - un mot-clé inconnu ;
 * - une valeur de mot-clé mal formée ;
 * - un `type` absent ;
 * - un format inconnu, ou un format sur une valeur non textuelle ;
 * - une référence introuvable ou circulaire.
 * Un `allOf`, `oneOf` ou `anyOf` ajouté à la spec fera donc rougir le test de
 * contrat qui atteint ce nœud. Ces mots-clés imposent alors de revoir cette
 * aide, ou d'adopter une bibliothèque de validation. Un nœud qu'aucun test ne
 * parcourt n'est pas inspecté.
 *
 * Sémantique d'OpenAPI 3.0 : une propriété non décrite est admise, sauf si le
 * schéma pose `additionalProperties: false` ; les voisins d'un `$ref` sont
 * ignorés.
 *
 * La forme des nœuds est vérifiée par {@see OpenApiSchemaShape}, les contraintes
 * scalaires par {@see OpenApiScalarRules}.
 *
 * @see tests/Concerns/ValidatesAgainstOpenApi.php
 * @see tests/Unit/Support/OpenApi/OpenApiSchemaValidatorTest.php
 */
final class OpenApiSchemaValidator
{
    private const PREFIXE = '#/components/schemas/';

    /** @var list<string> */
    private array $erreurs = [];

    /** @param  array<mixed>  $spec */
    public function __construct(private readonly array $spec) {}

    public static function depuisFichier(string $chemin): self
    {
        $spec = Yaml::parseFile($chemin);

        return new self(is_array($spec) ? $spec : []);
    }

    /**
     * Le schéma JSON de la réponse décrite pour ce chemin, cette méthode et ce
     * statut, ou null si la spec ne la décrit pas.
     *
     * @return array<mixed>|null
     */
    public function schemaDeReponse(string $chemin, string $methode, int $statut): ?array
    {
        $schema = $this->spec['paths'][$chemin][strtolower($methode)]['responses'][(string) $statut]['content']['application/json']['schema'] ?? null;

        return is_array($schema) ? $schema : null;
    }

    /**
     * @param  array<mixed>  $schema
     * @return list<string>  les écarts, vide si la valeur est conforme
     */
    public function erreurs(mixed $valeur, array $schema, string $ou = '$'): array
    {
        $this->erreurs = [];
        $this->valider($valeur, $schema, $ou, []);

        return $this->erreurs;
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $references  les références en cours de résolution
     */
    private function valider(mixed $valeur, array $schema, string $ou, array $references): void
    {
        if (array_key_exists('$ref', $schema)) {
            $this->viaReference($valeur, $schema['$ref'], $ou, $references);

            return;
        }

        $forme = OpenApiSchemaShape::erreurs($schema, $ou);
        if ($forme !== []) {
            array_push($this->erreurs, ...$forme);

            return;
        }

        if ($valeur === null) {
            $this->nullable($schema, $ou);

            return;
        }

        if (! $this->type($valeur, $schema, $ou)) {
            return;
        }

        array_push($this->erreurs, ...OpenApiScalarRules::erreurs($valeur, $schema, $ou));
        $this->objet($valeur, $schema, $ou, $references);
        $this->liste($valeur, $schema, $ou, $references);
    }

    /** @param  list<string>  $references */
    private function viaReference(mixed $valeur, mixed $reference, string $ou, array $references): void
    {
        $nom = is_string($reference) && str_starts_with($reference, self::PREFIXE) ? substr($reference, strlen(self::PREFIXE)) : null;
        $cible = $nom !== null ? ($this->spec['components']['schemas'][$nom] ?? null) : null;

        if (! is_array($cible)) {
            $this->erreurs[] = "$ou : référence introuvable ".json_encode($reference);
        } elseif (in_array($nom, $references, true)) {
            $this->erreurs[] = "$ou : référence circulaire ".json_encode($reference);
        } else {
            $this->valider($valeur, $cible, $ou, [...$references, (string) $nom]);
        }
    }

    /** @param  array<mixed>  $schema */
    private function nullable(array $schema, string $ou): void
    {
        if (($schema['nullable'] ?? false) !== true) {
            $this->erreurs[] = "$ou : null, alors que le schéma n'est pas nullable";
        } elseif (isset($schema['enum']) && ! in_array(null, (array) $schema['enum'], true)) {
            // Lecture stricte d'OpenAPI 3.0.3 : avec un `enum`, `nullable` n'admet
            // null que si l'énumération le contient.
            $this->erreurs[] = "$ou : null, absent de enum ".json_encode($schema['enum']);
        }
    }

    /** @param  array<mixed>  $schema */
    private function type(mixed $valeur, array $schema, string $ou): bool
    {
        // Le type est connu : OpenApiSchemaShape a refusé tout autre type avant.
        $type = $schema['type'];
        $conforme = match ($type) {
            'object' => $valeur instanceof stdClass,
            'array' => is_array($valeur) && array_is_list($valeur),
            'string' => is_string($valeur),
            'integer' => is_int($valeur),
            'number' => is_int($valeur) || is_float($valeur),
            default => is_bool($valeur),
        };

        if (! $conforme) {
            $this->erreurs[] = "$ou : attendu ".json_encode($type).', reçu '.get_debug_type($valeur);
        }

        return $conforme;
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $references
     */
    private function objet(mixed $valeur, array $schema, string $ou, array $references): void
    {
        if (! $valeur instanceof stdClass) {
            return;
        }
        $cles = get_object_vars($valeur);

        foreach ($schema['required'] ?? [] as $cle) {
            if (! array_key_exists($cle, $cles)) {
                $this->erreurs[] = "$ou : clé requise absente « $cle »";
            }
        }

        $proprietes = $schema['properties'] ?? [];
        $autres = $schema['additionalProperties'] ?? true;
        foreach ($cles as $cle => $sous) {
            if (isset($proprietes[$cle])) {
                $this->valider($sous, $proprietes[$cle], "$ou.$cle", $references);
            } elseif ($autres === false) {
                $this->erreurs[] = "$ou : clé non décrite « $cle » (additionalProperties: false)";
            } elseif (is_array($autres)) {
                $this->valider($sous, $autres, "$ou.$cle", $references);
            }
        }
    }

    /**
     * @param  array<mixed>  $schema
     * @param  list<string>  $references
     */
    private function liste(mixed $valeur, array $schema, string $ou, array $references): void
    {
        if (! is_array($valeur) || $schema['type'] !== 'array') {
            return;
        }
        if (! isset($schema['items'])) {
            $this->erreurs[] = "$ou : liste sans « items » (OpenAPI 3.0 l'exige)";

            return;
        }

        foreach ($valeur as $i => $element) {
            $this->valider($element, $schema['items'], "{$ou}[$i]", $references);
        }
    }
}

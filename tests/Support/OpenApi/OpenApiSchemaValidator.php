<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use stdClass;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

/**
 * Valide une valeur JSON décodée contre un schéma de `docs/openapi.yaml`
 * (OpenAPI 3.0), pour les tests de contrat.
 *
 * La valeur se décode en OBJETS (`json_decode($json, false)`) : un objet JSON
 * est un `stdClass`, une liste un tableau. `{}` et `[]` ne se confondent donc
 * pas.
 *
 * Elle ne connaît QUE les mots-clés que la spec emploie : `type`, `nullable`,
 * `required`, `properties`, `additionalProperties`, `items`, `maxItems`, `enum`,
 * `format`, `minLength`, `maxLength`, `minimum`, `maximum`, `pattern`, `oneOf` et
 * `$ref`.
 * Les annotations (`description`, `example`, `default`, `title`, `deprecated`)
 * sont sans effet.
 *
 * `oneOf` sert une valeur dont le type dépend de sa source, comme un id
 * entier dans une école créée depuis le LMS et relayé tel que KLASSCI le rend
 * dans une école créée depuis KLASSCI. Le nœud ne porte que `nullable` et des
 * annotations ; la valeur doit concorder avec EXACTEMENT une branche.
 *
 * `nullable` se lit sur le nœud `oneOf`, à l'écart de la lecture stricte
 * d'OpenAPI 3.0.3 (qui ne le fait agir qu'à côté d'un `type`) : en 3.0, c'est
 * la seule façon de dire qu'une valeur à plusieurs types peut être nulle. Posé
 * sur une branche, il ne s'appliquerait à rien : il y est refusé. Les défauts
 * de la spec trouvés dans une branche remontent toujours, même pour une valeur
 * nulle et même quand une autre branche concorde.
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
 * Un `allOf` ou `anyOf` ajouté à la spec fera donc rougir le test de contrat
 * qui atteint ce nœud. Ces mots-clés imposent alors de revoir cette aide, ou
 * d'adopter une bibliothèque de validation. Un nœud qu'aucun test ne parcourt
 * n'est pas inspecté.
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

    private const PREFIXE_REPONSES = '#/components/responses/';

    /** @var list<string> */
    private array $erreurs = [];

    /**
     * Les défauts de la SPEC relevés depuis le début de la validation. Ils ne
     * sont jamais effacés par la comparaison d'une branche de `oneOf` : ce n'est
     * pas un écart de la valeur, et il remonte quoi que concordent les branches.
     *
     * @var list<string>
     */
    private array $defautsDeSpec = [];

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
        $reponse = $this->spec['paths'][$chemin][strtolower($methode)]['responses'][(string) $statut] ?? null;
        if (is_array($reponse) && array_key_exists('$ref', $reponse)) {
            $reponse = $this->reponsePartagee($reponse['$ref']);
        }
        $schema = is_array($reponse) ? ($reponse['content']['application/json']['schema'] ?? null) : null;

        return is_array($schema) ? $schema : null;
    }

    /**
     * Une réponse décrite une fois sous `components/responses` et citée par
     * `$ref`. Introuvable, c'est une spec cassée, pas un statut non décrit.
     *
     * @return array<mixed>
     */
    private function reponsePartagee(mixed $reference): array
    {
        $nom = is_string($reference) && str_starts_with($reference, self::PREFIXE_REPONSES)
            ? substr($reference, strlen(self::PREFIXE_REPONSES))
            : null;
        $reponse = $nom !== null ? ($this->spec['components']['responses'][$nom] ?? null) : null;

        if (! is_array($reponse)) {
            throw new UnexpectedValueException('Réponse partagée introuvable : '.json_encode($reference));
        }
        // Une seule indirection est lue : une chaîne passerait sinon pour un
        // statut non décrit, un message qui tromperait.
        if (array_key_exists('$ref', $reponse)) {
            throw new UnexpectedValueException('Réponse partagée en chaîne, non suivie : '.json_encode($reference));
        }

        return $reponse;
    }

    /**
     * @param  array<mixed>  $schema
     * @return list<string>  les écarts, vide si la valeur est conforme
     */
    public function erreurs(mixed $valeur, array $schema, string $ou = '$'): array
    {
        $this->erreurs = [];
        $this->defautsDeSpec = [];
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
            $this->defautDeSpec(...$forme);

            return;
        }

        // Avant `null` : une valeur nulle ne parcourt aucune branche, leur forme
        // est pourtant vérifiée, comme celle d'un nœud simple.
        if (array_key_exists('oneOf', $schema) && ! $this->branchesBienFormees($schema['oneOf'], $ou)) {
            return;
        }

        if ($valeur === null) {
            $this->nullable($schema, $ou);

            return;
        }

        if (array_key_exists('oneOf', $schema)) {
            $this->uneSeuleBranche($valeur, $schema['oneOf'], $ou, $references);

            return;
        }

        if (! $this->type($valeur, $schema, $ou)) {
            return;
        }

        array_push($this->erreurs, ...OpenApiScalarRules::erreurs($valeur, $schema, $ou));
        $this->objet($valeur, $schema, $ou, $references);
        $this->liste($valeur, $schema, $ou, $references);
    }

    /**
     * `oneOf` : la valeur suit EXACTEMENT une branche. Aucune est un écart ; deux
     * aussi, car deux branches qui se recouvrent (`integer` et `number`) rendent
     * le schéma ambigu.
     *
     * La forme de chaque branche est vérifiée AVANT la comparaison (par
     * `valider`), et un défaut de spec trouvé plus bas PENDANT la comparaison
     * remonte aussi : sinon il ne serait qu'une branche qui ne concorde pas, en
     * silence dès qu'une autre concorde.
     *
     * @param  list<array<mixed>>  $branches  forme vérifiée par OpenApiSchemaShape
     * @param  list<string>  $references
     */
    private function uneSeuleBranche(mixed $valeur, array $branches, string $ou, array $references): void
    {
        $dejaRelevees = count($this->defautsDeSpec);
        $concordantes = count(array_filter(
            $branches,
            fn (array $branche): bool => $this->ecartsDansBranche($valeur, $branche, $ou, $references) === [],
        ));

        // Un défaut de spec trouvé PLUS BAS dans une branche n'est pas une branche
        // qui ne concorde pas : il remonte, même si une autre branche concorde.
        $decouverts = array_values(array_unique(array_slice($this->defautsDeSpec, $dejaRelevees)));
        if ($decouverts !== []) {
            array_push($this->erreurs, ...$decouverts);

            return;
        }

        if ($concordantes === 0) {
            $this->erreurs[] = "$ou : aucune branche de « oneOf » ne concorde";
        } elseif ($concordantes > 1) {
            $this->erreurs[] = "$ou : $concordantes branches de « oneOf » concordent, il en faut une seule";
        }
    }

    /**
     * @param  array<mixed>  $branche
     * @return list<string>
     */
    private function formeDeBranche(array $branche, string $ou): array
    {
        $suivies = [];
        while (array_key_exists('$ref', $branche)) {
            $reference = $branche['$ref'];
            if (in_array($reference, $suivies, true)) {
                return ["$ou : référence circulaire ".json_encode($reference)];
            }
            $suivies[] = $reference;
            $cible = $this->cible($reference);
            if ($cible === null) {
                return ["$ou : référence introuvable ".json_encode($reference)];
            }
            $branche = $cible;
        }

        if (array_key_exists('nullable', $branche)) {
            return ["$ou : « nullable » sur une branche de « oneOf », il ne s'appliquerait à rien : il se pose sur le nœud"];
        }

        return OpenApiSchemaShape::erreurs($branche, $ou);
    }

    /**
     * La forme de chaque branche, vérifiée avant toute comparaison, et même pour
     * une valeur nulle.
     *
     * @param  list<array<mixed>>  $branches  forme vérifiée par OpenApiSchemaShape
     */
    private function branchesBienFormees(array $branches, string $ou): bool
    {
        $forme = [];
        foreach ($branches as $i => $branche) {
            array_push($forme, ...$this->formeDeBranche($branche, "$ou.oneOf[$i]"));
        }
        $this->defautDeSpec(...$forme);

        return $forme === [];
    }

    /** Un défaut de la spec : relevé ici, et gardé à l'abri des comparaisons de branches. */
    private function defautDeSpec(string ...$defauts): void
    {
        array_push($this->erreurs, ...$defauts);
        array_push($this->defautsDeSpec, ...$defauts);
    }

    /**
     * Les écarts de la valeur face à UNE branche, sans toucher à ceux déjà relevés.
     *
     * @param  array<mixed>  $branche
     * @param  list<string>  $references
     * @return list<string>
     */
    private function ecartsDansBranche(mixed $valeur, array $branche, string $ou, array $references): array
    {
        $releves = $this->erreurs;
        $this->erreurs = [];
        $this->valider($valeur, $branche, $ou, $references);
        $ecarts = $this->erreurs;
        $this->erreurs = $releves;

        return $ecarts;
    }

    /** @return array<mixed>|null */
    private function cible(mixed $reference): ?array
    {
        $nom = is_string($reference) && str_starts_with($reference, self::PREFIXE) ? substr($reference, strlen(self::PREFIXE)) : null;
        $cible = $nom !== null ? ($this->spec['components']['schemas'][$nom] ?? null) : null;

        return is_array($cible) ? $cible : null;
    }

    /** @param  list<string>  $references */
    private function viaReference(mixed $valeur, mixed $reference, string $ou, array $references): void
    {
        $nom = is_string($reference) && str_starts_with($reference, self::PREFIXE) ? substr($reference, strlen(self::PREFIXE)) : null;
        $cible = $this->cible($reference);

        if ($cible === null) {
            $this->defautDeSpec("$ou : référence introuvable ".json_encode($reference));
        } elseif (in_array($nom, $references, true)) {
            $this->defautDeSpec("$ou : référence circulaire ".json_encode($reference));
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
            $this->defautDeSpec("$ou : liste sans « items » (OpenAPI 3.0 l'exige)");

            return;
        }

        if (isset($schema['maxItems']) && count($valeur) > $schema['maxItems']) {
            $this->erreurs[] = "$ou : ".count($valeur)." éléments, plus que maxItems {$schema['maxItems']}";
        }

        foreach ($valeur as $i => $element) {
            $this->valider($element, $schema['items'], "{$ou}[$i]", $references);
        }
    }
}

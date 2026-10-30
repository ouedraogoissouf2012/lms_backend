<?php

declare(strict_types=1);

namespace Tests\Unit\Support\OpenApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OpenApi\OpenApiSchemaValidator;

/**
 * L'aide de contrat MORD. Pour chaque règle, une valeur conforme passe ET une
 * valeur non conforme est signalée : les deux sens. Ce qu'elle ne sait pas lire
 * (mot-clé, format, type, référence, valeur mal formée) est signalé aussi :
 * elle échoue fermé.
 *
 * Les valeurs s'écrivent en JSON et se décodent en objets, comme la réponse
 * réelle : `{}` et `[]` restent distincts.
 */
final class OpenApiSchemaValidatorTest extends TestCase
{
    /** @return iterable<string, array{0: array<mixed>, 1: string, 2: string}> */
    public static function deuxSens(): iterable
    {
        $objet = static fn (array $schema): array => ['type' => 'object'] + $schema;
        $a = ['a' => ['type' => 'integer']];

        yield 'type integer' => [['type' => 'integer'], '3', '"3"'];
        yield 'integer refuse un flottant' => [['type' => 'integer'], '2', '1.5'];
        yield 'number admet un entier' => [['type' => 'number'], '3', '"3"'];
        yield 'number admet un flottant' => [['type' => 'number'], '1.5', 'true'];
        yield 'type boolean' => [['type' => 'boolean'], 'true', '1'];
        yield 'type string' => [['type' => 'string'], '"x"', '3'];
        yield 'objet, pas une liste' => [['type' => 'object'], '{"a":1}', '[1,2]'];
        yield 'objet vide, pas une liste vide' => [['type' => 'object'], '{}', '[]'];
        yield 'liste, pas un objet' => [['type' => 'array', 'items' => ['type' => 'integer']], '[1]', '{"a":1}'];
        yield 'liste vide, pas un objet vide' => [['type' => 'array', 'items' => ['type' => 'integer']], '[]', '{}'];
        yield 'nullable' => [['type' => 'string', 'nullable' => true], 'null', '3'];
        yield 'non nullable' => [['type' => 'string'], '"x"', 'null'];
        yield 'required' => [$objet(['required' => ['a'], 'properties' => $a]), '{"a":1}', '{"b":1}'];
        yield '[] face à required' => [$objet(['required' => ['a'], 'properties' => $a]), '{"a":1}', '[]'];
        yield 'properties' => [$objet(['properties' => $a]), '{"a":1}', '{"a":"1"}'];
        yield 'clé en trop refusée par additionalProperties false' => [$objet(['properties' => $a, 'additionalProperties' => false]), '{"a":1}', '{"a":1,"b":2}'];
        yield 'clé en trop admise sans additionalProperties' => [$objet(['properties' => $a]), '{"a":1,"b":2}', '{"a":"1","b":2}'];
        yield 'additionalProperties schéma' => [$objet(['additionalProperties' => ['type' => 'integer']]), '{"x":1}', '{"x":"1"}'];
        yield 'items' => [['type' => 'array', 'items' => ['type' => 'integer']], '[1,2]', '[1,"2"]'];
        yield 'enum' => [['type' => 'string', 'enum' => ['a', 'b']], '"a"', '"c"'];
        yield 'format date-time' => [['type' => 'string', 'format' => 'date-time'], '"2026-11-03T08:30:00.000000Z"', '"2026-11-03 08:30:00"'];
        yield 'format date' => [['type' => 'string', 'format' => 'date'], '"2026-11-03"', '"03/11/2026"'];
        yield 'format email' => [['type' => 'string', 'format' => 'email'], '"a@b.ci"', '"pas un courriel"'];
        yield 'format uri' => [['type' => 'string', 'format' => 'uri'], '"https://lms.ci/x"', '"pas une adresse"'];
        yield 'format int32, bornes' => [['type' => 'integer', 'format' => 'int32'], '2147483647', '2147483648'];
        yield 'format int64 sur un entier' => [['type' => 'integer', 'format' => 'int64'], '3', '1.5'];
        yield 'format double sur un nombre' => [['type' => 'number', 'format' => 'double'], '1.5', '"1.5"'];
        yield 'minLength' => [['type' => 'string', 'minLength' => 3], '"abc"', '"ab"'];
        yield 'maxLength' => [['type' => 'string', 'maxLength' => 3], '"abc"', '"abcd"'];
        yield 'minimum' => [['type' => 'integer', 'minimum' => 1], '1', '0'];
        yield 'maximum' => [['type' => 'integer', 'maximum' => 10], '10', '11'];
        yield 'pattern' => [['type' => 'string', 'pattern' => '^[A-Z]{3}$'], '"ABC"', '"abc"'];
        yield 'pattern, fin stricte' => [['type' => 'string', 'pattern' => '^[A-Z]{3}$'], '"ABC"', '"ABC\n"'];
        yield 'format date, fin stricte' => [['type' => 'string', 'format' => 'date'], '"2026-11-03"', '"2026-11-03\n"'];
        yield 'format date-time, fin stricte' => [['type' => 'string', 'format' => 'date-time'], '"2026-11-03T08:30:00Z"', '"2026-11-03T08:30:00Z\n"'];
        yield 'nullable avec null dans enum' => [['type' => 'string', 'nullable' => true, 'enum' => ['a', null]], 'null', '"b"'];
        yield 'nullable sans null dans enum' => [['type' => 'string', 'nullable' => true, 'enum' => ['a']], '"a"', 'null'];
        yield '$ref' => [['$ref' => '#/components/schemas/Entier'], '1', '"1"'];
        yield '$ref vers un schéma nullable' => [['$ref' => '#/components/schemas/EntierOuNul'], 'null', '"x"'];
        yield '$ref vers un schéma non nullable' => [['$ref' => '#/components/schemas/Entier'], '7', 'null'];
    }

    /** @param  array<mixed>  $schema */
    #[DataProvider('deuxSens')]
    public function test_chaque_regle_accepte_le_conforme_et_signale_le_reste(array $schema, string $conforme, string $nonConforme): void
    {
        $validateur = self::validateur();

        self::assertSame([], $validateur->erreurs(self::json($conforme), $schema), 'une valeur conforme est signalée');
        self::assertNotSame([], $validateur->erreurs(self::json($nonConforme), $schema), 'une valeur non conforme passe');
    }

    /**
     * Ce qu'elle ne sait pas lire est une erreur. Chaque cas a son jumeau conforme
     * dans deuxSens() (le même nœud, bien formé).
     *
     * @return iterable<string, array{0: array<mixed>, 1: string, 2: string}>
     */
    public static function echecFerme(): iterable
    {
        yield 'mot-clé allOf' => [['type' => 'string', 'allOf' => [['type' => 'string']]], '"x"', 'mot-clé non pris en charge « allOf »'];
        yield 'mot-clé oneOf' => [['type' => 'string', 'oneOf' => []], '"x"', 'mot-clé non pris en charge « oneOf »'];
        yield 'type absent' => [['properties' => ['a' => ['type' => 'integer']]], '{"a":1}', '« type » absent'];
        yield 'type inconnu' => [['type' => 'tuple'], '[1]', 'type non pris en charge'];
        yield 'format inconnu' => [['type' => 'string', 'format' => 'ipv4'], '"10.0.0.1"', 'format non pris en charge'];
        yield 'format int64 sur une chaîne, hors de son type' => [['type' => 'string', 'format' => 'int64'], '"3"', '« format » "int64" sur un type'];
        yield 'type inconnu, valeur nulle' => [['type' => 'intger', 'nullable' => true], 'null', 'type non pris en charge'];
        yield 'format inconnu, valeur nulle' => [['type' => 'string', 'nullable' => true, 'format' => 'ipv4'], 'null', 'format non pris en charge'];
        yield 'format sur un type non textuel' => [['type' => 'integer', 'format' => 'date'], '3', '« format » "date" sur un type'];
        yield 'minLength sur un entier' => [['type' => 'integer', 'minLength' => 1], '3', '« minLength » sur un type'];
        yield 'minimum sur un texte' => [['type' => 'string', 'minimum' => 1], '"x"', '« minimum » sur un type'];
        yield 'properties sur un texte' => [['type' => 'string', 'properties' => ['a' => ['type' => 'string']]], '"x"', '« properties » sur un type'];
        yield 'required sur une liste' => [['type' => 'array', 'items' => ['type' => 'string'], 'required' => ['a']], '["x"]', '« required » sur un type'];
        yield 'référence introuvable' => [['$ref' => '#/components/schemas/Absent'], '1', 'référence introuvable'];
        yield 'référence circulaire' => [['$ref' => '#/components/schemas/Boucle'], '1', 'référence circulaire'];
        yield 'liste sans items' => [['type' => 'array'], '[1]', 'liste sans « items »'];
        yield 'items sur un type non liste' => [['type' => 'string', 'items' => ['type' => 'string']], '"x"', '« items » sur un type'];
        yield 'additionalProperties mal formé' => [['type' => 'object', 'additionalProperties' => 'non'], '{}', 'valeur malformée pour « additionalProperties »'];
        yield 'required mal formé' => [['type' => 'object', 'required' => 'a'], '{"a":1}', 'valeur malformée pour « required »'];
    }

    /** @param  array<mixed>  $schema */
    #[DataProvider('echecFerme')]
    public function test_ce_qu_elle_ne_sait_pas_lire_est_une_erreur(array $schema, string $valeur, string $attendu): void
    {
        $erreurs = self::validateur()->erreurs(self::json($valeur), $schema);

        self::assertCount(1, $erreurs, implode(' | ', $erreurs));
        self::assertStringContainsString($attendu, $erreurs[0]);
    }

    public function test_chaque_ecart_est_dit_avec_son_chemin_objet_et_liste(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ]];

        self::assertSame(
            ['$.data[1].id : attendu "integer", reçu string'],
            self::validateur()->erreurs(self::json('{"data":[{"id":1},{"id":"2"}]}'), $schema),
        );
    }

    public function test_le_schema_de_reponse_vient_du_chemin_de_la_methode_et_du_statut(): void
    {
        $validateur = new OpenApiSchemaValidator(['paths' => ['/x' => ['post' => ['responses' => [
            '201' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ]]]]]);

        self::assertSame(['type' => 'object'], $validateur->schemaDeReponse('/x', 'POST', 201));
        self::assertNull($validateur->schemaDeReponse('/x', 'POST', 422), 'un statut non décrit');
        self::assertNull($validateur->schemaDeReponse('/x', 'GET', 201), 'une méthode non décrite');
    }

    private static function json(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    private static function validateur(): OpenApiSchemaValidator
    {
        return new OpenApiSchemaValidator(['components' => ['schemas' => [
            'Entier' => ['type' => 'integer'],
            'EntierOuNul' => ['type' => 'integer', 'nullable' => true],
            'Boucle' => ['$ref' => '#/components/schemas/Boucle2'],
            'Boucle2' => ['$ref' => '#/components/schemas/Boucle'],
        ]]]);
    }
}

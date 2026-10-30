<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApi\OpenApiSchemaValidator;

/**
 * Test de contrat : la réponse RÉELLE d'une route suit ce que
 * `docs/openapi.yaml` en dit, statut et corps.
 *
 * Un statut que la spec ne décrit pas pour cette route est un échec, comme
 * tout écart du corps. La validation échoue fermé
 * ({@see OpenApiSchemaValidator}).
 */
trait ValidatesAgainstOpenApi
{
    private static ?OpenApiSchemaValidator $specOpenApi = null;

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $reponse
     * @param  string  $chemin  le chemin tel que la spec l'écrit, sans `/api` (`/lms/seances`)
     */
    protected function assertMatchesOpenApiResponse(TestResponse $reponse, string $chemin, string $methode): void
    {
        self::$specOpenApi ??= OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $statut = $reponse->status();
        $schema = self::$specOpenApi->schemaDeReponse($chemin, $methode, $statut);

        self::assertNotNull($schema, "$methode $chemin : le statut $statut n'est pas décrit dans docs/openapi.yaml");
        self::assertSame(
            [],
            // En objets : `{}` reste un objet, `[]` une liste.
            self::$specOpenApi->erreurs(json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR), $schema),
            "$methode $chemin $statut : la réponse ne suit pas docs/openapi.yaml",
        );
    }

    /**
     * Le schéma décrit MORD : la réponse réelle privée d'une clé, ou augmentée
     * d'une clé non décrite, est refusée. Sans cette preuve, un schéma trop lâche
     * passerait tout en silence.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $reponse
     */
    protected function assertOpenApiSchemaRefuseSans(TestResponse $reponse, string $chemin, string $methode, string $cle): void
    {
        self::$specOpenApi ??= OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
        $schema = self::$specOpenApi->schemaDeReponse($chemin, $methode, $reponse->status());
        self::assertNotNull($schema);

        $sans = json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $sans);
        $avec = clone $sans;
        self::assertTrue(property_exists($sans, $cle), "la réponse n'a pas de clé « $cle » à retirer");
        unset($sans->$cle);
        $avec->cle_non_decrite = true;

        self::assertNotSame([], self::$specOpenApi->erreurs($sans, $schema), "le schéma admet la réponse sans « $cle »");
        self::assertNotSame([], self::$specOpenApi->erreurs($avec, $schema), 'le schéma admet une clé non décrite');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Concerns;

use App\Http\Controllers\Concerns\RespondsWithJson;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\CoversTrait;
use Tests\TestCase;

/**
 * Axe #1 — Tests du trait `RespondsWithJson` (golden contract des enveloppes JSON).
 *
 * Étend `Tests\TestCase` (et non le `TestCase` pur de PHPUnit) car le helper
 * `response()` du trait nécessite le conteneur Laravel. Aucune DB, aucun mock.
 *
 * Le trait est exercé via une classe anonyme qui l'utilise et ré-expose ses
 * méthodes `protected` en `public` — on teste le contrat produit, pas un
 * controller concret.
 *
 * Spec: `.claude/specs/api-response-envelope/` (design §4.3, §6).
 */
#[CoversTrait(RespondsWithJson::class)]
final class RespondsWithJsonTest extends TestCase
{
    /**
     * Sujet de test : expose les fabriques protégées du trait en public.
     */
    private function subject(): object
    {
        return new class
        {
            use RespondsWithJson;

            /**
             * @param  array<string, mixed>  $meta
             */
            public function success(mixed $data = null, string $message = '', int $status = 200, array $meta = []): JsonResponse
            {
                return $this->successResponse($data, $message, $status, $meta);
            }

            /**
             * @param  array<string, mixed>  $errors
             */
            public function error(string $message, int $status = 400, array $errors = []): JsonResponse
            {
                return $this->errorResponse($message, $status, $errors);
            }
        };
    }

    // ----- Succès -----

    public function test_success_with_data(): void
    {
        $response = $this->subject()->success(['id' => 1], 'OK');

        $this->assertSame(200, $response->status());
        $this->assertSame(
            ['success' => true, 'message' => 'OK', 'data' => ['id' => 1]],
            $response->getData(true),
        );
    }

    public function test_success_empty_omits_message_and_data(): void
    {
        // DRY-only : sans message ni data, on ne produit QUE {success:true}
        // (reproduit les réponses qui n'ont ni l'une ni l'autre clé).
        $response = $this->subject()->success();

        $this->assertSame(['success' => true], $response->getData(true));
    }

    public function test_success_message_omitted_when_empty(): void
    {
        // Reproduit la forme {success, data} (ex. index/show) : pas de clé message.
        $data = $this->subject()->success(['x' => 1])->getData(true);

        $this->assertSame(['success' => true, 'data' => ['x' => 1]], $data);
        $this->assertArrayNotHasKey('message', $data);
    }

    public function test_success_data_omitted_when_null(): void
    {
        // Reproduit la forme {success, message} (ex. destroy) : pas de clé data.
        $data = $this->subject()->success(null, 'Topic supprimé')->getData(true);

        $this->assertSame(['success' => true, 'message' => 'Topic supprimé'], $data);
        $this->assertArrayNotHasKey('data', $data);
    }

    public function test_success_without_meta_omits_meta_key(): void
    {
        $data = $this->subject()->success(['x' => 1], 'OK')->getData(true);

        $this->assertArrayNotHasKey('meta', $data);
    }

    public function test_success_with_meta_includes_meta(): void
    {
        $response = $this->subject()->success(['x' => 1], 'OK', 200, ['page' => 2]);

        $this->assertSame(
            ['success' => true, 'message' => 'OK', 'data' => ['x' => 1], 'meta' => ['page' => 2]],
            $response->getData(true),
        );
    }

    public function test_success_custom_status(): void
    {
        $response = $this->subject()->success(['id' => 9], 'Créé', 201);

        $this->assertSame(201, $response->status());
    }

    // ----- Erreur -----

    public function test_error_simple_omits_errors_key(): void
    {
        $response = $this->subject()->error('Interdit', 403);

        $this->assertSame(403, $response->status());
        $this->assertSame(['success' => false, 'message' => 'Interdit'], $response->getData(true));
        $this->assertArrayNotHasKey('errors', $response->getData(true));
    }

    public function test_error_with_errors_includes_them(): void
    {
        $response = $this->subject()->error('Invalide', 422, ['email' => ['requis']]);

        $this->assertSame(
            ['success' => false, 'message' => 'Invalide', 'errors' => ['email' => ['requis']]],
            $response->getData(true),
        );
    }

    public function test_error_default_status_is_400(): void
    {
        $response = $this->subject()->error('Erreur');

        $this->assertSame(400, $response->status());
    }
}

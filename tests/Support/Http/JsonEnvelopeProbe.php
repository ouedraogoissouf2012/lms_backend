<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use App\Http\Controllers\Concerns\RespondsWithJson;
use Illuminate\Http\JsonResponse;

/**
 * Sujet de test partagé : ré-expose en public les fabriques protégées de
 * {@see RespondsWithJson} pour exercer le trait hors de tout controller concret.
 *
 * Extrait en classe nommée (plutôt qu'une classe anonyme dupliquée dans chaque
 * fichier de test) car plusieurs suites l'utilisent : invariants, entrées
 * hostiles, cas limites d'encodage.
 */
final class JsonEnvelopeProbe
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
}

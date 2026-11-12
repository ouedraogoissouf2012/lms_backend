<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy\Concerns;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Support\Klassci\KlassciPersonalToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jeton KLASSCI personnel de CETTE requête (#591, #616).
 *
 * Lu sur l'objet `Request`, jamais sur un collaborateur injecté : Laravel
 * mémoïse le contrôleur sur la `Route` (seul Octane flush). L'argument de
 * méthode est per-requête par construction.
 */
trait ResolvesPersonalKlassciToken
{
    protected function personalKlassciToken(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? KlassciPersonalToken::de($user) : null;
    }

    protected function missingKlassciTokenResponse(): JsonResponse
    {
        return MissingKlassciTokenException::jsonResponse();
    }
}

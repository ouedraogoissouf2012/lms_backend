<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Http\Client\Response;

/**
 * Le corps d'une réponse KLASSCI 2xx, en tableau, ou `null` s'il est illisible.
 *
 * Un tableau JSON, même vide, est une réponse : KLASSCI peut rendre une liste
 * nue (`KlassciSearchSources::records`), et `[]` y est une liste vide. Un 204,
 * ou un corps vide, n'a pas de contenu : `[]`.
 *
 * Un corps NON vide qui n'est pas un tableau JSON (page HTML, JSON tronqué,
 * scalaire) est illisible. Il devenait `[]` : chaque appelant le lisait comme
 * « aucune donnée » (un effectif à 0), et le cache le gardait comme une donnée.
 * L'appelant décide quoi en faire : `KlassciHttpClient` (une à une) et
 * `KlassciBatchResponseCollector` (lots) lisent la règle ici.
 */
final class KlassciSuccessBody
{
    /** @return array<mixed>|null */
    public static function lire(Response $response): ?array
    {
        $corps = $response->json();

        if (is_array($corps)) {
            return $corps;
        }

        return $response->status() === 204 || trim($response->body()) === '' ? [] : null;
    }
}

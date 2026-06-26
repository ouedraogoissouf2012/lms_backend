<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Fabrique centralisée des enveloppes de réponse JSON de l'API (axe #1 réutilisabilité).
 *
 * ## Pourquoi
 *
 * Avant ce trait, 48 controllers construisaient leurs réponses à la main : 197
 * occurrences de `'success' => ...`, 85 réponses d'erreur inline, et une
 * incohérence de contrat (197 `success` mais 96 `data` seulement — certains
 * endpoints plaçaient le payload à la racine, d'autres sous `data`). Le
 * `Controller` de base était vide : aucun point unique de construction.
 *
 * Ce trait, monté sur {@see \App\Http\Controllers\Controller}, donne à tout
 * controller deux fabriques typées produisant le **contrat canonique** déjà
 * utilisé par {@see \App\Http\Presenters\AuthResponsePresenter} et
 * {@see \App\Services\Quiz\Concerns\BuildsAttemptResponses}. Il centralise la
 * forme sans la réinventer (PRODUCTION_STANDARDS §1.5 : format JSON identique
 * pour tous les endpoints).
 *
 * ## Contrat
 *
 * - Succès : `{ "success": true, "message": string, "data": mixed, "meta"?: object }`
 *   — `data` toujours présent (null si absent) ; `meta` OMIS si vide.
 * - Erreur : `{ "success": false, "message": string, "errors"?: object }` + status HTTP
 *   — `errors` OMIS si vide.
 *
 * ## Sécurité
 *
 * `errorResponse()` n'accepte qu'un message métier et un tableau structuré : sa
 * signature n'offre aucun vecteur pour exposer `$e->getMessage()` au client
 * (PRODUCTION_STANDARDS §1.2). C'est au caller de ne passer que du contenu sûr.
 *
 * @see .claude/specs/api-response-envelope/design.md §4 (golden contract)
 */
trait RespondsWithJson
{
    /**
     * Construit une réponse de succès au contrat canonique.
     *
     * @param  mixed  $data  Payload métier ; la clé `data` reste présente même si null.
     * @param  string  $message  Message métier ; vide autorisé pour les endpoints purement « data ».
     * @param  int  $status  Code HTTP (200 par défaut ; 201 pour une création, etc.).
     * @param  array<string, mixed>  $meta  Métadonnées optionnelles (pagination…) ; clé `meta` omise si vide.
     */
    protected function successResponse(
        mixed $data = null,
        string $message = '',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Construit une réponse d'erreur au contrat canonique.
     *
     * N'expose JAMAIS de détail d'exception : `$message` doit être un libellé
     * métier et `$errors` un tableau structuré (ex. erreurs de validation).
     *
     * @param  string  $message  Libellé d'erreur métier (jamais `$e->getMessage()`).
     * @param  int  $status  Code HTTP d'échec (400 par défaut ; 403, 404, 422…).
     * @param  array<string, mixed>  $errors  Détail structuré optionnel ; clé `errors` omise si vide.
     */
    protected function errorResponse(
        string $message,
        int $status = 400,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}

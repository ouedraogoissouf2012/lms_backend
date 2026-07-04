<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AuthResponsePresenter;
use App\Services\Quiz\Concerns\BuildsAttemptResponses;
use App\Support\Http\JsonPayloadGuard;
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
 * Ce trait, monté sur {@see Controller}, donne à tout
 * controller deux fabriques typées produisant le **contrat canonique** déjà
 * utilisé par {@see AuthResponsePresenter} et
 * {@see BuildsAttemptResponses}. Il centralise la
 * forme sans la réinventer (PRODUCTION_STANDARDS §1.5 : format JSON identique
 * pour tous les endpoints).
 *
 * ## Contrat (préservation, DRY-only)
 *
 * Les controllers existants émettent des formes hétérogènes — `{success, data}`
 * sans message, `{success, message}` sans data, `{success, message, data}`. Pour
 * migrer **sans changer le JSON vu par le client** (axe #1, objectif « DRY only »),
 * chaque clé optionnelle est OMISE quand sa valeur est absente :
 *
 * - Succès : `{ "success": true, "message"?: string, "data"?: mixed, "meta"?: object }`
 *   — `message` omis si `''` ; `data` omis si `null` ; `meta` omis si vide.
 * - Erreur : `{ "success": false, "message": string, "errors"?: object }` + status HTTP
 *   — `errors` omis si vide.
 *
 * Le trait centralise donc la **construction** sans imposer une forme unique :
 * pour reproduire `{success, data}` on appelle `successResponse($data)` ; pour
 * `{success, message}` on appelle `successResponse(null, $message)`. L'uniformisation
 * du contrat (toujours les 3 clés) est un chantier distinct nécessitant une
 * coordination frontend (hors périmètre de cette spec).
 *
 * ## Sécurité
 *
 * `errorResponse()` n'accepte qu'un message métier et un tableau structuré : sa
 * signature n'offre aucun vecteur pour exposer `$e->getMessage()` au client
 * (PRODUCTION_STANDARDS §1.2). C'est au caller de ne passer que du contenu sûr.
 *
 * Les Closures sont rejetées AVANT construction ({@see JsonPayloadGuard}, #360) :
 * `json_encode` les encoderait silencieusement en `{}` (200 avec payload vide,
 * aucun signal d'échec). L'exception résultante est une erreur de programmation,
 * rendue en 500 générique par le handler global.
 *
 * @see .claude/specs/api-response-envelope/design.md §4 (golden contract)
 */
trait RespondsWithJson
{
    /**
     * Construit une réponse de succès au contrat canonique.
     *
     * @param  mixed  $data  Payload métier ; clé `data` OMISE si `null` (préservation des formes sans data).
     * @param  string  $message  Message métier ; clé `message` OMISE si `''` (préservation des formes sans message).
     * @param  int  $status  Code HTTP (200 par défaut ; 201 pour une création, etc.).
     * @param  array<string, mixed>  $meta  Métadonnées optionnelles (pagination…) ; clé `meta` omise si vide.
     */
    protected function successResponse(
        mixed $data = null,
        string $message = '',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        JsonPayloadGuard::rejectClosures($data, 'data');
        JsonPayloadGuard::rejectClosures($meta, 'meta');

        $payload = ['success' => true];

        if ($message !== '') {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

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
        JsonPayloadGuard::rejectClosures($errors, 'errors');

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

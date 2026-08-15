<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

/**
 * Résultat de la vérification d'existence d'une séance côté KLASSCI (#516).
 *
 * Enum plutôt qu'une comparaison de chaîne sur un message d'exception —
 * l'ancien code (`str_contains($e->getMessage(), '404')`) pouvait donner un
 * faux positif si un message d'erreur contenait accidentellement "404"
 * ailleurs dans son texte. Le statut HTTP réel est la seule source de vérité.
 */
enum SeanceCheckResult: string
{
    /** Réponse 2xx : la séance existe toujours côté KLASSCI. */
    case Exists = 'exists';

    /** Réponse 404 : suppression confirmée — seule condition d'archivage. */
    case ConfirmedDeleted = 'confirmed_deleted';

    /** Timeout, connexion, 5xx, ou tout code ≠ 2xx/404 : ne jamais archiver. */
    case Error = 'error';
}

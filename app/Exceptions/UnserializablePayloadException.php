<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * Payload d'enveloppe JSON non sérialisable détecté AVANT construction de la
 * réponse (#360).
 *
 * Étend `LogicException` : c'est une erreur de PROGRAMMATION du caller (un
 * controller a passé une Closure dans `data`/`meta`/`errors`), jamais un état
 * métier. Elle ne doit pas être attrapée — le handler global `\Throwable` de
 * `bootstrap/app.php` la rend en 500 générique côté client (`app.debug=false`)
 * et journalise le message complet, chemin fautif inclus (§1.2 : le détail va
 * dans les logs, jamais au client).
 */
final class UnserializablePayloadException extends LogicException
{
    /**
     * @param  string  $path  Chemin de la valeur fautive dans l'enveloppe (ex. `data.items.0.on_click`).
     */
    public static function closureAt(string $path): self
    {
        return new self(
            "Closure non sérialisable dans l'enveloppe JSON à `{$path}` — "
            .'convertir le payload en tableau/scalaire avant successResponse()/errorResponse() '
            .'(sans cette garde, la Closure serait encodée silencieusement en {}).',
        );
    }
}

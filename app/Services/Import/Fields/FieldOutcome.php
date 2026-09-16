<?php

declare(strict_types=1);

namespace App\Services\Import\Fields;

/**
 * Issue d'une colonne lue : une valeur retenue, ou un refus motivé (#718).
 *
 * Trois colonnes — `role`, `statut`, `date_inscription` — partagent la même
 * alternative : soit la cellule donne une valeur exploitable, soit elle doit
 * faire échouer SA ligne en disant pourquoi. Un type commun évite que chaque
 * résolveur invente sa convention de retour (`null` ? exception ? tableau ?),
 * et rend le classement des lignes uniforme dans l'analyse à blanc.
 *
 * Le refus porte un `code` machine — que le client peut traiter — ET un message
 * destiné à quelqu'un qui a son tableur ouvert et doit corriger une cellule.
 *
 * @see docs/adr/2026-09-16-718-02-colonnes-role-statut-date.md
 */
final class FieldOutcome
{
    private function __construct(
        public readonly string $value,
        public readonly ?string $code,
        public readonly ?string $message,
    ) {}

    public static function accepted(string $value): self
    {
        return new self($value, null, null);
    }

    public static function rejected(string $code, string $message): self
    {
        return new self('', $code, $message);
    }

    public function isRejected(): bool
    {
        return $this->code !== null;
    }
}

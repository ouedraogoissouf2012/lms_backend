<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * #1657 : un élève semblable existe déjà dans l'école. 409 `doublon_possible`, avec les candidats, pour que la gestion
 * confirme (`confirmer_doublon`) ou renonce. Rien n'a été écrit.
 */
final class DoublonDEleve extends RuntimeException
{
    public const REASON = 'doublon_possible';

    public const MESSAGE = 'Un élève semblable existe déjà dans cet établissement.';

    /** @param  list<array{eleve_id: int, nom: string, prenoms: string, matricule: string}>  $candidats */
    public function __construct(public readonly array $candidats)
    {
        parent::__construct(self::MESSAGE, 409);
    }
}

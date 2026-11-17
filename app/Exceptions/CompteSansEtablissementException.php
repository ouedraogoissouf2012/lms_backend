<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * Un compte d'école ne se crée pas sans établissement (#1181).
 *
 * Seul le supradmin de plateforme (rôle exact `supradmin`) vit sans
 * `institution_id`. Tout autre compte qui en serait privé à sa création est une
 * erreur de programmation : un appelant sans tenant, une donnée incomplète. Elle
 * échoue ici, au modèle, une seule règle pour tous les écrivains, au lieu d'être
 * tenue par chacun ; `BelongsToInstitution` se contentait d'un journal.
 */
final class CompteSansEtablissementException extends LogicException
{
    public static function pour(?string $role): self
    {
        return new self(sprintf('Un compte « %s » ne se crée pas sans établissement (#1181).', $role ?? 'sans rôle'));
    }
}

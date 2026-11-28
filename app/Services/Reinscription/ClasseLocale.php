<?php

declare(strict_types=1);

namespace App\Services\Reinscription;

use App\Exceptions\BusinessException;
use App\Models\Classe;

/**
 * Une classe LOCALE de l'école, ou introuvable (#1663) : une classe d'une autre école, ou absente, rend le même 404.
 */
final class ClasseLocale
{
    public const INTROUVABLE = 'Classe introuvable dans cet établissement.';

    /** @throws BusinessException 404 */
    public static function de(int $ecole, int $classeId): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()->whereKey($classeId)->where('institution_id', $ecole)->whereNull('klassci_id')->first();
        if (! $classe instanceof Classe) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }

        return $classe;
    }
}

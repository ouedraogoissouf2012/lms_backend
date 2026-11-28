<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\CodeDeClasseDejaEmploye;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Le code d'une classe LOCALE, commun à sa création (#1521) et à sa modification (#1652). Jumeau de
 * `CodeDeMatiere` (#1653).
 *
 * - `normalise` : un code fait de blancs n'est pas un code, il devient NULL. Ce n'est pas cosmétique :
 *   l'unique `(institution_id, code)` admet plusieurs NULL, pas plusieurs chaînes vides.
 * - `ecrire` : la base tranche l'unique, sans SELECT préalable ; sa violation devient une saisie
 *   invalide (422 `class_code_already_used`). Aucune transaction ici : l'attrape n'a rien à relancer.
 */
final class CodeDeClasse
{
    public static function normalise(?string $code): ?string
    {
        $propre = trim((string) $code);

        return $propre === '' ? null : $propre;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $ecriture
     * @return T
     *
     * @throws CodeDeClasseDejaEmploye
     */
    public static function ecrire(Closure $ecriture): mixed
    {
        try {
            return $ecriture();
        } catch (UniqueConstraintViolationException) {
            throw CodeDeClasseDejaEmploye::surLeCode();
        }
    }
}

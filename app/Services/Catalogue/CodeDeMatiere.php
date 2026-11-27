<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\CodeDeMatiereDejaEmploye;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Le code d'une matière LOCALE (#1653), commun à sa création et à sa modification.
 *
 * - `normalise` : un code fait de blancs n'est pas un code, il devient NULL.
 * - `ecrire` : la base tranche l'unique `(institution_id, code_local)`, sans SELECT préalable ;
 *   sa violation devient une saisie invalide (422 `subject_code_already_used`). Une matière
 *   locale n'a aucun autre unique atteignable (`klassci_id` nul), d'où l'attrape sans filtre,
 *   comme pour la classe (#1521). Aucune transaction ici : l'attrape n'a rien à relancer.
 */
final class CodeDeMatiere
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
     * @throws CodeDeMatiereDejaEmploye
     */
    public static function ecrire(Closure $ecriture): mixed
    {
        return $ecriture();
    }
}

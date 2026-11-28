<?php

declare(strict_types=1);

namespace App\Services\Reinscription;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Services\Catalogue\PorteDuCatalogueLocal;

/**
 * Une classe LOCALE de l'école (#1663).
 *
 * - D'une autre école, ou absente : le même 404, qui ne révèle rien d'une autre école.
 * - Une classe MIROIR de l'école (venue de KLASSCI) : elle n'a rien de secret. C'est un refus motivé, 403
 *   `catalogue_managed_by_klassci`, comme tous les refus KLASSCI du dépôt, et non « introuvable ».
 */
final class ClasseLocale
{
    public const INTROUVABLE = 'Classe introuvable dans cet établissement.';

    public const REFUS_MIROIR = 'Cette classe vient de KLASSCI : ses règles de passage se gèrent dans KLASSCI.';

    /** @throws BusinessException 404 hors de l'école ou absente, 403 `catalogue_managed_by_klassci` si miroir */
    public static function de(int $ecole, int $classeId): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()->whereKey($classeId)->where('institution_id', $ecole)->whereNull('klassci_id')->first(); // SONDE #1663
        if (! $classe instanceof Classe) {
            throw new BusinessException(self::INTROUVABLE, 404);
        }
        if ($classe->klassci_id !== null) {
            throw new BusinessException(self::REFUS_MIROIR, 403, reason: PorteDuCatalogueLocal::REASON);
        }

        return $classe;
    }
}

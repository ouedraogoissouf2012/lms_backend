<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/** Des identifiants distincts, entiers et non nuls, triés : la forme commune des compteurs (#1624). */
final class IdsLocaux
{
    /**
     * @param  array<mixed>  $valeurs
     * @return list<int>
     */
    public static function de(array $valeurs): array
    {
        $ids = [];
        foreach ($valeurs as $valeur) {
            if (is_numeric($valeur) && (int) $valeur > 0) {
                $ids[(int) $valeur] = (int) $valeur;
            }
        }
        ksort($ids);

        return array_values($ids);
    }
}

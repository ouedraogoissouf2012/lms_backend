<?php

declare(strict_types=1);

namespace App\Services\Reinscription;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\RegleDePassage;

/**
 * LA moyenne de passage d'une classe (#1663), une seule source pour le bilan de classe (#1713) et les propositions de
 * passage : la surcharge de la classe (`regles_de_passage`), sinon `institutions.settings.seuil_reussite`, sinon 10.
 */
final class SeuilDePassage
{
    public const PAR_DEFAUT = 10.0;

    public static function pour(Classe $classe): float
    {
        $surcharge = RegleDePassage::query()->withoutGlobalScopes()
            ->where('institution_id', $classe->institution_id)->where('classe_id', $classe->id)->value('moyenne_passage');

        return self::lire($surcharge) ?? self::deLEcole((int) $classe->institution_id);
    }

    public static function deLEcole(int $ecole): float
    {
        // Par le modèle : `value()` rendrait le JSON brut de la colonne, sans le cast `array`.
        $reglages = Institution::query()->find($ecole, ['id', 'settings'])?->settings;

        return self::lire(is_array($reglages) ? ($reglages['seuil_reussite'] ?? null) : null) ?? self::PAR_DEFAUT;
    }

    private static function lire(mixed $valeur): ?float
    {
        return is_numeric($valeur) && (float) $valeur >= 0 && (float) $valeur <= 20 ? (float) $valeur : null;
    }
}

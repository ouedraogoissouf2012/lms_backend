<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

/** #1698 (C2) : une école créée depuis le LMS tient ses notes : elle dispense. */
final class LocalePorteDesDispenses implements PorteDesDispenses
{
    public function ouvrir(): void {}
}

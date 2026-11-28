<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;

/**
 * Le refus d'écrire l'entrée d'une classe archivée (#1652) : `class_archived`, 409.
 *
 * Une décision de l'école, pas une panne (#906) : le front l'affiche comme un refus motivé.
 * Un seul message et un seul motif, que posent le service d'inscription (toute porte qui
 * l'emprunte) et l'émission d'un code d'inscription.
 */
final class ClasseArchivee
{
    public const REASON = 'class_archived';

    public const MESSAGE = 'Cette classe est archivée : elle ne reçoit plus d\'inscription.';

    public static function exception(): BusinessException
    {
        return new BusinessException(self::MESSAGE, 409, reason: self::REASON);
    }
}

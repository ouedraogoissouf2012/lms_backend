<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Trop de codes d'émargement erronés pour un élève (#1680) : 429 motivé, avec le délai avant le prochain essai
 * (`Retry-After`). Une {@see BusinessException} ne porte pas ce délai, d'où cette classe. Le message est une
 * constante sûre pour le client ; le contrôleur le lit par elle, jamais par `getMessage()`.
 */
final class EssaisDeCodeEpuises extends RuntimeException
{
    public const REASON = 'attendance_code_attempts_exceeded';

    public const MESSAGE = 'Trop de codes erronés. Réessayez dans quelques minutes.';

    public function __construct(public readonly int $secondesAvantNouvelEssai)
    {
        parent::__construct(self::MESSAGE, 429);
    }
}

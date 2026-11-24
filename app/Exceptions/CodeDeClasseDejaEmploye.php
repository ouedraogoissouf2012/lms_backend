<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Le code d'une classe est déjà employé dans l'établissement (#1521) : une saisie invalide,
 * 422 sur `code`, motif `class_code_already_used`.
 *
 * Levée au point d'écriture, sur la seule violation de l'unique `(institution_id, code)` : la
 * BASE tranche, sans SELECT préalable, et deux requêtes concurrentes restent départagées.
 *
 * Une `ValidationException` : Laravel ne la journalise jamais (`internalDontReport`), et la
 * saisie d'un code pris n'est pas une panne. Elle se rend elle-même, avec son motif, que le
 * rendu des `ValidationException` (`bootstrap/app.php`) ne porte pas : le front traduit par le
 * motif (ADR-906-01), jamais par le message.
 */
final class CodeDeClasseDejaEmploye extends ValidationException
{
    public const MOTIF = 'class_code_already_used';

    private const MESSAGE = 'Ce code est déjà employé par une autre classe de l\'établissement.';

    public static function surLeCode(): self
    {
        return self::withMessages(['code' => self::MESSAGE]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::MESSAGE,
            'reason' => self::MOTIF,
            'errors' => $this->errors(),
        ], 422);
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Le code d'une matière locale est déjà employé dans l'établissement (#1653) : une saisie
 * invalide, 422 sur `code`, motif `subject_code_already_used`. Jumelle de
 * `CodeDeClasseDejaEmploye` (#1521).
 *
 * Levée au point d'écriture, sur la seule violation de l'unique `(institution_id, code_local)` :
 * la BASE tranche, sans SELECT préalable, et deux requêtes concurrentes restent départagées.
 * Une `ValidationException`, rendue avec son motif : le front traduit par le motif
 * (ADR-906-01), jamais par le message.
 */
final class CodeDeMatiereDejaEmploye extends ValidationException
{
    public const MOTIF = 'subject_code_already_used';

    private const MESSAGE = 'Ce code est déjà employé par une autre matière de l\'établissement.';

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

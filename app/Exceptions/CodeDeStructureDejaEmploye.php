<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Le code d'une filière ou d'un niveau d'études est déjà employé dans l'école (#1648) : 422 sous `code`,
 * avec un motif que le front range sous ce champ. La base tranche (unique `(institution_id, code_local)`) ;
 * cette exception traduit sa violation, comme `CodeDeMatiereDejaEmploye` (#1653).
 */
final class CodeDeStructureDejaEmploye extends ValidationException
{
    private string $motif = '';

    private string $texte = '';

    public static function surLeCode(string $motif, string $texte): self
    {
        $refus = self::withMessages(['code' => $texte]);
        $refus->motif = $motif;
        $refus->texte = $texte;

        return $refus;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->texte,
            'reason' => $this->motif,
            'errors' => $this->errors(),
        ], 422);
    }
}

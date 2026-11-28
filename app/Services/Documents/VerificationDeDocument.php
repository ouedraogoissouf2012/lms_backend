<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\DocumentEmis;

/**
 * Vérifier qu'un document de scolarité est authentique, par son code (#1662). Route PUBLIQUE : elle ne rend que ce
 * qui est déjà imprimé sur le document (type, numéro, date d'émission, école, titulaire), rien d'autre ; un code
 * inconnu et un code mal formé reçoivent la même réponse (`null` → le même 404).
 */
final class VerificationDeDocument
{
    /** @return array{type: string, numero: string, emis_le: string, ecole: string, titulaire: string}|null */
    public function verifier(string $code): ?array
    {
        if (preg_match('/^[A-Z0-9]{12}$/', $code) !== 1) {
            return null;
        }

        $document = DocumentEmis::query()->withoutGlobalScopes()->where('code_verification', $code)->first();

        if (! $document instanceof DocumentEmis) {
            return null;
        }

        return [
            'type' => $document->type->value,
            'numero' => $document->numero,
            'emis_le' => $document->created_at->toDateString(),
            'ecole' => (string) ($document->contenu['ecole'] ?? ''),
            'titulaire' => (string) ($document->contenu['titulaire'] ?? ''),
        ];
    }
}

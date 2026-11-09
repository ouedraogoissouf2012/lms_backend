<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Concerns;

use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La panne de BASE de la fiche d'une classe, rendue de la même façon par ses
 * deux portes (`lms/classes/{id}` et `lms/classes/local/{id}`) : journalisée
 * côté serveur, un 500 générique côté client (§1.2).
 *
 * Les autres erreurs internes, non : la porte historique les rattrape aussi par
 * ce rendu (son filet `Throwable`, préexistant), la porte locale les laisse au
 * gestionnaire global (500 sans `error`).
 *
 * Une `QueryException` y passe AVANT le rendu des échecs KLASSCI : elle est une
 * `RuntimeException` (par `PDOException`), et sans cette branche une panne de
 * base serait rendue comme un échec de KLASSCI. Dans une école créée depuis le
 * LMS, toute panne de la fiche est une panne de base.
 *
 * @see RendersKlassciBackedErrors
 */
trait RendersClasseDetailsFailure
{
    protected function echecDetailsClasse(LoggerInterface $logger, int $classeId, Throwable $e): JsonResponse
    {
        $logger->error('Erreur récupération détails classe', [
            'classe_id' => $classeId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des détails de la classe',
            'error' => 'Une erreur est survenue.',
        ], 500);
    }
}

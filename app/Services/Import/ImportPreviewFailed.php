<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * Le fichier ne peut pas être analysé du tout (#718).
 *
 * À distinguer d'une ligne refusée, que le rapport porte déjà avec son code :
 * ici aucune ligne n'est lisible, il n'y a donc pas de rapport à rendre. Le
 * code machine est stable et destiné au client ; le message est sûr à afficher.
 *
 * Sépare la couche métier de HTTP : le controller traduit en 422, le service
 * n'a pas à connaître les codes de statut, et League\Csv ne remonte pas
 * jusqu'au controller.
 */
final class ImportPreviewFailed extends RuntimeException
{
    private function __construct(
        private readonly string $machineCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function duplicateHeader(): self
    {
        return new self(
            'duplicate_header',
            'Deux colonnes du fichier portent le même nom. Renommez-les avant de réessayer.',
        );
    }

    /**
     * Code stable pour le client. `getCode()` est un entier sur Throwable, d'où
     * un accesseur distinct.
     */
    public function machineCode(): string
    {
        return $this->machineCode;
    }
}

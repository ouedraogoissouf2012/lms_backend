<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;

/**
 * Créer une classe SANS KLASSCI (#848).
 *
 * ## Le manque que ce service comble
 *
 * Avant lui, le seul créateur d'une `Classe` dans tout `app/` était
 * `ClasseSyncService:238` — la synchronisation KLASSCI. Une école autonome
 * n'avait donc aucune classe, jamais. Et l'import CSV, qui crée pourtant de
 * vrais comptes (`ImportApplyService:147`), sort en silence quand la classe est
 * introuvable (`:165-170`) : il produisait des apprenants qui n'appartenaient à
 * rien.
 *
 * ## La garde est ICI, au point d'écriture
 *
 * Par `PorteDuCatalogueLocal` (#910), la seule porte du catalogue local : elle
 * dit pourquoi la garde vit au point d'appel, et dans quel ordre.
 *
 * ## Le code vide devient nul, et ce n'est pas cosmétique
 *
 * `classes.code` porte désormais un unique `(institution_id, code)`. SQL
 * autorise les `NULL` en doublon mais pas les chaînes vides : sans cette
 * normalisation, la DEUXIÈME classe créée sans code échouerait en collision.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalClasseCreator
{
    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
    ) {}

    /**
     * @param  array{libelle: string, code?: string|null, description?: string|null, effectif?: int|null}  $donnees
     *
     * @throws BusinessException si l'établissement ne tient pas son catalogue,
     *                           ou si aucun tenant n'est résolu.
     */
    public function creer(array $donnees): Classe
    {
        $institution = $this->porte->etablissement();

        return Classe::create([
            'libelle' => $donnees['libelle'],
            'code' => $this->codeNormalise($donnees['code'] ?? null),
            'description' => $donnees['description'] ?? null,
            'effectif' => $donnees['effectif'] ?? 0,
            'institution_id' => $institution,
            // JAMAIS d'identifiant KLASSCI : une classe locale n'en a pas, et lui
            // en inventer un la rendrait indiscernable d'une classe miroitée.
            'klassci_id' => null,
        ]);
    }

    private function codeNormalise(?string $code): ?string
    {
        $propre = trim((string) $code);

        return $propre === '' ? null : $propre;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Services\TenantManager;

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
 * ## La garde est ICI, pas seulement à la route
 *
 * `CatalogueAuthority` est consultée au point d'écriture. Une garde posée
 * uniquement sur la route protégerait cet appelant-là ; posée ici, elle protège
 * tout appelant futur — commande de console, job, second contrôleur. Le service
 * demande un DROIT et ignore qu'un mode existe (épique #697, art. 1).
 *
 * Refuser à un établissement KLASSCI n'est pas une restriction commerciale :
 * c'est la préservation d'une source unique. Deux sources écrivant la même
 * réalité est la cause racine de #673, déjà payée une fois.
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
        private readonly CatalogueAuthority $autorite,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * @param  array{libelle: string, code?: string|null, description?: string|null, effectif?: int|null}  $donnees
     *
     * @throws BusinessException si l'établissement ne tient pas son catalogue,
     *                           ou si aucun tenant n'est résolu.
     */
    public function creer(array $donnees): Classe
    {
        if (! $this->autorite->allowsLocalCatalogue()) {
            throw new BusinessException(
                'Les classes de cet établissement viennent de KLASSCI et ne se créent pas ici.',
                403
            );
        }

        $institution = $this->tenants->id();

        // Fail-secure, et non une garde décorative : l'autorité rend déjà `false`
        // hors établissement. Ce second contrôle existe parce qu'une classe sans
        // `institution_id` échapperait au scope multi-tenant — le laisser
        // dépendre d'une seule garde en ferait une fuite dès qu'elle bouge.
        if ($institution === null) {
            throw new BusinessException('Aucun établissement résolu.', 409);
        }

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

<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Matiere;
use App\Services\TenantManager;

/**
 * Créer une matière SANS KLASSCI (#797, #848).
 *
 * ## Le manque que ce service comble
 *
 * Avant lui, le seul créateur d'une `Matiere` du dépôt était
 * `ClasseMatieresSynchronizer` — la synchronisation KLASSCI, qui écrit d'ailleurs
 * par `new Matiere()` + `save()`, si bien qu'un `grep` sur `updateOrCreate` ne le
 * voyait pas. Une école autonome n'avait donc aucune matière, jamais : un
 * formateur n'avait rien à enseigner.
 *
 * La classe était livrée par #860 ; la matière est le second des trois lots de
 * l'ADR, et le premier à exiger une migration avant son écrivain.
 *
 * ## La garde est ICI, au point d'écriture
 *
 * Même raison qu'en #860 : posée sur la route, elle protégerait cet appelant-là ;
 * posée ici, elle protège toute commande, job ou contrôleur à venir. Le service
 * demande un DROIT et ignore qu'un mode existe (épique #697, art. 1).
 *
 * Refuser à un établissement KLASSCI préserve une source unique — deux sources
 * écrivant la même réalité est la cause racine de #673.
 *
 * ## Ce qui N'EST PAS repris de la Classe
 *
 * Aucune normalisation du code vide en nul, et aucun unique sur `matieres.code` :
 * mesuré, **aucun accès par code** n'existe sur les matières dans `app/`. La
 * normalisation de #860 servait une contrainte réelle ; ici elle serait un geste
 * sans cause.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalMatiereCreator
{
    public function __construct(
        private readonly CatalogueAuthority $autorite,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * @param  array{libelle: string, code?: string|null, description?: string|null, coefficient?: int|null, credit?: int|null}  $donnees
     *
     * @throws BusinessException si l'établissement ne tient pas son catalogue,
     *                           ou si aucun tenant n'est résolu.
     */
    public function creer(array $donnees): Matiere
    {
        if (! $this->autorite->allowsLocalCatalogue()) {
            throw new BusinessException(
                'Les matières de cet établissement viennent de KLASSCI et ne se créent pas ici.',
                403
            );
        }

        $institution = $this->tenants->id();

        // Fail-secure et non décoratif : une matière sans `institution_id`
        // échapperait au scope multi-tenant. Le faire dépendre d'une seule garde
        // en ferait une fuite dès que celle-ci bouge.
        if ($institution === null) {
            throw new BusinessException('Aucun établissement résolu.', 409);
        }

        return Matiere::create([
            'libelle' => $donnees['libelle'],
            'code' => $donnees['code'] ?? null,
            'description' => $donnees['description'] ?? null,
            'coefficient' => $donnees['coefficient'] ?? 1,
            'credit' => $donnees['credit'] ?? 1,
            'institution_id' => $institution,
            // JAMAIS d'identifiant KLASSCI inventé : il rendrait la matière
            // indiscernable d'une matière miroitée, et la ferait entrer dans les
            // correspondances par `whereIn('klassci_id', …)` où elle n'a rien à
            // faire.
            'klassci_id' => null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Services\Roster\RosterAuthorityFactory;
use App\Services\TenantManager;

/**
 * La seule porte du catalogue local (#910) : qui peut écrire — ou lire, pour
 * les classes locales — le catalogue pédagogique tenu dans le LMS.
 *
 * ## Une règle, un endroit
 *
 * Avant #910, cinq services écrivaient cette garde à la main, et divergeaient
 * sur l'ordre, sur le message et sur le motif du 409. La règle est une seule
 * décision : elle vit ici, et une garde d'architecture refuse qu'on la recopie.
 *
 * ## La garde est au point d'appel, pas seulement à la route
 *
 * Posée sur la route, elle protégerait cet appelant-là ; posée ici, elle
 * protège tout appelant futur — commande de console, job, second contrôleur.
 * Le service demande un DROIT et ignore qu'un mode existe (épique #697, art. 1).
 * Refuser à un établissement KLASSCI préserve une source unique : deux sources
 * écrivant la même réalité est la cause racine de #673.
 *
 * ## L'établissement AVANT le droit (l'ordre de #905)
 *
 * Sans établissement, le droit rend faux, et un compte de plateforme
 * recevrait « vient de KLASSCI », qui est faux pour qui n'en a aucun. Le 409
 * dit ce qui manque. Fail-secure, et non décoratif : une classe sans
 * `institution_id` échapperait au scope multi-tenant.
 *
 * ## Relue à CHAQUE appel
 *
 * L'école est lue à l'appel, jamais à la construction. Un même objet sert
 * plusieurs écoles — un job qui en traverse plusieurs, un contrôleur que
 * Laravel garde sur l'objet Route — et un droit capturé rejouerait celui de la
 * première : une école KLASSCI écrirait un catalogue local (épique #697, art. 3).
 * L'école n'est lue qu'une fois par appel : le droit et l'identifiant rendu
 * portent sur la même.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class PorteDuCatalogueLocal
{
    /** Le refus d'une école KLASSCI, le même pour tout geste sur son catalogue. */
    public const REFUS = 'Le catalogue de cet établissement vient de KLASSCI.';

    /**
     * Son motif (#1184, ADR-906-01) : un concept — le catalogue de cette école vit
     * dans KLASSCI —, un motif, pour tout geste de la porte : classes, matières,
     * programme d'une classe, codes d'inscription, sessions de formation.
     */
    public const REASON = 'catalogue_managed_by_klassci';

    public function __construct(
        private readonly RosterAuthorityFactory $autorites,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * L'identifiant de l'établissement qui tient son catalogue, ou le refus.
     *
     * @throws BusinessException 409 `no_institution` sans établissement résolu,
     *                           403 `catalogue_managed_by_klassci` si l'établissement ne
     *                           tient pas son catalogue
     */
    public function etablissement(): int
    {
        $ecole = $this->tenants->get();

        if ($ecole === null) {
            throw new BusinessException('Aucun établissement résolu.', 409, reason: 'no_institution');
        }

        if (! $this->autorites->catalogueForInstitution($ecole)->allowsLocalCatalogue()) {
            throw new BusinessException(self::REFUS, 403, reason: self::REASON);
        }

        return $ecole->id;
    }
}

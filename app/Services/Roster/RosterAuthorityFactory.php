<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Catalogue\KlassciCatalogueAuthority;
use App\Services\Catalogue\LocalCatalogueAuthority;
use App\Services\TenantManager;
use Tests\Feature\Roster\RosterAuthorityResolutionTest;

/**
 * LE point où le mode d'un établissement se résout (#805, épique #697 art. 1).
 *
 * ## Pourquoi ce fichier et pas le fournisseur de services
 *
 * La liste d'exemption de la garde OCP travaille par FICHIER. Exempter
 * `AppServiceProvider` ouvrirait un fichier de plusieurs centaines de lignes
 * où une comparaison de mode pourrait se glisser sans être vue. Ici, la
 * dérogation tient en une classe de vingt lignes dont c'est la seule raison
 * d'être.
 *
 * ## Pourquoi le mode déclaré, et pas l'absence d'URL KLASSCI
 *
 * Une colonne nullable ne distingue pas « pas encore configuré » de
 * « délibérément autonome ». Dériver le mode de `klassci_api_url IS NULL`
 * ferait donc d'une faute de saisie un changement de mode en production, en
 * silence — ce que `docs/PLAN_AUTONOMIE_KLASSCI.md` §4.1 et §6 interdisent
 * nommément.
 *
 * Et `KlassciTargetResolver::baseUrl()` ne peut pas davantage servir de
 * discriminant, mais la raison a changé depuis #792 (PR #844). Elle ne tient
 * plus au déploiement : la priorité 3 ne se rabat plus sur la configuration
 * globale, et le `null` d'un tenant résolu y est désormais absorbant — une
 * école autonome reçoit donc bien `null`, de façon fiable.
 *
 * Ce que `baseUrl()` décrit reste néanmoins une LIAISON RÉSEAU, pas une
 * intention. Les deux axes sont volontairement orthogonaux : une école peut
 * n'avoir aucune cible KLASSCI sans avoir déclaré vouloir tenir sa propre
 * liste, et {@see RosterAuthorityResolutionTest} épingle
 * les deux cas croisés. Dériver l'autorité de roster d'une URL absente
 * confondrait « injoignable » et « autonome ».
 *
 * ## Fail-secure
 *
 * Hors contexte d'établissement — un job, une route publique — aucune autorité
 * locale n'est accordée.
 */
final class RosterAuthorityFactory
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function forCurrentTenant(): RosterAuthority
    {
        return $this->forInstitution($this->tenants->get());
    }

    /**
     * Pour une institution nommee, quand le tenant ambiant n'est pas encore
     * resolu : au login, l'utilisateur n'est pas authentifie et
     * `ResolveInstitution` n'a donc pas pu s'executer avec son etablissement.
     *
     * `null` rend l'autorite la plus restrictive — hors etablissement, aucune
     * inscription locale.
     */
    public function forInstitution(?Institution $institution): RosterAuthority
    {
        return $institution?->mode === InstitutionMode::Standalone
            ? new LocalRosterAuthority
            : new KlassciRosterAuthority;
    }

    /**
     * Qui écrit le catalogue pédagogique — classes, matières (#848).
     *
     * Produite ICI et non par une seconde fabrique : la liste d'exemption
     * travaille par FICHIER et prévient qu'une entrée de plus « revient à
     * déplacer la frontière d'architecture ». Un second point de résolution du
     * mode est exactement ce que cette classe existe pour empêcher.
     *
     * Le nom de la classe en devient trop étroit — elle résout le mode, elle ne
     * sert plus le seul roster. Dette assumée : la renommer déplacerait une
     * entrée d'un fichier sous CODEOWNERS, ce qui n'a pas sa place dans le lot
     * qui introduit la capacité.
     */
    public function catalogueForCurrentTenant(): CatalogueAuthority
    {
        return $this->catalogueForInstitution($this->tenants->get());
    }

    /**
     * `null` rend l'autorité la plus restrictive : hors établissement — un job,
     * une route publique — aucune écriture locale du catalogue.
     */
    public function catalogueForInstitution(?Institution $institution): CatalogueAuthority
    {
        return $institution?->mode === InstitutionMode::Standalone
            ? new LocalCatalogueAuthority
            : new KlassciCatalogueAuthority;
    }
}

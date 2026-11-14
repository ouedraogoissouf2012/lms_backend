<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Catalogue\KlassciCatalogueAuthority;
use App\Services\Catalogue\LocalCatalogueAuthority;
use App\Services\TenantManager;
use Illuminate\Contracts\Container\Container;

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
 * liste, et `tests/Feature/Roster/RosterAuthorityResolutionTest.php` épingle
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
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly Container $container,
    ) {}

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

    /**
     * La SOURCE d'une lecture : la même route sert les deux volets, seule
     * l'implémentation change (école indépendante, lot 1).
     *
     * Une méthode générique et non une par lecture : chacune recopierait la
     * comparaison au mode, et ce fichier existe pour qu'elle n'ait lieu qu'à
     * un seul endroit. L'appelant nomme deux classes, il ne reçoit jamais le
     * mode.
     *
     * Sans établissement, la source KLASSCI : c'est le comportement d'avant ce
     * choix, et un compte sans école s'arrête déjà à la porte de #984.
     *
     * Résolue par le conteneur : une instance qu'un test y a posée pour la
     * classe KLASSCI reste servie, et les dépendances suivent le câblage
     * habituel.
     *
     * @template TSource of object
     *
     * @param  class-string<TSource>  $klassci
     * @param  class-string<TSource>  $local
     * @return TSource
     */
    public function sourceForCurrentTenant(string $klassci, string $local): object
    {
        return $this->sourceForInstitution($this->tenants->get(), $klassci, $local);
    }

    /**
     * La même source, pour un établissement NOMMÉ plutôt que pour le tenant
     * courant : là où le tenant manque (un job qui ne l'entre pas), la question
     * porte sur une entité qui désigne elle-même son école (une classe, une
     * séance). Sans établissement, la source KLASSCI, comme sans tenant.
     *
     * @template TSource of object
     *
     * @param  class-string<TSource>  $klassci
     * @param  class-string<TSource>  $local
     * @return TSource
     */
    public function sourceForInstitution(?Institution $institution, string $klassci, string $local): object
    {
        return $this->container->make(
            $institution?->mode === InstitutionMode::Standalone ? $local : $klassci,
        );
    }

    /**
     * La même source, pour l'établissement d'un COMPTE (D496). Le tenant quand il
     * est cet établissement : c'est toute requête authentifiée, `ResolveInstitution`
     * le pose sur l'établissement du jeton, et aucune lecture de plus n'a lieu (le
     * garde Sanctum recharge le compte dans une autre instance, sans sa relation).
     * Sinon (un job, une commande, sans tenant) : l'établissement lu sur le compte.
     * Un compte sans établissement : la source KLASSCI.
     *
     * @template TSource of object
     *
     * @param  class-string<TSource>  $klassci
     * @param  class-string<TSource>  $local
     * @return TSource
     */
    public function sourceForAccount(User $compte, string $klassci, string $local): object
    {
        $tenant = $this->tenants->get();
        $ecole = match (true) {
            $tenant !== null && $tenant->id === $compte->institution_id => $tenant,
            $compte->institution_id === null => null,
            // Une école supprimée en douceur garde son mode : la relation l'ignorerait.
            default => Institution::withTrashed()->find($compte->institution_id),
        };

        return $this->sourceForInstitution($ecole, $klassci, $local);
    }
}

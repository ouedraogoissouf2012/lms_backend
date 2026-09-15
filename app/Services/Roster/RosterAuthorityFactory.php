<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Enums\InstitutionMode;
use App\Services\TenantManager;

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
 * discriminant : sa priorité 3 retombe sur la configuration GLOBALE, si bien
 * qu'un établissement autonome reçoit une URL non nulle dès que
 * `KLASSCI_API_URL` est définie. Son `null` ne vaut « autonome » que par
 * accident de déploiement.
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
        $institution = $this->tenants->get();

        return $institution?->mode === InstitutionMode::Standalone
            ? new LocalRosterAuthority
            : new KlassciRosterAuthority;
    }
}

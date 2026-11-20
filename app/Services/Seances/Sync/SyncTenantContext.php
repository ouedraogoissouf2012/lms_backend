<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\TenantManager;

/**
 * Contexte tenant d'une passe de synchronisation.
 *
 * ## Pourquoi ce collaborateur existe
 *
 * Une requête HTTP pose son tenant via `ResolveInstitution`, à partir du Bearer
 * token. Un JOB n'a ni requête ni utilisateur authentifié : `KlassciConfigResolver`
 * ne peut alors résoudre l'URL amont ni par le porteur du token (priorité 1) ni
 * par son institution (priorité 2), et retombe sur `TenantManager::klassciConfig()`.
 *
 * Sans tenant posé, cette dernière priorité lit une configuration globale qui
 * n'existe pas en multi-tenant : chaque enseignant partait alors en
 * `KlassciUnavailableException`, la table des séances restait vide, et l'écran
 * des managers — qui lit le local — avec elle.
 *
 * ## L'oubli fait partie du geste
 *
 * La résolution est mémoïsée pour la durée de l'instance, laquelle vit toute la
 * passe. Changer de tenant sans oublier la résolution précédente enverrait le
 * trafic du second établissement vers l'URL du premier, avec son token : ce
 * n'est pas une optimisation manquée, c'est une fuite cross-tenant.
 *
 * @see app/Services/Klassci/KlassciConfigResolver.php
 * @see app/Services/Seances/Sync/KlassciSeancesSyncService.php
 */
final class SyncTenantContext
{
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly KlassciConfigResolver $configResolver,
    ) {}

    /**
     * Bascule le contexte sur l'institution donnée.
     *
     * Sans effet si le tenant est déjà celui-là : réinitialiser la résolution
     * pour rien coûterait un aller-retour de configuration à chaque enseignant
     * d'un même établissement.
     *
     * Une institution introuvable laisse le contexte INCHANGÉ plutôt que de le
     * vider : l'appel échouera pour ce seul enseignant, que la passe isole déjà,
     * là où un contexte effacé ferait repartir le suivant sans tenant.
     */
    public function enter(int $institutionId): void
    {
        if ($this->tenantManager->id() === $institutionId) {
            return;
        }

        $institution = Institution::find($institutionId);
        if (! $institution instanceof Institution) {
            return;
        }

        $this->tenantManager->set($institution);
        $this->configResolver->forget();
    }

    /**
     * Entre dans l'établissement pour un job qui enverra le jeton PERSONNEL du compte, et
     * dit si ce jeton peut partir (#1249).
     *
     * Sans compte authentifié, un job résout sa cible par le tenant (priorité 3) : l'URL de
     * l'ÉCOLE, pas celle qui a émis le jeton. Vrai seulement si cette cible est EXACTEMENT
     * celle du compte ({@see KlassciConfigResolver::cibleDeSynchronisation()}) : une école
     * détachée, une adresse X qui n'est pas l'URL Y de l'école, ou un job mis en file pour
     * une autre école ne reçoivent jamais ce jeton.
     */
    public function entrerPourLeJetonDe(User $compte, int $institutionId): bool
    {
        $this->enter($institutionId);
        $cible = $this->configResolver->cibleDeSynchronisation($compte);

        return $cible !== null && $this->configResolver->baseUrl() === $cible;
    }
}

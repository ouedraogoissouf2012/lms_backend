<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Institution;
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
}

<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Models\Institution;
use App\Services\TenantManager;
use Illuminate\Support\Collection;

/**
 * Annuaire PUBLIC des institutions (issue #375).
 *
 * Extrait verbatim des closures de `routes/api/core.php` (§5 : jamais de
 * requête Eloquent dans le fichier de routes ; §1.6 D : TenantManager injecté
 * au lieu de `app()`).
 *
 * ## SECURITY — périmètre public assumé
 *
 * Contrairement à {@see InstitutionQueryService} (réservé supradmin,
 * cross-tenant avec stats), ce service n'expose QUE les 4 champs de branding
 * nécessaires au sélecteur de la page de login, pour les seules institutions
 * actives. Ne jamais y ajouter de champ sans réévaluer l'exposition publique
 * (décision tracée sur l'issue #375).
 */
final class InstitutionDirectoryService
{
    /** Champs publics exposés — l'ordre fait partie du contrat JSON. */
    private const PUBLIC_FIELDS = ['slug', 'name', 'logo_url', 'primary_color'];

    public function __construct(
        private readonly TenantManager $tenantManager,
    ) {
    }

    /**
     * Institutions actives, triées par nom (sélecteur public de login).
     *
     * @return Collection<int, Institution>
     */
    public function listActive(): Collection
    {
        return Institution::where('is_active', true)
            ->orderBy('name')
            ->get(self::PUBLIC_FIELDS);
    }

    /**
     * Descripteur public du tenant courant, ou null si aucun tenant résolu
     * (pas de header X-Institution ni de token — cf. ResolveInstitution).
     *
     * @return array{slug: ?string, name: ?string, logo_url: ?string, primary_color: ?string}|null
     */
    public function currentDescriptor(): ?array
    {
        $institution = $this->tenantManager->get();

        if ($institution === null) {
            return null;
        }

        return [
            'slug' => $institution->slug,
            'name' => $institution->name,
            'logo_url' => $institution->logo_url,
            'primary_color' => $institution->primary_color,
        ];
    }
}

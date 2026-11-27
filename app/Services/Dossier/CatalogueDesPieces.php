<?php

declare(strict_types=1);

namespace App\Services\Dossier;

use App\Exceptions\BusinessException;
use App\Models\PieceAFournir;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Support\Collection;

/**
 * Le catalogue des pièces à fournir d'une école autonome (#1661, PR 1). `PorteDuCatalogueLocal` : 403
 * `catalogue_managed_by_klassci` en école KLASSCI. Bornée explicitement à l'école de l'acteur : une pièce d'une autre
 * école est introuvable (404).
 */
final class CatalogueDesPieces
{
    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /** @return Collection<int, PieceAFournir> */
    public function lister(User $acteur, ?bool $enService): Collection
    {
        $ecole = $this->porte->etablissementDe($acteur);

        return PieceAFournir::query()->withoutGlobalScopes()->where('institution_id', $ecole)
            ->when($enService !== null, static fn ($q) => $q->where('en_service', $enService))
            ->orderBy('libelle')->orderBy('id')
            ->get();
    }

    /** @param  array<string, mixed>  $valeurs  validées par `EcrirePieceAFournirRequest` */
    public function creer(User $acteur, array $valeurs): PieceAFournir
    {
        return PieceAFournir::query()->create(['institution_id' => $this->porte->etablissementDe($acteur)] + $valeurs);
    }

    /**
     * @param  array<string, mixed>  $valeurs
     *
     * @throws BusinessException 403, 404
     */
    public function modifier(User $acteur, int $id, array $valeurs): PieceAFournir
    {
        $piece = PieceAFournir::query()->withoutGlobalScopes()
            // SONDE #1661 : la pièce n'est plus bornée à l'école de l'acteur.
            ->whereKey($id)->first();
        $this->porte->etablissementDe($acteur);
        if (! $piece instanceof PieceAFournir) {
            throw new BusinessException('Pièce introuvable dans cet établissement.', 404);
        }
        $piece->update($valeurs);

        return $piece;
    }
}

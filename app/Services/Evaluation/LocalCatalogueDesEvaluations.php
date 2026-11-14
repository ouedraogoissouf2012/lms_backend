<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Le catalogue d'une évaluation dans une école créée depuis le LMS (D72, E4b, C27) :
 * la présentation sans catalogue KLASSCI (`enrichWithoutCatalogue`, E3), qui lit les
 * libellés posés sur la ligne et l'auteur local. Les filtres portent sur les ids
 * locaux. Aucun appel à KLASSCI, même pour un compte porteur d'un jeton.
 */
final class LocalCatalogueDesEvaluations implements CatalogueDesEvaluations
{
    public function __construct(private readonly EvaluationEnrichmentService $presentation) {}

    public function presenter(Collection $evaluations, User $appelant): array
    {
        return $this->presentation->enrichWithoutCatalogue($evaluations);
    }

    public function colonne(FiltreDeListe $filtre): string
    {
        return match ($filtre) {
            FiltreDeListe::Classe => 'classe_id',
            FiltreDeListe::Matiere => 'matiere_id',
        };
    }
}

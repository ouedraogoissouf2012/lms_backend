<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Le catalogue d'une évaluation dans une école KLASSCI : le comportement d'avant E4b,
 * inchangé. La présentation lit les classes et les matières chez KLASSCI
 * (`EvaluationEnrichmentService::enrich`, fail-soft), avec le jeton de l'appelant ;
 * les filtres portent sur les identités KLASSCI.
 */
final class KlassciCatalogueDesEvaluations implements CatalogueDesEvaluations
{
    public function __construct(private readonly EvaluationEnrichmentService $presentation) {}

    public function presenter(Collection $evaluations, User $appelant): array
    {
        $jeton = $appelant->klassci_token;

        return $this->presentation->enrich($evaluations, is_string($jeton) ? $jeton : '');
    }

    public function colonne(FiltreDeListe $filtre): string
    {
        return match ($filtre) {
            FiltreDeListe::Classe => 'klassci_classe_id',
            FiltreDeListe::Matiere => 'klassci_matiere_id',
        };
    }
}

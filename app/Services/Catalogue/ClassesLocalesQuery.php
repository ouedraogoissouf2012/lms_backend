<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\PlacesDeClasse;
use App\Enums\StatutDeClasse;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Les classes LOCALES d'un établissement (#905, ADR-905-01).
 *
 * ## Le manque que ce service comble
 *
 * Seule une classe locale porte un code d'inscription (ADR-803-03), et rien ne
 * permettait de les lire : la liste d'administration vient de KLASSCI, la
 * fiche aussi, et la porte locale de #760 répond 409 pour une classe sans
 * identifiant KLASSCI — elle traduit vers KLASSCI par construction. Sans cette
 * lecture, un coordinateur ne pouvait ni trouver sa classe, ni relire son code
 * sans le régénérer, donc sans invalider celui déjà dicté.
 *
 * ## Une classe locale : sans `klassci_id`, dans CET établissement
 *
 * C'est la définition d'ADR-848-01 : `LocalClasseCreator` n'en invente jamais,
 * pour qu'une classe locale reste discernable d'une classe miroitée.
 * L'établissement est borné explicitement : le scope multi-tenant est
 * fail-OPEN, il se tairait si aucun tenant n'était résolu.
 *
 * ## Même droit que l'écriture
 *
 * Un établissement qui ne tient pas son catalogue n'a pas de classes locales à
 * gérer. Lire et émettre obéissent à la même règle, et à la même porte :
 * `PorteDuCatalogueLocal` (#910).
 *
 * ## Les filtres (#1652)
 *
 * Par statut (date d'archive) et par places. « Pleine » est compté par la base, sur la MÊME
 * relation que `Classe::isComplete()` (`etudiantsActifs`) : le filtre et le refus d'une
 * inscription ne peuvent pas diverger sur ce qu'est une place prise.
 */
final class ClassesLocalesQuery
{
    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Classe>
     *
     * @throws BusinessException 403 sans catalogue local, 409 sans établissement
     */
    public function lister(int $parPage, ?StatutDeClasse $statut = null, ?PlacesDeClasse $places = null): LengthAwarePaginator
    {
        $institution = $this->porte->etablissement();

        $classes = Classe::query()->withoutGlobalScopes()
            ->where('institution_id', $institution)
            ->whereNull('klassci_id');

        match ($statut) {
            StatutDeClasse::Active => $classes->whereNull('archivee_le'),
            StatutDeClasse::Archivee => $classes->whereNotNull('archivee_le'),
            null => null,
        };

        match ($places) {
            PlacesDeClasse::Pleine => $this->pleines($classes),
            // Le complément EXACT de « pleine » (`effectif` n'est jamais nul : défaut 0).
            PlacesDeClasse::Disponibles => $classes->whereNot(fn (Builder $q) => $this->pleines($q)),
            null => null,
        };

        // `id` en second critère : deux libellés égaux garderaient sinon un
        // ordre instable d'une page à l'autre, et une classe pourrait
        // apparaître deux fois — ou jamais — en paginant.
        return $classes->orderBy('libelle')->orderBy('id')->paginate($parPage);
    }

    /** @param Builder<Classe> $classes */
    private function pleines(Builder $classes): void
    {
        $classes->where('effectif', '>', 0)->has('etudiants', '>=', $this->capacite($classes));
    }

    /**
     * La capacité de la ligne, comme COLONNE et non comme valeur liée : `has()` compare le
     * compte des adhésions actives à cette expression.
     *
     * @param  Builder<Classe>  $classes
     * @return Expression<string>
     */
    private function capacite(Builder $classes): Expression
    {
        return new Expression($classes->getQuery()->getGrammar()->wrap($classes->getModel()->qualifyColumn('effectif')));
    }
}

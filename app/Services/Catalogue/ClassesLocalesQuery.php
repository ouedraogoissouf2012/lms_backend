<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
    public function lister(int $parPage): LengthAwarePaginator
    {
        $institution = $this->porte->etablissement();

        // `id` en second critère : deux libellés égaux garderaient sinon un
        // ordre instable d'une page à l'autre, et une classe pourrait
        // apparaître deux fois — ou jamais — en paginant.
        return Classe::query()->withoutGlobalScopes()
            ->where('institution_id', $institution)
            ->whereNull('klassci_id')
            ->orderBy('libelle')
            ->orderBy('id')
            ->paginate($parPage);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Services\TenantManager;
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
 * gérer. Il reçoit le refus, mot pour mot, que `ClasseEnrolmentCodeService` lui
 * oppose à l'émission du code : lire et émettre obéissent à la même règle.
 */
final class ClassesLocalesQuery
{
    public function __construct(
        private readonly CatalogueAuthority $autorite,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Classe>
     *
     * @throws BusinessException 403 sans catalogue local, 409 sans établissement
     */
    public function lister(int $parPage): LengthAwarePaginator
    {
        $institution = $this->tenants->id();

        // AVANT la capacité : sans établissement, celle-ci rend faux — et un
        // compte de plateforme recevrait « cet établissement vient de KLASSCI »,
        // qui est faux pour qui n'en a aucun. Le 409 dit ce qui manque.
        if ($institution === null) {
            throw new BusinessException('Aucun établissement résolu.', 409);
        }

        if (! $this->autorite->allowsLocalCatalogue()) {
            throw new BusinessException(
                'Les inscriptions de cet établissement viennent de KLASSCI.',
                403
            );
        }

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

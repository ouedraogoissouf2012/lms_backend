<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Les classes de l'établissement par leur id LOCAL, dans les deux volets (#1709) : de quoi
 * désigner une classe là où le serveur attend un id local, comme le filtre du rapport de
 * présences.
 *
 * ## Une seule source, pas de choix de volet
 *
 * La table `classes` tient les deux : les classes créées dans le LMS, et les miroirs des
 * classes KLASSCI. Elle répond donc pareil dans les deux types d'école, sans fabrique.
 * `GET /proxy/classes` ne convient pas : dans une école KLASSCI, il rend les ids de KLASSCI
 * (D41). `GET /classes` non plus : il ne liste que les classes sans `klassci_id`, avec
 * l'état de leur code d'inscription.
 *
 * Une classe KLASSCI pas encore miroitée n'a pas d'id local : elle n'apparaît pas, faute de
 * rien qui la désigne côté serveur.
 *
 * Bornage EXPLICITE à l'établissement : le scope global ne filtre rien sans tenant.
 */
final class ClassesDeLEtablissementQuery
{
    /**
     * @return LengthAwarePaginator<int, Classe>
     */
    public function lister(int $institutionId, int $parPage): LengthAwarePaginator
    {
        // `id` en second critère : deux libellés égaux garderaient sinon un ordre instable
        // d'une page à l'autre (même règle que `ClassesLocalesQuery`).
        return Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $institutionId)
            ->orderBy('libelle')
            ->orderBy('id')
            ->paginate($parPage, ['id', 'libelle']);
    }
}

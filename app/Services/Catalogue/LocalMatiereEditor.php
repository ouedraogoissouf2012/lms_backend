<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Exceptions\CodeDeMatiereDejaEmploye;
use App\Models\Matiere;
use App\Models\User;

/**
 * Modifier une matière LOCALE (#1653) : libellé, code, description, coefficient, crédits.
 *
 * Dans l'ordre :
 * 1. la porte du catalogue local, lue sur l'ACTEUR (403 `catalogue_managed_by_klassci` pour une
 *    école KLASSCI) ;
 * 2. la matière, cherchée dans SON école par un filtre EXPLICITE, le scope tenant ôté : sans
 *    école résolue le scope s'efface, et le filtre seul tient (404 sinon) ;
 * 3. une matière MIROIR de KLASSCI, même dans une école autonome, ne se modifie pas ici : son
 *    contenu est à KLASSCI, la synchronisation l'écraserait (403, même motif) ;
 * 4. l'écriture, le code tranché par la base (`CodeDeMatiere`).
 *
 * Seuls les champs ENVOYÉS changent : un PATCH partiel garde le reste.
 */
final class LocalMatiereEditor
{
    private const REFUS_MIROIR = 'Cette matière vient de KLASSCI : elle se modifie dans KLASSCI.';

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * @param  array{libelle?: string, code?: string|null, description?: string|null, coefficient?: int, credit?: int}  $modifications
     *
     * @throws BusinessException 403 école KLASSCI ou matière miroir, 404 hors de l'école.
     * @throws CodeDeMatiereDejaEmploye 422 sur `code`, déjà employé dans l'établissement.
     */
    public function modifier(User $acteur, int $matiereId, array $modifications): Matiere
    {
        $matiere = Matiere::query()->withoutGlobalScope('institution')
            ->whereKey($matiereId)
            ->first();
        $this->porte->etablissementDe($acteur);

        if (! $matiere instanceof Matiere) {
            throw new BusinessException('Matière introuvable dans cet établissement.', 404);
        }

        if ($matiere->klassci_id !== null) {
            throw new BusinessException(self::REFUS_MIROIR, 403, reason: PorteDuCatalogueLocal::REASON);
        }

        if (array_key_exists('code', $modifications)) {
            $modifications['code'] = CodeDeMatiere::normalise($modifications['code']);
        }

        return CodeDeMatiere::ecrire(function () use ($matiere, $modifications): Matiere {
            $matiere->fill($modifications)->save();

            return $matiere;
        });
    }
}

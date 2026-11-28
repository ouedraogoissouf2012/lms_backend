<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessException;
use App\Exceptions\CodeDeClasseDejaEmploye;
use App\Models\Classe;
use App\Models\User;

/**
 * Modifier une classe LOCALE (#1652) : libellé, code, description. La filière et le niveau
 * attendront #1648 ; la capacité, la période et le code d'inscription ont déjà leurs routes.
 *
 * Dans l'ordre, comme la matière (#1653) :
 * 1. la porte du catalogue local, lue sur l'ACTEUR (403 `catalogue_managed_by_klassci`) ;
 * 2. la classe, cherchée dans SON école par un filtre explicite, les scopes ôtés (404 sinon) ;
 * 3. une classe MIROIR de KLASSCI ne se modifie pas ici (403, même motif) ;
 * 4. l'écriture, le code tranché par la base (`CodeDeClasse`, 422 `class_code_already_used`).
 *
 * Seuls les champs ENVOYÉS changent.
 */
final class LocalClasseEditor
{
    private const REFUS_MIROIR = 'Cette classe vient de KLASSCI : elle se modifie dans KLASSCI.';

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly RattachementALaStructure $structure,
    ) {}

    /**
     * @param  array{libelle?: string, code?: string|null, description?: string|null, filiere_id?: int|null, niveau_id?: int|null}  $modifications
     *
     * @throws BusinessException 403 école KLASSCI ou classe miroir, 404 hors de l'école.
     * @throws CodeDeClasseDejaEmploye 422 sur `code`, déjà employé dans l'établissement.
     */
    public function modifier(User $acteur, int $classeId, array $modifications): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()
            ->whereKey($classeId)
            ->where('institution_id', $this->porte->etablissementDe($acteur))
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        if ($classe->klassci_id !== null) {
            throw new BusinessException(self::REFUS_MIROIR, 403, reason: PorteDuCatalogueLocal::REASON);
        }

        $structure = $this->structure->colonnes((int) $classe->institution_id, $modifications);
        unset($modifications['filiere_id'], $modifications['niveau_id']);

        if (array_key_exists('code', $modifications)) {
            $modifications['code'] = CodeDeClasse::normalise($modifications['code']);
        }

        return CodeDeClasse::ecrire(function () use ($classe, $modifications, $structure): Classe {
            $classe->fill($modifications)->forceFill($structure)->save();

            return $classe;
        });
    }
}

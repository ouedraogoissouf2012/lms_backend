<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ElementDeStructure;
use App\Exceptions\BusinessException;
use App\Exceptions\CodeDeStructureDejaEmploye;
use App\Models\Filiere;
use App\Models\NiveauEtude;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Les filières et les niveaux d'études d'une école qui tient son catalogue (#1648, PR A).
 *
 * - Le droit vient de la capacité (`PorteDuCatalogueLocal`) : une école KLASSCI reçoit 403
 *   `catalogue_managed_by_klassci` ; sa structure vient de KLASSCI.
 * - Un élément d'une autre école : 404, borné EXPLICITEMENT (le scope multi-tenant est fail-OPEN).
 * - Le code : espaces retirés, vide = sans code. Son unicité dans l'école est tranchée par la base
 *   (`code_local`), jamais par un SELECT préalable qu'une écriture concurrente franchirait.
 * - Supprimer : rien ne rattache encore une classe ou une matière à un élément local (PR B) ; la
 *   suppression est donc toujours possible ici. PR B y posera le refus d'un élément employé.
 */
final class StructureDeLEcole
{
    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * @param  array{libelle: string, code?: ?string, ordre?: ?int}  $donnees
     *
     * @throws BusinessException 403
     */
    public function creer(ElementDeStructure $element, array $donnees): Filiere|NiveauEtude
    {
        $ecole = $this->porte->etablissement();
        $classe = $element->modele();
        $ligne = new $classe;
        $ligne->forceFill($this->colonnes($element, $donnees) + ['institution_id' => $ecole]);

        return $this->ecrire($element, static function () use ($ligne): Filiere|NiveauEtude {
            $ligne->save();

            return $ligne;
        });
    }

    /**
     * @param  array{libelle?: string, code?: ?string, ordre?: ?int}  $modifications
     *
     * @throws BusinessException 403, 404
     */
    public function modifier(ElementDeStructure $element, int $id, array $modifications): Filiere|NiveauEtude
    {
        $ligne = $this->trouver($element, $this->porte->etablissement(), $id);
        $ligne->forceFill($this->colonnes($element, $modifications));

        return $this->ecrire($element, static function () use ($ligne): Filiere|NiveauEtude {
            $ligne->save();

            return $ligne;
        });
    }

    /** @throws BusinessException 403, 404 */
    public function supprimer(ElementDeStructure $element, int $id): void
    {
        $this->trouver($element, $this->porte->etablissement(), $id)->delete();
    }

    /**
     * Les éléments de l'école, triés : un niveau par son ordre (sans ordre, après), puis par libellé.
     *
     * @return list<Filiere|NiveauEtude>
     *
     * @throws BusinessException 403
     */
    public function lister(ElementDeStructure $element): array
    {
        $requete = $this->dansLEcole($element, $this->porte->etablissement());

        if ($element->porteUnOrdre()) {
            $requete->orderByRaw('ordre IS NULL')->orderBy('ordre');
        }

        return array_values($requete->orderBy('libelle')->orderBy('id')->get()->all());
    }

    /** @throws BusinessException 404 */
    private function trouver(ElementDeStructure $element, int $ecole, int $id): Filiere|NiveauEtude
    {
        $ligne = $this->dansLEcole($element, $ecole)->whereKey($id)->first();

        if (! $ligne instanceof Filiere && ! $ligne instanceof NiveauEtude) {
            throw new BusinessException($element->introuvable(), 404);
        }

        return $ligne;
    }

    /** @return Builder<Filiere>|Builder<NiveauEtude> */
    private function dansLEcole(ElementDeStructure $element, int $ecole): Builder
    {
        return $element->modele()::query()->withoutGlobalScopes();
    }

    /**
     * @param  array{libelle?: string, code?: ?string, ordre?: ?int}  $donnees
     * @return array<string, mixed>
     */
    private function colonnes(ElementDeStructure $element, array $donnees): array
    {
        $colonnes = [];

        if (array_key_exists('libelle', $donnees)) {
            $colonnes['libelle'] = trim($donnees['libelle']);
        }

        if (array_key_exists('code', $donnees)) {
            $code = trim((string) $donnees['code']);
            $colonnes['code'] = $code === '' ? null : $code;
        }

        if ($element->porteUnOrdre() && array_key_exists('ordre', $donnees)) {
            $colonnes['ordre'] = $donnees['ordre'];
        }

        return $colonnes;
    }

    /**
     * @param  Closure(): (Filiere|NiveauEtude)  $ecriture
     *
     * @throws CodeDeStructureDejaEmploye
     */
    private function ecrire(ElementDeStructure $element, Closure $ecriture): Filiere|NiveauEtude
    {
        try {
            return $ecriture();
        } catch (UniqueConstraintViolationException) {
            throw CodeDeStructureDejaEmploye::surLeCode($element->motifDuCodePris(), $element->texteDuCodePris());
        }
    }
}

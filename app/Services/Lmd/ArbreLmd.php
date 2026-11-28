<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Enums\NiveauLmd;
use App\Exceptions\BusinessException;
use App\Exceptions\CodeDeStructureDejaEmploye;
use App\Models\LmdDomaine;
use App\Models\LmdMention;
use App\Models\LmdParcours;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * L'arbre LMD d'une école qui tient son catalogue (#1654, PR A) : domaines → mentions → parcours.
 *
 * - Le droit vient de la capacité (`PorteDuCatalogueLocal`), en lecture comme en écriture : KLASSCI n'expose aucune
 *   structure LMD au LMS, une école KLASSCI reçoit donc 403 `catalogue_managed_by_klassci`.
 * - Tout est borné EXPLICITEMENT à l'école (le scope multi-tenant est fail-OPEN) : un élément d'une autre école,
 *   404 ; un parent d'une autre école, 422 sous son champ.
 * - Le code est facultatif ; son unicité par niveau et par école est tranchée par la base (`code_local`) : 422
 *   `lmd_code_already_used`. Un élément ne se supprime pas : il se désactive (`is_active`).
 */
final class ArbreLmd
{
    public const CODE_PRIS = 'lmd_code_already_used';

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * L'arbre, trié par libellé à chaque niveau. `$actifsSeulement` écarte un élément inactif ET sa descendance.
     *
     * @return list<array<string, mixed>>
     *
     * @throws BusinessException 403
     */
    public function arbre(bool $actifsSeulement): array
    {
        $ecole = $this->porte->etablissement();
        $parcours = self::parParent($this->niveau(NiveauLmd::Parcours, $ecole, $actifsSeulement), 'mention_id');
        $mentions = self::parParent($this->niveau(NiveauLmd::Mention, $ecole, $actifsSeulement), 'domaine_id');

        $arbre = [];
        foreach ($this->niveau(NiveauLmd::Domaine, $ecole, $actifsSeulement) as $domaine) {
            $sesMentions = [];
            foreach ($mentions[self::cle($domaine)] ?? [] as $mention) {
                $sesParcours = [];
                foreach ($parcours[self::cle($mention)] ?? [] as $unParcours) {
                    $sesParcours[] = self::vue($unParcours);
                }
                $sesMentions[] = self::vue($mention) + ['parcours' => $sesParcours];
            }
            $arbre[] = self::vue($domaine) + ['mentions' => $sesMentions];
        }

        return $arbre;
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{id: int, libelle: string, code: string|null, is_active: bool}
     *
     * @throws BusinessException 403
     * @throws ValidationException 422
     */
    public function creer(NiveauLmd $niveau, array $donnees): array
    {
        $ecole = $this->porte->etablissement();
        $classe = $niveau->modele();
        $element = new $classe;
        $element->forceFill(['institution_id' => $ecole]);

        return $this->ecrire($niveau, $ecole, $element, $donnees);
    }

    /**
     * @param  array<string, mixed>  $modifications
     * @return array{id: int, libelle: string, code: string|null, is_active: bool}
     *
     * @throws BusinessException 403, 404
     * @throws ValidationException 422
     */
    public function modifier(NiveauLmd $niveau, int $id, array $modifications): array
    {
        $ecole = $this->porte->etablissement();
        $element = $this->dansLEcole($niveau, $ecole)->whereKey($id)->first();

        if (! $element instanceof Model) {
            throw new BusinessException('Élément introuvable dans cet établissement.', 404);
        }

        return $this->ecrire($niveau, $ecole, $element, $modifications);
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{id: int, libelle: string, code: string|null, is_active: bool}
     *
     * @throws ValidationException
     */
    private function ecrire(NiveauLmd $niveau, int $ecole, Model $element, array $donnees): array
    {
        $colonne = $niveau->colonneDuParent();
        $parent = $niveau->parent();
        if ($colonne !== null && $parent !== null && array_key_exists($colonne, $donnees)
            && ! $this->dansLEcole($parent, $ecole)->whereKey($donnees[$colonne])->exists()) {
            throw ValidationException::withMessages([$colonne => 'Ce parent n\'existe pas dans cet établissement.']);
        }

        $colonnes = array_intersect_key($donnees, array_flip(array_filter(['libelle', 'code', 'is_active', $colonne])));
        if (array_key_exists('libelle', $colonnes)) {
            $colonnes['libelle'] = trim(self::texte($colonnes['libelle']));
        }
        if (array_key_exists('code', $colonnes)) {
            $code = trim(self::texte($colonnes['code']));
            $colonnes['code'] = $code === '' ? null : $code;
        }

        try {
            $element->forceFill($colonnes)->save();
        } catch (UniqueConstraintViolationException) {
            throw CodeDeStructureDejaEmploye::surLeCode(self::CODE_PRIS, 'Ce code est déjà employé à ce niveau dans l\'établissement.');
        }

        return self::vue($element);
    }

    /** @return list<Model> */
    private function niveau(NiveauLmd $niveau, int $ecole, bool $actifsSeulement): array
    {
        return array_values($this->dansLEcole($niveau, $ecole)
            ->when(false && $actifsSeulement, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('libelle')->orderBy('id')->get()->all());
    }

    /**
     * @param  list<Model>  $elements
     * @return array<int, list<Model>>
     */
    private static function parParent(array $elements, string $colonne): array
    {
        $groupes = [];
        foreach ($elements as $element) {
            $parent = $element->getAttribute($colonne);
            $groupes[is_numeric($parent) ? (int) $parent : 0][] = $element;
        }

        return $groupes;
    }

    /** @return Builder<LmdDomaine>|Builder<LmdMention>|Builder<LmdParcours> */
    private function dansLEcole(NiveauLmd $niveau, int $ecole): Builder
    {
        return $niveau->modele()::query()->withoutGlobalScopes()->where('institution_id', $ecole);
    }

    /** @return array{id: int, libelle: string, code: string|null, is_active: bool} */
    private static function vue(Model $element): array
    {
        $code = $element->getAttribute('code');

        return [
            'id' => self::cle($element),
            'libelle' => self::texte($element->getAttribute('libelle')),
            'code' => $code === null ? null : self::texte($code),
            'is_active' => (bool) $element->getAttribute('is_active'),
        ];
    }

    private static function cle(Model $element): int
    {
        $cle = $element->getKey();

        return is_int($cle) ? $cle : (is_numeric($cle) ? (int) $cle : 0);
    }

    private static function texte(mixed $valeur): string
    {
        return is_scalar($valeur) ? (string) $valeur : '';
    }
}

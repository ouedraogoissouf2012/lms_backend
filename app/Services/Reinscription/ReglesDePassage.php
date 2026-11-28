<?php

declare(strict_types=1);

namespace App\Services\Reinscription;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\RegleDePassage;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Validation\ValidationException;

/**
 * Les règles de passage d'une école autonome et la surcharge d'une classe (#1663). Porte du catalogue local (403
 * `catalogue_managed_by_klassci` en école KLASSCI). La moyenne de passage de l'école s'écrit dans
 * `settings.seuil_reussite` ({@see SeuilDePassage}), la seule source.
 *
 * La règle effective d'une classe : sa surcharge, champ par champ, sinon l'école, sinon {@see self::PAR_DEFAUT}.
 */
final class ReglesDePassage
{
    /** Les valeurs d'une école qui n'a rien réglé (hors moyenne de passage, {@see SeuilDePassage::PAR_DEFAUT}). */
    public const PAR_DEFAUT = ['moyenne_rattrapage' => 8.0, 'max_matieres_rattrapage' => 2, 'redoublement_autorise' => true, 'max_redoublements' => 1];

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /** @return array<string, float|int|bool> */
    public function deLEcole(User $acteur): array
    {
        return $this->ecole($this->porte->etablissementDe($acteur));
    }

    /**
     * @param  array<string, mixed>  $valeurs  validées
     * @return array<string, float|int|bool>
     */
    public function ecrireEcole(User $acteur, array $valeurs): array
    {
        $ecole = $this->porte->etablissementDe($acteur);
        $this->verifierRattrapage($valeurs, $this->ecole($ecole));
        if (is_numeric($valeurs['moyenne_passage'] ?? null)) {
            $institution = Institution::query()->findOrFail($ecole);
            $institution->settings = ['seuil_reussite' => (float) $valeurs['moyenne_passage']] + (is_array($institution->settings) ? $institution->settings : []);
            $institution->save();
        }
        $autres = array_diff_key($valeurs, ['moyenne_passage' => true]);
        if ($autres !== []) {
            $this->ligne($ecole, null)->fill($autres)->save();
        }

        return $this->ecole($ecole);
    }

    /** @return array{effective: array<string, float|int|bool>, surcharge: array<string, float|int|bool|null>|null} */
    public function deLaClasse(User $acteur, int $classeId): array
    {
        return $this->classe(ClasseLocale::de($this->porte->etablissementDe($acteur), $classeId));
    }

    /**
     * @param  array<string, mixed>  $valeurs  validées ; `null` remet un champ à l'héritage
     * @return array{effective: array<string, float|int|bool>, surcharge: array<string, float|int|bool|null>|null}
     */
    public function surchargerClasse(User $acteur, int $classeId, array $valeurs): array
    {
        $classe = ClasseLocale::de($this->porte->etablissementDe($acteur), $classeId);
        $this->verifierRattrapage($valeurs, $this->effective($classe));
        $this->ligne((int) $classe->institution_id, $classe->id)->fill($valeurs)->save();

        return $this->classe($classe);
    }

    /** @return array{effective: array<string, float|int|bool>, surcharge: array<string, float|int|bool|null>|null} */
    public function retirerSurcharge(User $acteur, int $classeId): array
    {
        $classe = ClasseLocale::de($this->porte->etablissementDe($acteur), $classeId);
        RegleDePassage::query()->withoutGlobalScopes()->where('institution_id', $classe->institution_id)->where('classe_id', $classe->id)->first()?->delete();

        return $this->classe($classe);
    }

    /** @return array<string, float|int|bool> la règle qui s'applique à la classe */
    public function effective(Classe $classe): array
    {
        $surcharge = $this->surcharge($classe);
        $ecole = $this->ecole((int) $classe->institution_id);
        $effective = ['moyenne_passage' => SeuilDePassage::pour($classe)];
        foreach (array_keys(self::PAR_DEFAUT) as $champ) {
            $effective[$champ] = $surcharge[$champ] ?? $ecole[$champ];
        }

        return $effective;
    }

    /** @return array<string, float|int|bool> */
    private function ecole(int $ecole): array
    {
        $ligne = RegleDePassage::query()->withoutGlobalScopes()->where('institution_id', $ecole)->whereNull('classe_id')->first();
        $regle = ['moyenne_passage' => SeuilDePassage::deLEcole($ecole)];
        foreach (self::PAR_DEFAUT as $champ => $defaut) {
            $regle[$champ] = self::typer($champ, $ligne?->getAttribute($champ)) ?? $defaut;
        }

        return $regle;
    }

    /** @return array{effective: array<string, float|int|bool>, surcharge: array<string, float|int|bool|null>|null} */
    private function classe(Classe $classe): array
    {
        return ['effective' => $this->effective($classe), 'surcharge' => $this->surcharge($classe)];
    }

    /** @return array<string, float|int|bool|null>|null les champs surchargés de la classe, `null` sans surcharge */
    private function surcharge(Classe $classe): ?array
    {
        $ligne = RegleDePassage::query()->withoutGlobalScopes()->where('institution_id', $classe->institution_id)->where('classe_id', $classe->id)->first();
        if (! $ligne instanceof RegleDePassage) {
            return null;
        }

        return array_combine(RegleDePassage::CHAMPS, array_map(static fn (string $c): mixed => self::typer($c, $ligne->getAttribute($c)), RegleDePassage::CHAMPS));
    }

    private function ligne(int $ecole, ?int $classe): RegleDePassage
    {
        return RegleDePassage::query()->withoutGlobalScopes()->where('institution_id', $ecole)->where('classe_id', $classe)->first()
            ?? new RegleDePassage(['institution_id' => $ecole, 'classe_id' => $classe]);
    }

    /**
     * La moyenne de rattrapage ne dépasse pas celle de passage, valeurs reçues ou en vigueur.
     *
     * @param  array<string, mixed>  $valeurs
     * @param  array<string, float|int|bool>  $enVigueur
     */
    private function verifierRattrapage(array $valeurs, array $enVigueur): void
    {
        $passage = $valeurs['moyenne_passage'] ?? $enVigueur['moyenne_passage'];
        $rattrapage = $valeurs['moyenne_rattrapage'] ?? $enVigueur['moyenne_rattrapage'];
        if (is_numeric($passage) && is_numeric($rattrapage) && (float) $rattrapage > (float) $passage) {
            throw ValidationException::withMessages(['moyenne_rattrapage' => 'La moyenne de rattrapage ne dépasse pas la moyenne de passage.']);
        }
    }

    private static function typer(string $champ, mixed $valeur): float|int|bool|null
    {
        return match (true) {
            $valeur === null => null,
            in_array($champ, ['moyenne_passage', 'moyenne_rattrapage'], true) => is_numeric($valeur) ? (float) $valeur : null,
            $champ === 'redoublement_autorise' => (bool) $valeur,
            default => is_numeric($valeur) ? (int) $valeur : null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Admission;

use App\Enums\EtatDeLaDemande;
use App\Exceptions\BusinessException;
use App\Models\DemandeInscription;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Le traitement des demandes d'inscription par la gestion d'une école autonome (#1659, PR 1).
 *
 * - Écrire et lire : `PorteDuCatalogueLocal` (403 `catalogue_managed_by_klassci` en école KLASSCI), et l'école de
 *   l'acteur, explicitement : une demande d'une autre école est introuvable (404).
 * - Circuit : `recue` → `en_examen` (examiner) ; `recue` ou `en_examen` → `rejetee` (rejeter, motif obligatoire). Hors
 *   circuit : 409 `etat_de_la_demande`. « Accepter et inscrire » est la PR 2.
 * - Liste sur la forme commune (#1647), compteurs par état MESURÉS.
 */
final class TraitementDesDemandes
{
    public const HORS_CIRCUIT = 'etat_de_la_demande';

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    /**
     * @param  array<string, mixed>  $filtres
     * @return array{page: LengthAwarePaginator<int, DemandeInscription>, compteurs: array<string, int>}
     */
    public function lister(User $acteur, array $filtres): array
    {
        $ecole = $this->porte->etablissementDe($acteur);
        $requete = $this->deLEcole($ecole);

        $q = $filtres['q'] ?? null;
        if (is_string($q) && $q !== '') {
            $motif = '%'.addcslashes($q, '%_\\').'%';
            $requete->where(static fn ($ou) => $ou->where('nom', 'like', $motif)->orWhere('prenoms', 'like', $motif)->orWhere('declarant_nom', 'like', $motif));
        }
        foreach (['type', 'etat'] as $colonne) {
            if (is_string($filtres[$colonne] ?? null)) {
                $requete->where($colonne, $filtres[$colonne]);
            }
        }
        if (filter_var($filtres['a_traiter'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $requete->whereIn('etat', [EtatDeLaDemande::Recue->value, EtatDeLaDemande::EnExamen->value]);
        }

        $sens = ($filtres['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $parPage = is_numeric($filtres['per_page'] ?? null) ? (int) $filtres['per_page'] : 25;

        return [
            'page' => $requete->orderBy('created_at', $sens)->orderBy('id')->paginate(min($parPage, 100)),
            'compteurs' => $this->compteurs($ecole),
        ];
    }

    /** @throws BusinessException 403, 404 */
    public function voir(User $acteur, int $id): DemandeInscription
    {
        return $this->demande($this->porte->etablissementDe($acteur), $id);
    }

    /** @throws BusinessException 403, 404, 409 */
    public function examiner(User $acteur, int $id): DemandeInscription
    {
        $demande = $this->demande($this->porte->etablissementDe($acteur), $id);
        $this->exiger($demande, [EtatDeLaDemande::Recue]);
        $demande->update(['etat' => EtatDeLaDemande::EnExamen]);

        return $demande;
    }

    /** @throws BusinessException 403, 404, 409 */
    public function rejeter(User $acteur, int $id, string $motif): DemandeInscription
    {
        $demande = $this->demande($this->porte->etablissementDe($acteur), $id);
        $this->exiger($demande, [EtatDeLaDemande::Recue, EtatDeLaDemande::EnExamen]);
        $demande->update([
            'etat' => EtatDeLaDemande::Rejetee, 'motif_rejet' => $motif,
            'traite_par' => $acteur->id, 'traite_le' => Carbon::now(),
        ]);

        return $demande;
    }

    /** @return Builder<DemandeInscription> */
    private function deLEcole(int $ecole): Builder
    {
        return DemandeInscription::query()->withoutGlobalScopes()->where('institution_id', $ecole);
    }

    /** @throws BusinessException 404 */
    private function demande(int $ecole, int $id): DemandeInscription
    {
        $demande = $this->deLEcole($ecole)->whereKey($id)->first();
        if (! $demande instanceof DemandeInscription) {
            throw new BusinessException('Demande introuvable dans cet établissement.', 404);
        }

        return $demande;
    }

    /**
     * @param  list<EtatDeLaDemande>  $admis
     *
     * @throws BusinessException 409 `etat_de_la_demande`
     */
    private function exiger(DemandeInscription $demande, array $admis): void
    {
        if (! in_array($demande->etat, $admis, true)) {
            throw new BusinessException('Cette demande n\'est plus dans un état qui permet ce geste.', 409, reason: self::HORS_CIRCUIT);
        }
    }

    /** @return array<string, int> le nombre de demandes de l'école par état, chaque état présent (0 compris : mesuré) */
    private function compteurs(int $ecole): array
    {
        $compteurs = array_fill_keys(array_map(static fn (EtatDeLaDemande $e): string => $e->value, EtatDeLaDemande::cases()), 0);
        foreach ($this->deLEcole($ecole)->selectRaw('etat, COUNT(*) as n')->groupBy('etat')->get() as $ligne) {
            $etat = $ligne->getRawOriginal('etat');
            $n = $ligne->getAttribute('n');
            if (is_string($etat) && array_key_exists($etat, $compteurs) && is_numeric($n)) {
                $compteurs[$etat] = (int) $n;
            }
        }

        return $compteurs;
    }
}

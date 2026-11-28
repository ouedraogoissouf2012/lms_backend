<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use App\Exceptions\BusinessException;
use App\Models\DossierEleve;
use App\Models\Tuteur;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Database\DatabaseManager;

/**
 * Les tuteurs d'un élève d'une école autonome (#1657).
 *
 * - Un tuteur est NEUF (ses coordonnées), ou EXISTANT dans l'école (`tuteur_id`) : il se partage alors entre frères et
 *   sœurs. Hors de l'école, il est introuvable (404).
 * - Il faut un dossier à l'élève d'abord : 409 `dossier_absent`.
 * - Retirer le lien d'un tuteur qui n'a plus aucun élève le retire aussi : donnée devenue inutile (loi 2013-450).
 * - École KLASSCI : 403 `catalogue_managed_by_klassci` (`PorteDuCatalogueLocal`).
 */
final class TuteursDeLEleve
{
    public const DOSSIER_ABSENT = 'dossier_absent';

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly DossierDeLEleve $dossiers,
        private readonly PresentationDuDossier $presentation,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $saisie  validée : `relation`, et `tuteur_id` ou les coordonnées
     * @return array<string, mixed>
     *
     * @throws BusinessException 403, 404, 409 `dossier_absent`
     */
    public function ajouter(User $acteur, int $eleveId, array $saisie): array
    {
        $ecole = $this->porte->etablissement();
        $dossier = $this->dossierDe($acteur, $eleveId);
        // Validée (`AjouterTuteurRequest`) : une des `Tuteur::RELATIONS`.
        $relation = is_string($saisie['relation'] ?? null) ? $saisie['relation'] : 'autre';

        $tuteur = $this->db->transaction(function () use ($ecole, $dossier, $saisie, $relation): Tuteur {
            $existant = $saisie['tuteur_id'] ?? null;
            $tuteur = is_int($existant)
                ? $this->tuteurDeLEcole($ecole, $existant)
                : Tuteur::query()->create(['institution_id' => $ecole] + array_intersect_key($saisie, array_flip(['nom', 'telephone', 'courriel', 'profession', 'adresse'])));
            $dossier->tuteurs()->syncWithoutDetaching([$tuteur->id => ['relation' => $relation]]);
            $dossier->tuteurs()->updateExistingPivot($tuteur->id, ['relation' => $relation]);

            return $tuteur;
        });

        return $this->presentation->tuteur($tuteur, $relation);
    }

    /**
     * @param  array<string, mixed>  $coordonnees
     * @return array<string, mixed>
     *
     * @throws BusinessException 403, 404
     */
    public function modifier(int $tuteurId, array $coordonnees): array
    {
        $tuteur = $this->tuteurDeLEcole($this->porte->etablissement(), $tuteurId);
        $tuteur->update($coordonnees);

        return $this->presentation->tuteur($tuteur->refresh());
    }

    /** @throws BusinessException 403, 404 */
    public function retirer(User $acteur, int $eleveId, int $tuteurId): void
    {
        $ecole = $this->porte->etablissement();
        $dossier = $this->dossierDe($acteur, $eleveId);
        $tuteur = $this->tuteurDeLEcole($ecole, $tuteurId);

        $this->db->transaction(function () use ($dossier, $tuteur): void {
            if ($dossier->tuteurs()->detach($tuteur->id) === 0) {
                throw new BusinessException('Ce tuteur n\'est pas lié à cet élève.', 404);
            }
            if (! $tuteur->dossiers()->exists()) {
                $tuteur->delete();
            }
        });
    }

    /** @throws BusinessException 404, 409 `dossier_absent` */
    private function dossierDe(User $acteur, int $eleveId): DossierEleve
    {
        $eleve = $this->dossiers->eleve($acteur, $eleveId);
        $dossier = DossierEleve::query()->withoutGlobalScopes()->where('user_id', $eleve->id)->first();

        if (! $dossier instanceof DossierEleve) {
            throw new BusinessException('Créez d\'abord le dossier de l\'élève.', 409, reason: self::DOSSIER_ABSENT);
        }

        return $dossier;
    }

    /** @throws BusinessException 404 */
    private function tuteurDeLEcole(int $ecole, int $tuteurId): Tuteur
    {
        $tuteur = Tuteur::query()->withoutGlobalScopes()->whereKey($tuteurId)->where('institution_id', $ecole)->first();

        if (! $tuteur instanceof Tuteur) {
            throw new BusinessException('Tuteur introuvable dans cet établissement.', 404);
        }

        return $tuteur;
    }
}

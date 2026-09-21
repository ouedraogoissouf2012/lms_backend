<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Services\Catalogue\CatalogueAuthority;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;

/**
 * Émettre et retirer le code d'inscription d'une classe (#846, ADR-803-03).
 *
 * ## Régénérer ne désinscrit personne
 *
 * L'ADR l'exige : « révocable et régénérable **sans toucher aux inscriptions
 * déjà faites** ». Les inscriptions vivent dans `classe_etudiant` ; ce service
 * n'y touche pas. Un code retiré ferme la porte aux suivants, il n'expulse pas
 * ceux qui sont entrés.
 *
 * ## Le droit vient de la capacité, jamais du mode
 *
 * Un établissement dont le catalogue vient de KLASSCI ne distribue pas de code :
 * ses classes et ses inscriptions ont une autre source, et deux sources
 * écrivant la même réalité est la cause racine de #673. L'appelant demande un
 * DROIT et ignore qu'un mode existe (épique #697, article 1).
 *
 * ## La collision est tranchée par la base, pas par un SELECT
 *
 * Un contrôle préalable ouvrirait une fenêtre entre la vérification et
 * l'écriture, que deux requêtes concurrentes franchiraient toutes les deux. On
 * écrit, et on retente si l'unique refuse — au plus {@see self::ESSAIS} fois.
 * Sur 31⁶ combinaisons par établissement, atteindre cette borne signalerait une
 * source d'aléa en panne, pas de la malchance : on le dit alors bruyamment.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class ClasseEnrolmentCodeService
{
    private const ESSAIS = 5;

    public function __construct(
        private readonly CatalogueAuthority $autorite,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * Émet un nouveau code et le rend. Efface une révocation précédente : la
     * classe rouvre.
     *
     * @throws BusinessException
     */
    public function generer(int $classeId): string
    {
        $classe = $this->saClasse($classeId);

        for ($essai = 1; $essai <= self::ESSAIS; $essai++) {
            $classe->code_inscription = ClasseEnrolmentCode::tirer();
            $classe->code_inscription_revoque_le = null;

            try {
                $classe->save();

                return (string) $classe->code_inscription;
            } catch (QueryException $collision) {
                if ($essai === self::ESSAIS) {
                    throw new BusinessException(
                        'Impossible de tirer un code disponible. Réessayez.',
                        503,
                        $collision
                    );
                }
            }
        }

        // Inatteignable : la boucle rend ou lève. Présent pour le typage.
        throw new BusinessException('Impossible de tirer un code disponible.', 503);
    }

    /**
     * Retire le code : il n'ouvre plus. La valeur DEMEURE — voir la migration,
     * qui explique pourquoi l'effacer perdrait l'unicité d'un code tout juste
     * dicté.
     *
     * @throws BusinessException
     */
    public function revoquer(int $classeId): void
    {
        $classe = $this->saClasse($classeId);

        if ($classe->code_inscription === null) {
            throw new BusinessException('Cette classe n\'a pas de code d\'inscription.', 409);
        }

        $classe->code_inscription_revoque_le = now();
        $classe->save();
    }

    /**
     * La classe de CET établissement, et le droit d'en composer le catalogue.
     *
     * `withoutGlobalScopes` puis un `where` explicite : le scope multi-tenant
     * est fail-OPEN, s'y fier serait dépendre d'une garde qui se tait quand
     * aucun tenant n'est résolu.
     *
     * @throws BusinessException
     */
    private function saClasse(int $classeId): Classe
    {
        if (! $this->autorite->allowsLocalCatalogue()) {
            throw new BusinessException(
                'Les inscriptions de cet établissement viennent de KLASSCI.',
                403
            );
        }

        $institution = $this->tenants->id();

        if ($institution === null) {
            throw new BusinessException('Aucun établissement résolu.', 409);
        }

        $classe = Classe::query()->withoutGlobalScopes()
            ->where('id', $classeId)
            ->where('institution_id', $institution)
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        return $classe;
    }
}

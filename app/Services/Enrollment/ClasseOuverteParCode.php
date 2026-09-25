<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * La classe qu'un code d'inscription ouvre, dans UN établissement (#846, #885).
 *
 * Partagée par les deux variantes de la troisième porte d'ADR-803-03 — la porte
 * anonyme, qui crée le compte, et la porte authentifiée, qui inscrit un compte
 * existant. Une résolution par porte, ce seraient deux réponses à « ce code
 * ouvre-t-il quelque chose ? », et la première divergence ferait d'une porte un
 * oracle que l'autre refuse d'être.
 *
 * ## Bornée à l'établissement
 *
 * `code_inscription` n'est unique que par institution : deux écoles peuvent
 * tirer le même code, et un apprenant atterrirait dans la mauvaise. L'appelant
 * fournit l'établissement — l'en-tête pour la porte anonyme, le compte pour la
 * porte authentifiée — et `withoutGlobalScopes` écarte le scope multi-tenant,
 * fail-OPEN, qui se tairait si aucun tenant n'était résolu.
 *
 * ## Un code n'ouvre rien là où la liste des apprenants vient d'ailleurs
 *
 * Émettre un code exige le catalogue local (`ClasseEnrolmentCodeService`), mais
 * basculer un établissement vers KLASSCI ne retire pas les codes déjà dictés.
 * Sans ce contrôle, ils continueraient d'écrire `classe_etudiant` dans des
 * classes dont la liste appartient désormais à KLASSCI : deux sources pour une
 * même réalité, la cause racine de #673. Le droit consulté est celui qu'exige
 * déjà l'import (`ImportConfirmController`) — écrire la liste des apprenants —
 * et il est demandé POUR CET établissement, jamais au tenant ambiant.
 *
 * ## Inconnu, retiré ou sans droit : le MÊME refus
 *
 * Les distinguer dirait qu'un code a existé pour cette valeur — donc qu'une
 * classe l'attend. Même raisonnement qu'ADR-803-02 pour les trois états du
 * jeton d'activation.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class ClasseOuverteParCode
{
    private const REFUS = 'Ce code ne correspond à aucune inscription ouverte.';

    public function __construct(private readonly RosterAuthorityFactory $autorites) {}

    /**
     * @throws BusinessException 404 si le code n'ouvre aucune inscription
     */
    public function resoudre(string $code, Institution $institution): Classe
    {
        if (! $this->autorites->forInstitution($institution)->allowsLocalEnrolment()) {
            throw new BusinessException(self::REFUS, 404);
        }

        $classe = Classe::query()->withoutGlobalScopes()
            ->where('institution_id', $institution->id)
            ->where('code_inscription', ClasseEnrolmentCode::normaliser($code))
            ->whereNull('code_inscription_revoque_le')
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException(self::REFUS, 404);
        }

        return $classe;
    }
}

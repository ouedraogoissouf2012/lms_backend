<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\TrainingSessionStatus;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use App\Services\Roster\RosterAuthorityFactory;
use App\Support\Periode\FenetreDInscription;

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
 * ## Inconnu, retiré, sans droit, Période close, en brouillon ou hors fenêtre : le MÊME refus
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
            // #1652 : archiver ferme le code ; ce filtre couvre aussi une classe archivée dont
            // le code serait resté ouvert. Même refus qu'un code inconnu.
            ->whereNull('archivee_le')
            ->first();

        // La Période de la classe décide (#897, #1208) : la règle est LUE ici, au
        // résolveur des deux portes, et couvre ainsi les Périodes déjà closes dont
        // le code est resté posé. Même refus qu'un code inconnu.
        if (! $classe instanceof Classe || $this->laPeriodeRefuse($classe)) {
            throw new BusinessException(self::REFUS, 404);
        }

        return $classe;
    }

    /**
     * La Période de la classe, lue explicitement dans SON établissement (le scope
     * global est fail-open sans tenant). Elle refuse si elle est supprimée, si elle
     * n'est pas publiée ({@see TrainingSessionStatus::ouvreLesCodes()} : ni
     * brouillon, ni close), ou si aujourd'hui est hors de sa fenêtre d'inscription.
     *
     * Pour la porte, une borne absente ne ferme rien : les dates sont facultatives,
     * et une Période publiée sans dates reçoit ({@see FenetreDInscription}). Une
     * classe sans Période n'est pas concernée.
     */
    private function laPeriodeRefuse(Classe $classe): bool
    {
        if ($classe->training_session_id === null) {
            return false;
        }

        $periode = TrainingSession::query()->withoutGlobalScope('institution')->withTrashed()
            ->where('institution_id', $classe->institution_id)
            ->whereKey($classe->training_session_id)
            ->first();

        if (! $periode instanceof TrainingSession) {
            return false;
        }

        $fenetre = new FenetreDInscription($periode->enrollment_opens_at, $periode->enrollment_closes_at);

        return $periode->trashed() || ! $periode->status->ouvreLesCodes()
            || $fenetre->pasEncoreOuverte() || $fenetre->dejaFermee();
    }
}

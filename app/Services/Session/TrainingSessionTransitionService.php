<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Enums\TrainingSessionStatus;
use App\Exceptions\BusinessException;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Faire avancer une Période dans son cycle de vie (#845, ADR-845-01).
 *
 * ## Cinq états sur six étaient inatteignables
 *
 * `TrainingSessionCrudService:55` écrit `Brouillon`, et c'était le **seul**
 * écrivain de `status` dans tout `app/`. Aucune route, aucune commande, aucun
 * job ne le faisait avancer : une période naissait brouillon et le restait.
 * L'écran livré par `frontend_lms#405` affichait donc « Brouillon » pour
 * toutes, à jamais.
 *
 * ## Des transitions NOMMÉES, et non un `PATCH status`
 *
 * Un `PATCH` libre laisserait passer `brouillon → archivee`, ou le retour
 * d'`annulee` à `publiee`. Le graphe cesserait d'exister, et chaque appelant
 * inventerait ses règles — ce que le dépôt a déjà payé sur l'inscription, où
 * trois écrivains produisaient trois politiques.
 *
 * Nommer rend aussi le refus lisible : « une période clôturée ne se publie
 * pas » dit quelque chose ; « transition invalide » ne dit rien.
 *
 * ## Annuler est irréversible
 *
 * ADR-711-04 refuse « reportée » comme état, au motif qu'il créerait « un état
 * dont personne ne saurait sortir ». Le raisonnement vaut en sens inverse : une
 * annulation défaisable ne vaudrait rien pour ceux qu'elle informe. Une période
 * annulée par erreur se recrée, elle ne se ressuscite pas.
 *
 * ## Ce que ce service ne fait PAS
 *
 * Il ne touche **aucune inscription**. Annuler une période ne désinscrit
 * personne — comme régénérer un code ne désinscrit personne (#846). Les
 * inscriptions vivent dans `classe_etudiant`, hors de portée d'ici.
 *
 * `purgee` n'est atteignable par aucune de ces opérations : ce n'est pas une
 * décision humaine mais l'issue d'une politique de rétention, qui reste à
 * écrire.
 *
 * @see docs/adr/2026-09-22-845-01-transitions-de-la-periode.md
 */
final class TrainingSessionTransitionService
{
    /**
     * Publier engage des inscriptions : la période doit avoir un calendrier.
     *
     * `phase` est dérivée des dates, toutes nullables. Sans `starts_on`, une
     * période publiée n'aurait aucune phase calculable — ni à venir, ni en
     * cours, ni terminée. On exige cette date SEULE : on publie souvent avant
     * d'avoir arrêté la fin ou les bornes d'inscription.
     *
     * @throws BusinessException
     */
    public function publier(User $acteur, int $periodeId): TrainingSession
    {
        $periode = $this->sienne($acteur, $periodeId);

        if ($periode->starts_on === null) {
            throw new BusinessException(
                'Une période se publie avec une date de début : sans elle, elle n\'a aucune phase.',
                422
            );
        }

        return $this->appliquer($periode, [TrainingSessionStatus::Brouillon], TrainingSessionStatus::Publiee, 'publiée');
    }

    /**
     * @throws BusinessException
     */
    public function annuler(User $acteur, int $periodeId): TrainingSession
    {
        return $this->appliquer(
            $this->sienne($acteur, $periodeId),
            [TrainingSessionStatus::Brouillon, TrainingSessionStatus::Publiee],
            TrainingSessionStatus::Annulee,
            'annulée'
        );
    }

    /**
     * @throws BusinessException
     */
    public function cloturer(User $acteur, int $periodeId): TrainingSession
    {
        return $this->appliquer(
            $this->sienne($acteur, $periodeId),
            [TrainingSessionStatus::Publiee],
            TrainingSessionStatus::Cloturee,
            'clôturée'
        );
    }

    /**
     * @throws BusinessException
     */
    public function archiver(User $acteur, int $periodeId): TrainingSession
    {
        return $this->appliquer(
            $this->sienne($acteur, $periodeId),
            [TrainingSessionStatus::Cloturee, TrainingSessionStatus::Annulee],
            TrainingSessionStatus::Archivee,
            'archivée'
        );
    }

    /**
     * Le refus porte le statut COURANT : « transition invalide » n'apprend rien
     * à qui vient de cliquer.
     *
     * @param  list<TrainingSessionStatus>  $depuis
     *
     * @throws BusinessException
     */
    private function appliquer(
        TrainingSession $periode,
        array $depuis,
        TrainingSessionStatus $vers,
        string $participe,
    ): TrainingSession {
        if (! in_array($periode->status, $depuis, true)) {
            throw new BusinessException(
                sprintf(
                    'Une période « %s » ne peut pas être %s.',
                    $periode->status->value,
                    $participe
                ),
                409
            );
        }

        $periode->status = $vers;
        $periode->save();

        return $periode;
    }

    /**
     * La période de l'établissement de l'acteur, ou rien.
     *
     * `withoutGlobalScopes` puis un `where` explicite : le scope multi-tenant
     * est fail-OPEN, s'y fier serait dépendre d'une garde qui se tait quand
     * aucun tenant n'est résolu.
     *
     * Le message ne distingue pas « n'existe pas » de « appartient à une autre
     * école » : les séparer dirait à un établissement quels identifiants
     * existent chez ses voisins.
     *
     * @throws BusinessException
     */
    private function sienne(User $acteur, int $periodeId): TrainingSession
    {
        $institution = $acteur->institution_id;

        if (! is_int($institution)) {
            throw new BusinessException('L\'acteur n\'est rattaché à aucun établissement.', 409);
        }

        $periode = TrainingSession::query()->withoutGlobalScopes()
            ->where('id', $periodeId)
            ->where('institution_id', $institution)
            ->first();

        if (! $periode instanceof TrainingSession) {
            throw new BusinessException('Période introuvable dans cet établissement.', 404);
        }

        return $periode;
    }
}

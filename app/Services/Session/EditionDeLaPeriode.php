<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Enums\TrainingSessionStatus;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Modifier une Période, supprimer un brouillon, désigner la période EN COURS (#1651).
 *
 * - Modifier : en brouillon ou publiée. Une période close, annulée ou archivée est de l'histoire.
 *   Une période publiée garde une date de début, comme l'exige sa publication. Ses nouvelles
 *   dates contiennent ses sous-périodes (#1806).
 * - Supprimer : un brouillon SANS classe rattachée, en douceur. Une classe rattachée perdrait sa
 *   Période (`nullOnDelete`) et, avec elle, la règle qui ouvre ses codes.
 * - En cours : UNE période PUBLIÉE par école. La marque se déplace en une transaction, sous le
 *   verrou de l'école : deux désignations simultanées ne se croisent pas. La base garde l'unicité
 *   et l'état publiée ; ce service les rend lisibles (409 nommés).
 *
 * Les deux volets : tout passe par {@see PeriodesDeLEcole}, donc par la porte du catalogue local.
 */
final class EditionDeLaPeriode
{
    private const MODIFIABLES = [TrainingSessionStatus::Brouillon, TrainingSessionStatus::Publiee];

    public function __construct(
        private readonly PeriodesDeLEcole $periodes,
        private readonly ConnectionInterface $db,
        private readonly SousPeriodesDeLaPeriode $sousPeriodes,
    ) {}

    /**
     * @param  array{libelle: string, enrollment_opens_at: ?string, enrollment_closes_at: ?string, starts_on: ?string, ends_on: ?string, certificate_available_at: ?string}  $modifications
     *
     * @throws BusinessException
     */
    public function modifier(User $acteur, int $periodeId, array $modifications): TrainingSession
    {
        $periode = $this->periodes->sienne($acteur, $periodeId);

        if (! in_array($periode->status, self::MODIFIABLES, true)) {
            throw new BusinessException(
                sprintf('Une période « %s » ne se modifie plus.', $periode->status->value),
                409,
                reason: 'training_session_not_editable',
            );
        }

        if ($periode->status === TrainingSessionStatus::Publiee && $modifications['starts_on'] === null) {
            throw new BusinessException(
                'Une période publiée garde sa date de début : sans elle, elle n\'a aucune phase.',
                422,
                reason: 'training_session_published_needs_start',
            );
        }

        // #1806 : les nouvelles dates contiennent les sous-périodes, sinon 409 nommé.
        $this->sousPeriodes->exigerQueLaPeriodeLesContienne($periode, $modifications['starts_on'], $modifications['ends_on']);
        $periode->fill($modifications)->save();

        return $periode->load('program');
    }

    /** @throws BusinessException */
    public function supprimer(User $acteur, int $periodeId): void
    {
        $periode = $this->periodes->sienne($acteur, $periodeId);
        $rattachee = Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $periode->institution_id)
            ->where('training_session_id', $periode->getKey())
            ->exists();

        if ($periode->status !== TrainingSessionStatus::Brouillon || $rattachee) {
            throw new BusinessException(
                'Seul un brouillon sans classe rattachée se supprime ; une période publiée s\'annule.',
                409,
                reason: 'training_session_not_deletable',
            );
        }

        $periode->delete();
    }

    /** @throws BusinessException */
    public function designerEnCours(User $acteur, int $periodeId): TrainingSession
    {
        $periode = $this->periodes->sienne($acteur, $periodeId);

        if ($periode->status !== TrainingSessionStatus::Publiee) {
            throw new BusinessException(
                sprintf('Seule une période publiée peut être en cours ; celle-ci est « %s ».', $periode->status->value),
                409,
                reason: 'training_session_not_publiee',
            );
        }

        $this->db->transaction(function () use ($periode): void {
            Institution::query()->whereKey($periode->institution_id)->lockForUpdate()->first();
            TrainingSession::query()->withoutGlobalScope('institution')
                ->where('institution_id', $periode->institution_id)
                ->where('en_cours', true)
                ->whereKeyNot($periode->getKey())
                ->update(['en_cours' => null]);
            $periode->en_cours = true;
            $periode->save();
        });

        return $periode->load('program');
    }
}

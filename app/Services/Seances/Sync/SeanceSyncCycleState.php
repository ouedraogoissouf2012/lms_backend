<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Services\Seances\Sync\Cursor\SeanceSyncPosition;
use Carbon\CarbonImmutable;

/**
 * État mutable d'UNE passe de synchronisation (#582).
 *
 * Même rôle que {@see SeanceSyncStats} — un accumulateur de passe circulant
 * entre collaborateurs — mais pour la progression plutôt que pour les
 * compteurs : position atteinte, tenant en cours, tenants souillés.
 *
 * ## Pourquoi `currentInstitutionId` est amorcé depuis le curseur
 *
 * Un cycle s'étale sur plusieurs passes. Une passe qui reprend après le dernier
 * enseignant du tenant A doit encore savoir que A est « en cours », sans quoi :
 *  - le franchissement vers B ne serait pas détecté (A jamais archivé) ;
 *  - une passe qui trouve le flux vide ne clôturerait aucun tenant.
 *
 * @see .claude/specs/582-seance-sync-cursor/design.md §1
 */
final class SeanceSyncCycleState
{
    /** Tenant en cours de parcours — sa clôture déclenche l'archivage. */
    public ?int $currentInstitutionId;

    public ?int $lastUserId;

    public bool $cycleCompleted = false;

    /** @var array<int, int> */
    private array $taintedInstitutionIds;

    /** @param array<int, int> $taintedInstitutionIds */
    private function __construct(
        public readonly CarbonImmutable $cycleStartedAt,
        ?int $currentInstitutionId,
        ?int $lastUserId,
        array $taintedInstitutionIds,
    ) {
        $this->currentInstitutionId = $currentInstitutionId;
        $this->lastUserId = $lastUserId;
        $this->taintedInstitutionIds = $taintedInstitutionIds;
    }

    public static function resume(SeanceSyncPosition $position): self
    {
        return new self(
            $position->cycleStartedAt,
            $position->lastInstitutionId,
            $position->lastUserId,
            $position->taintedInstitutionIds,
        );
    }

    /**
     * Avance la position APRÈS le traitement d'un enseignant — y compris quand
     * celui-ci a échoué. Sans cela, un enseignant dont l'appel KLASSCI échoue
     * durablement rebloquerait le parcours sur lui-même et réintroduirait la
     * famine par la porte de derrière.
     */
    public function advance(int $userId): void
    {
        $this->lastUserId = $userId;
    }

    /**
     * Marque le tenant comme non archivable pour ce cycle : une donnée manque
     * (erreur KLASSCI), donc « non confirmé » ne veut plus dire « disparu ».
     */
    public function taint(int $institutionId): void
    {
        if (! in_array($institutionId, $this->taintedInstitutionIds, true)) {
            $this->taintedInstitutionIds[] = $institutionId;
        }
    }

    public function isTainted(int $institutionId): bool
    {
        return in_array($institutionId, $this->taintedInstitutionIds, true);
    }

    /** La souillure n'a plus d'objet une fois le tenant clos : on la libère. */
    public function clearTaint(int $institutionId): void
    {
        $this->taintedInstitutionIds = array_values(
            array_filter($this->taintedInstitutionIds, static fn (int $id): bool => $id !== $institutionId)
        );
    }

    public function toPosition(): SeanceSyncPosition
    {
        return new SeanceSyncPosition(
            $this->currentInstitutionId,
            $this->lastUserId,
            $this->cycleStartedAt,
            $this->taintedInstitutionIds,
        );
    }
}

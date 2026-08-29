<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync\Cursor;

use Carbon\CarbonImmutable;

/**
 * Position de reprise de la sync des séances (#582) — valeur immuable.
 *
 * La position est un COUPLE `(institution_id, user_id)` et non un simple
 * `user_id` : c'est ce qui rend les enseignants d'un même tenant contigus dans
 * le parcours, donc la complétude d'un tenant détectable en O(1) (franchissement
 * de frontière) sans connaître toute la population.
 *
 * `cycleStartedAt` est la référence du balayage d'archivage : toute séance non
 * confirmée depuis cet instant est réputée disparue de KLASSCI.
 *
 * @see .claude/specs/582-seance-sync-cursor/design.md §1
 */
final class SeanceSyncPosition
{
    /**
     * @param  array<int, int>  $taintedInstitutionIds  Tenants ayant subi une erreur
     *                                                  dans le cycle : archivage renoncé.
     */
    public function __construct(
        public readonly ?int $lastInstitutionId,
        public readonly ?int $lastUserId,
        public readonly CarbonImmutable $cycleStartedAt,
        public readonly array $taintedInstitutionIds = [],
    ) {}

    /** Position neuve : le parcours repart du tout premier enseignant. */
    public static function startOfCycle(CarbonImmutable $cycleStartedAt): self
    {
        return new self(null, null, $cycleStartedAt);
    }

    /** Vrai tant qu'aucun enseignant n'a encore été traité dans ce cycle. */
    public function isAtStart(): bool
    {
        return $this->lastInstitutionId === null || $this->lastUserId === null;
    }
}

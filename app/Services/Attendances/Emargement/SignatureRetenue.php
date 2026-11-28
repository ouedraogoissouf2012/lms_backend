<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Enums\AttendanceMark;

/**
 * Ce que retient une signature par code (#1680) : la séance, la marque, et si l'élève avait déjà signé (rejeu).
 * Rendu par la transaction de {@see SignatureParCode} : `ConnectionInterface::transaction()` rend `mixed`, l'objet est
 * vérifié par `instanceof` à la sortie au lieu d'un type imposé.
 */
final readonly class SignatureRetenue
{
    public function __construct(
        public int $seanceId,
        public AttendanceMark $marque,
        public bool $dejaSigne,
    ) {}

    /** @return array{seance_id: int, mark: string, deja_signe: bool} */
    public function enTableau(): array
    {
        return ['seance_id' => $this->seanceId, 'mark' => $this->marque->value, 'deja_signe' => $this->dejaSigne];
    }
}

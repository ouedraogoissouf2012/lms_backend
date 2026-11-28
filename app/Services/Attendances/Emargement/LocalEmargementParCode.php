<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Models\User;

/**
 * École créée depuis le LMS : l'émargement par code se tient dans le LMS (#1680). Les codes d'une séance côté auteur
 * ({@see CodesDeSeance}) et la signature côté élève ({@see SignatureParCode}) sont deux responsabilités, deux classes.
 */
final class LocalEmargementParCode implements EmargementParCode
{
    public function __construct(
        private readonly CodesDeSeance $codes,
        private readonly SignatureParCode $signature,
    ) {}

    public function generer(int $seanceId, User $auteur, int $dureeMinutes, ?int $retardApresMinutes): array
    {
        return $this->codes->generer($seanceId, $auteur, $dureeMinutes, $retardApresMinutes);
    }

    public function lister(int $seanceId, User $auteur): array
    {
        return $this->codes->lister($seanceId, $auteur);
    }

    public function revoquer(int $codeId, User $auteur): void
    {
        $this->codes->revoquer($codeId, $auteur);
    }

    public function signer(User $eleve, string $code): array
    {
        return $this->signature->signer($eleve, $code);
    }
}

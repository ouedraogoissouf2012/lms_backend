<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Exceptions\BusinessException;
use App\Exceptions\EssaisDeCodeEpuises;
use App\Models\User;

/**
 * L'émargement par code d'une séance en ligne (#1680), nommé par ce qu'il fait, jamais par le mode : une école créée
 * depuis le LMS le tient dans le LMS ({@see LocalEmargementParCode}), une école KLASSCI dans KLASSCI
 * ({@see KlassciEmargementParCode}). La source est choisie au point de liaison et reçue par la MÉTHODE du contrôleur.
 */
interface EmargementParCode
{
    /**
     * Un code pour la séance, rendu en clair UNE seule fois.
     *
     * @return array{id: int, code: string, expire_le: string}
     *
     * @throws BusinessException
     */
    public function generer(int $seanceId, User $auteur, int $dureeMinutes, ?int $retardApresMinutes): array;

    /**
     * Les codes de la séance et leurs statistiques, jamais le clair.
     *
     * @return list<array{id: int, expire_le: string, revoque_le: ?string, valable: bool, signatures: int, attendus: int, taux: int}>
     *
     * @throws BusinessException
     */
    public function lister(int $seanceId, User $auteur): array;

    /** @throws BusinessException */
    public function revoquer(int $codeId, User $auteur): void;

    /**
     * L'élève signe par le code.
     *
     * @return array{seance_id: int, mark: string, deja_signe: bool}
     *
     * @throws BusinessException
     * @throws EssaisDeCodeEpuises
     */
    public function signer(User $eleve, string $code): array;
}

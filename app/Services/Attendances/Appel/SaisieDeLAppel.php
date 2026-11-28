<?php

declare(strict_types=1);

namespace App\Services\Attendances\Appel;

use App\Enums\AttendanceMark;
use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;

/**
 * L'appel d'une séance (#1649), nommé par ce qu'il fait, jamais par le mode : une école créée depuis le LMS le saisit
 * dans le LMS ({@see LocalSaisieDeLAppel}), une école KLASSCI dans KLASSCI ({@see KlassciSaisieDeLAppel}). La source
 * est choisie au point de liaison (`CatalogueServiceProvider::SOURCES`), et reçue par la MÉTHODE du contrôleur.
 */
interface SaisieDeLAppel
{
    /**
     * La classe de la séance, chaque élève actif avec sa marque.
     *
     * @return array{seance_id: int, eleves: list<array{user_id: int, nom: string, mark: ?string, origin: ?string, delegated_at: ?string, presence_visio: ?string}>}
     *
     * @throws BusinessException
     */
    public function lire(int $seanceId, User $acteur): array;

    /**
     * @param  list<array{user_id: int, mark: AttendanceMark}>  $marques
     *
     * @throws BusinessException
     */
    public function saisir(int $seanceId, User $acteur, array $marques): void;

    /**
     * Le marqueur `can_take_attendance` d'une carte (front#719) : ce que {@see saisir()} accepterait de CE lecteur
     * sur cette séance, sans écrire. L'écran masque le geste par cette capacité, jamais par le mode.
     */
    public function peutSaisir(Seance $seance, User $lecteur): bool;
}

<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * La justification d'une absence (#1681), nommée par ce qu'elle fait, jamais par le mode : une école créée depuis le
 * LMS la tient dans le LMS ({@see LocalJustificationDAbsence}), une école KLASSCI dans KLASSCI
 * ({@see KlassciJustificationDAbsence}), où vivent ses absences. La source est reçue par la MÉTHODE du contrôleur.
 *
 * Formes rendues : {@see JustificatifsDeLEcole::presenter()} pour un justificatif, {@see AbsencesDeLEleve::absences()}
 * pour une absence.
 */
interface JustificationDAbsence
{
    /**
     * @return list<array{seance_id: int, titre: ?string, date_seance: ?string, marque: ?string, justificatif_etat: ?string}>
     *
     * @throws BusinessException
     */
    public function mesAbsences(User $eleve): array;

    /**
     * @param  ?list<int>  $seanceIds
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function deposer(User $eleve, string $motif, ?array $seanceIds, ?string $du, ?string $au, ?UploadedFile $piece): array;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws BusinessException
     */
    public function mesJustificatifs(User $eleve): array;

    /**
     * @return array{justificatifs: list<array<string, mixed>>, compteurs: array<string, int>, page: int, derniere_page: int, total: int}
     *
     * @throws BusinessException
     */
    public function lister(User $gestion, FiltresDeJustificatifs $filtres): array;

    /**
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function valider(int $justificatifId, User $gestion): array;

    /**
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function rejeter(int $justificatifId, User $gestion, string $motif): array;

    /**
     * La pièce, pour l'élève déposant ou la gestion de son école.
     *
     * @throws BusinessException
     */
    public function piece(int $justificatifId, User $lecteur): PieceJustificative;
}

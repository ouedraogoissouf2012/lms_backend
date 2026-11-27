<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Enums\EtatJustificatif;
use App\Exceptions\BusinessException;
use App\Models\JustificatifAbsence;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * L'élève dépose un justificatif (#1681) : motif, séances nommées ou plage de dates, pièce FACULTATIVE (ARRÊT 1 Q4).
 *
 * - Les séances visées sont résolues ICI, par la règle unique ({@see AbsencesDeLEleve::visees()}), et liées au
 *   justificatif : la décision ne lit qu'elles.
 * - La pièce a son propre stockage : le disque privé `local`, sous `justificatifs/{école}/{uuid}.{ext}`. Son type est
 *   celui que lit le serveur, pas celui que déclare le client. Si l'écriture en base échoue, la pièce déjà rangée est
 *   retirée : aucun fichier orphelin.
 */
final class DepotDeJustificatif
{
    public const DISQUE = 'local';

    public function __construct(
        private readonly AbsencesDeLEleve $absences,
        private readonly FilesystemFactory $disques,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  ?list<int>  $seanceIds
     *
     * @throws BusinessException
     */
    public function deposer(User $eleve, string $motif, ?array $seanceIds, ?string $du, ?string $au, ?UploadedFile $piece): JustificatifAbsence
    {
        $visees = $this->absences->visees($eleve, $seanceIds, $du, $au);
        $rangee = $piece === null ? null : $this->ranger($piece, (int) $eleve->institution_id);

        try {
            $cree = $this->db->transaction(function () use ($eleve, $motif, $du, $au, $rangee, $visees): JustificatifAbsence {
                $justificatif = JustificatifAbsence::query()->create([
                    'institution_id' => $eleve->institution_id,
                    'eleve_id' => $eleve->id,
                    'motif' => $motif,
                    'du' => $du,
                    'au' => $au,
                    'etat' => EtatJustificatif::EnAttente,
                ] + ($rangee ?? []));
                $justificatif->seances()->attach($visees);

                return $justificatif;
            });
            if (! $cree instanceof JustificatifAbsence) {
                throw new LogicException('Le dépôt n\'a rien retenu.');
            }

            return $cree;
        } catch (Throwable $echec) {
            if ($rangee !== null) {
                $this->disques->disk(self::DISQUE)->delete($rangee['piece_chemin']);
            }

            throw $echec;
        }
    }

    /** @return array{piece_chemin: string, piece_nom: string, piece_mime: string, piece_taille: int} */
    private function ranger(UploadedFile $piece, int $ecole): array
    {
        $extension = strtolower($piece->guessExtension() ?? $piece->getClientOriginalExtension());
        $chemin = $this->disques->disk(self::DISQUE)->putFileAs("justificatifs/{$ecole}", $piece, Str::uuid()->toString().'.'.$extension);
        if (! is_string($chemin)) {
            throw new BusinessException('La pièce n\'a pas pu être enregistrée. Réessayez.', 503);
        }

        return [
            'piece_chemin' => $chemin,
            'piece_nom' => $this->nomSur($piece->getClientOriginalName(), $extension),
            'piece_mime' => (string) $piece->getMimeType(),
            'piece_taille' => (int) $piece->getSize(),
        ];
    }

    /** Le nom proposé au téléchargement : lettres, chiffres, tirets ; jamais un chemin ni un caractère de contrôle. */
    private function nomSur(string $nom, string $extension): string
    {
        $base = Str::slug(pathinfo($nom, PATHINFO_FILENAME));

        return ($base === '' ? 'justificatif' : Str::limit($base, 80, '')).'.'.$extension;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * École créée depuis le LMS : la justification des absences se tient dans le LMS (#1681). Quatre responsabilités,
 * quatre classes : ce qui est justifiable ({@see AbsencesDeLEleve}), le dépôt ({@see DepotDeJustificatif}), la
 * décision ({@see DecisionSurJustificatif}), les lectures ({@see JustificatifsDeLEcole}).
 */
final class LocalJustificationDAbsence implements JustificationDAbsence
{
    public function __construct(
        private readonly AbsencesDeLEleve $absences,
        private readonly DepotDeJustificatif $depot,
        private readonly DecisionSurJustificatif $decision,
        private readonly JustificatifsDeLEcole $justificatifs,
    ) {}

    public function mesAbsences(User $eleve): array
    {
        return $this->absences->absences($eleve);
    }

    public function deposer(User $eleve, string $motif, ?array $seanceIds, ?string $du, ?string $au, ?UploadedFile $piece): array
    {
        $justificatif = $this->depot->deposer($eleve, $motif, $seanceIds, $du, $au, $piece);
        $justificatif->load('seances');

        return $this->justificatifs->presenter($justificatif);
    }

    public function mesJustificatifs(User $eleve): array
    {
        return $this->justificatifs->deLEleve($eleve);
    }

    public function lister(User $gestion, FiltresDeJustificatifs $filtres): array
    {
        return $this->justificatifs->pourLaGestion($gestion, $filtres);
    }

    public function valider(int $justificatifId, User $gestion): array
    {
        return $this->justificatifs->presenter($this->decision->valider($justificatifId, $gestion)->load('seances'));
    }

    public function rejeter(int $justificatifId, User $gestion, string $motif): array
    {
        return $this->justificatifs->presenter($this->decision->rejeter($justificatifId, $gestion, $motif)->load('seances'));
    }

    /**
     * La pièce : l'élève déposant ou la gestion de l'école ; à tout autre lecteur, elle n'existe pas (404).
     *
     * @throws BusinessException 404
     */
    public function piece(int $justificatifId, User $lecteur): PieceJustificative
    {
        $j = $this->justificatifs->parId($justificatifId, $lecteur);
        $autorise = $lecteur->isStudent() || $this->justificatifs->estLaGestion($lecteur);
        if (! $autorise || $j->piece_chemin === null) {
            throw new BusinessException('Pièce introuvable', 404);
        }

        return new PieceJustificative($j->piece_chemin, (string) $j->piece_nom, (string) $j->piece_mime);
    }
}

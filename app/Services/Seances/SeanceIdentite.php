<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;

/**
 * Les identifiants qu'une séance porte dans la forme du calendrier, et le seul
 * point où les deux types d'école diffèrent ({@see SeanceCalendarProjection}).
 *
 * - École créée depuis KLASSCI : les ids KLASSCI du miroir, ceux que ses écrans
 *   et ses filtres emploient.
 * - École créée depuis le LMS : les ids LOCAUX, ceux de `/lms/seances/{id}`,
 *   de `/proxy/classes` et du compte auteur. Une séance locale n'a qu'un nom
 *   de matière, et son auteur tient lieu d'enseignant (D119).
 */
final readonly class SeanceIdentite
{
    private const SANS_ENSEIGNANT = 'Non assigné';

    private function __construct(
        public int $id,
        public ?int $classeId,
        public ?int $matiereId,
        public ?int $enseignantId,
        public string $enseignantNom,
    ) {}

    public static function klassci(Seance $seance): self
    {
        return new self(
            $seance->klassci_seance_id ?? $seance->id,
            $seance->klassci_classe_id,
            $seance->klassci_matiere_id,
            $seance->klassci_enseignant_id,
            $seance->enseignant_nom ?? self::SANS_ENSEIGNANT,
        );
    }

    public static function locale(Seance $seance, ?string $auteur): self
    {
        return new self(
            $seance->id,
            $seance->classe_id,
            null,
            $seance->created_by,
            $auteur ?? self::SANS_ENSEIGNANT,
        );
    }
}

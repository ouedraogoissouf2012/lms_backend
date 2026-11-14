<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;

/**
 * Les participants d'une séance locale : UNE règle, lue par le détail
 * ({@see LocalSeanceDetailReader}) et par `GET …/participants`. Chaque route garde
 * sa forme ; aucune ne recompte à sa façon.
 *
 * - L'enseignant est l'AUTEUR de la séance, cherché dans l'établissement de la
 *   séance. Sans auteur valide (aucun, ou d'un autre établissement) : `null`, jamais
 *   un enseignant fictif.
 * - Les élèves : les élèves actifs de la classe de la séance
 *   ({@see ClasseAudienceSource::studentsOf()}).
 * - Le total : l'enseignant s'il existe, plus les élèves.
 */
final class ParticipantsDeSeanceLocale
{
    public function __construct(private readonly ClasseAudienceSource $audience) {}

    /**
     * @return array{auteur: User|null, eleves: list<User>, total: int}
     */
    public function de(Seance $seance): array
    {
        $auteur = is_numeric($seance->created_by)
            ? User::query()->where('institution_id', $seance->institution_id)->find((int) $seance->created_by)
            : null;
        $auteur = $auteur instanceof User ? $auteur : null;
        $eleves = array_values($this->audience->studentsOf($seance)->all());

        return [
            'auteur' => $auteur,
            'eleves' => $eleves,
            'total' => ($auteur === null ? 0 : 1) + count($eleves),
        ];
    }
}

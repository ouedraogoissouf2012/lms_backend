<?php

declare(strict_types=1);

namespace App\Services\EmploiDuTemps;

use App\Models\EmploiDuTemps;
use App\Models\LigneEmploiDuTemps;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Copier une grille vers une autre classe ou une autre plage (#1669). La copie est un nouvel emploi du temps de la
 * classe cible, daté dans SA période ; ses lignes passent par la même règle que toute grille ({@see GrilleDeLEmploi}) :
 * chaque matière doit être au programme de la classe cible (422 sinon, et rien n'est créé), et l'enseignant de chaque
 * ligne redevient celui du programme de la cible.
 */
final class CopieDEmploiDuTemps
{
    public function __construct(
        private readonly EmploisDuTemps $emplois,
        private readonly GrilleDeLEmploi $grille,
        private readonly ConnectionInterface $db,
    ) {}

    public function copier(int $id, User $acteur, int $classeId, string $du, string $au, ?string $titre): EmploiDuTemps
    {
        $source = $this->emplois->designer($id, $acteur);
        $copie = $this->db->transaction(function () use ($source, $acteur, $classeId, $du, $au, $titre): EmploiDuTemps {
            $copie = $this->emplois->creer($acteur, [
                'classe_id' => $classeId,
                'titre' => $titre ?? $source->titre,
                'du' => $du,
                'au' => $au,
                'en_vigueur' => true,
            ]);
            $this->grille->remplacer($copie, array_values($source->lignes->map(static fn (LigneEmploiDuTemps $l): array => [
                'jour' => $l->jour,
                'debut' => $l->debut,
                'fin' => $l->fin,
                'matiere_id' => $l->matiere_id,
                'enseignant_id' => null,
                'type_seance' => $l->type_seance,
                'libelle' => $l->libelle,
            ])->all()));

            return $copie;
        });
        if (! $copie instanceof EmploiDuTemps) {
            throw new LogicException('La copie n\'a rien retenu.');
        }

        return $copie->load('lignes');
    }
}

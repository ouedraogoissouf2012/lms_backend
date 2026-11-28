<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Le suivi des présences d'une école (#1683), nommé par ce qu'il fait, jamais par le mode : une école créée depuis le
 * LMS le tient dans le LMS ({@see LocalSuiviDesPresences}) ; une école KLASSCI tient ses présences dans KLASSCI
 * ({@see KlassciSuiviDesPresences}).
 */
interface SuiviDesPresences
{
    /**
     * Les présences (séance passée × élève actif de sa classe), filtrées et paginées.
     *
     * @return array{presences: list<array{seance_id: int, date_seance: ?string, classe_id: int, matiere_id: ?int, eleve_id: int, eleve_nom: ?string, marque: string}>, page: int, derniere_page: int, total: int}
     *
     * @throws BusinessException
     */
    public function lister(User $gestion, FiltresDePresences $filtres): array;

    /**
     * Par élève et par classe : présences, retards, absences justifiées, absences, taux ; les élèves sous le seuil.
     *
     * @return array{du: string, au: string, seuil: int, eleves: list<array<string, mixed>>, classes: list<array<string, mixed>>, sous_le_seuil: list<int>}
     *
     * @throws BusinessException
     */
    public function synthese(User $gestion, FiltresDePresences $filtres, int $seuil): array;

    /**
     * L'école tient-elle ses présences dans le LMS ? La capacité que la connexion dit au front
     * (`meta.peut_suivre_les_presences`, front#689) : la source répond, le mode n'est jamais lu.
     */
    public function tenuDansLeLms(): bool;
}

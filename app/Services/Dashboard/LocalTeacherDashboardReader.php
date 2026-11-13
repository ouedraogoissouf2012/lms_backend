<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Seance;
use App\Models\User;
use App\Services\Classe\LocalTeacherClassesReader;
use App\Services\Matiere\LocalTeacherMatieresReader;
use App\Services\Seances\LocalSeancesListReader;
use App\Services\Seances\SeanceCalendarProjection;
use Illuminate\Support\Carbon;

/**
 * Le tableau de bord de l'enseignant dans l'école en mode autonome (R4) : la
 * forme de `me/teacher-dashboard`, composée des sources locales existantes, sans
 * en recopier aucune. Aucun jeton, aucun appel KLASSCI, aucune écriture.
 *
 * - `matieres` et `classes` : celles de l'enseignant (lots 1 et 2), les classes
 *   avec leur effectif actif ;
 * - `prochaines_seances` : ses séances des {@see self::JOURS} prochains jours (lot 3d) ;
 * - `statistiques.heures` : ses séances, comptées sur `created_by` comme dans le
 *   lot 3d (D119). Une séance locale n'a pas d'heure de fin : elle ne compte pour
 *   effectuée qu'après sa fin ESTIMÉE, et la réponse le dit (`fin_estimee`).
 *
 * `evaluations` est ABSENT, et non vide : la fenêtre d'une évaluation locale
 * n'est pas lue ici. Une liste sans fenêtre affirmerait « 0 évaluation en cours ».
 *
 * `meta.source` dit au client que les identifiants sont LOCAUX ; la réponse
 * KLASSCI ne porte pas cette clé.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalTeacherDashboardReader implements TeacherDashboardReader
{
    /** Les séances à venir du tableau de bord, sur 30 jours. */
    public const JOURS = 30;

    /**
     * Dette déclarée : le même littéral que la constante commune des sources
     * locales (`SourceLocale::LMS_LOCAL`, lot B-source), qui n'est pas encore
     * fusionnée. À unifier à sa fusion.
     */
    private const SOURCE = 'lms_local';

    public function __construct(
        private readonly LocalTeacherMatieresReader $matieres,
        private readonly LocalTeacherClassesReader $classes,
        private readonly LocalSeancesListReader $seances,
    ) {}

    public function dashboardFor(User $teacher): array
    {
        return [
            'success' => true,
            'data' => [
                'matieres' => $this->matieres->getMatieresForUser($teacher),
                'classes' => $this->classes->listFor($teacher),
                'prochaines_seances' => $this->seances->getUpcomingForUser($teacher, self::JOURS, $teacher->id, null)['data'],
                'statistiques' => ['heures' => $this->heures($teacher)],
            ],
            'meta' => ['source' => self::SOURCE],
        ];
    }

    /**
     * @return array{total_seances: int, seances_effectuees: int, fin_estimee: true}
     */
    private function heures(User $teacher): array
    {
        $institution = $teacher->institution_id;
        if (! is_int($institution)) {
            return ['total_seances' => 0, 'seances_effectuees' => 0, 'fin_estimee' => true];
        }

        // Le même périmètre que la lecture des séances du lot 3d : l'établissement
        // explicite, les séances actives, celles dont il est l'auteur.
        $siennes = Seance::query()
            ->where('institution_id', $institution)
            ->where('is_active', true)
            ->where('created_by', $teacher->id)
            ->whereNotNull('date_seance');

        $finEstimeePassee = Carbon::now()->subHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);

        return [
            'total_seances' => (clone $siennes)->count(),
            'seances_effectuees' => (clone $siennes)->where('date_seance', '<=', $finEstimeePassee)->count(),
            'fin_estimee' => true,
        ];
    }
}

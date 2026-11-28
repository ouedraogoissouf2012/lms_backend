<?php

declare(strict_types=1);

namespace App\Services\Attendances\Heures;

use App\Models\Seance;
use App\Services\Seances\SeanceCalendarProjection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * LA définition des heures d'un enseignant (#1682, ARRÊT 1 bis validé le 27/11) : l'historique, le rapport, le
 * réalisé (lot 4) et la paie (lot 7) la lisent ici, et nulle part ailleurs.
 *
 * Une séance compte sa durée PRÉVUE (fin prévue − début ; sans fin prévue, ou une fin qui ne suit pas le début,
 * {@see SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES}) si sa visio a été démarrée par SON enseignant et s'est
 * terminée, à la main ou automatiquement. Sinon, 0. L'enseignant d'une séance d'une école créée depuis le LMS est son
 * auteur (`created_by`) : seul l'enseignant crée une séance (décision du 17/11).
 *
 * Une séance démarrée avant le relevé du démarreur (`visio_demarree_par_id`, #1682) n'a pas d'auteur connu : elle
 * compte 0, sans reconstitution ({@see self::AUTEUR_INCONNU}).
 */
final class HeuresEmargees
{
    public const COMPTEE = 'comptee';

    public const NON_DEMARREE = 'visio_non_demarree';

    public const AUTEUR_INCONNU = 'auteur_inconnu';

    public const PAR_UN_AUTRE = 'demarree_par_un_autre';

    public const NON_TERMINEE = 'visio_non_terminee';

    public function __construct(private readonly ConnectionInterface $db) {}

    public static function statut(Seance $seance): string
    {
        return match (true) {
            $seance->visio_started_at === null => self::NON_DEMARREE,
            $seance->visio_demarree_par_id === null => self::AUTEUR_INCONNU,
            $seance->created_by === null || (int) $seance->visio_demarree_par_id !== (int) $seance->created_by => self::PAR_UN_AUTRE,
            $seance->visio_ended_at === null => self::NON_TERMINEE,
            default => self::COMPTEE,
        };
    }

    /** Les minutes que la séance compte : sa durée prévue si elle est comptée, sinon 0. */
    public static function minutes(Seance $seance): int
    {
        return self::statut($seance) === self::COMPTEE ? self::dureePrevue($seance) : 0;
    }

    /**
     * La date depuis laquelle les heures se comptent dans l'école : celle de la première visio dont le démarreur est
     * connu ; `null` tant qu'aucune ne l'est.
     */
    public function depuis(int $ecole): ?string
    {
        $premiere = $this->db->table('seances')
            ->where('institution_id', $ecole)
            ->whereNotNull('visio_demarree_par_id')
            ->whereNotNull('visio_started_at')
            ->min('visio_started_at');

        return is_string($premiere) ? Carbon::parse($premiere)->toDateString() : null;
    }

    private static function dureePrevue(Seance $seance): int
    {
        $supposee = SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES * 60;
        if ($seance->date_seance === null || $seance->fin_seance === null) {
            return $supposee;
        }
        $debut = Carbon::parse($seance->date_seance);
        $fin = Carbon::parse($seance->fin_seance);

        return $fin->greaterThan($debut) ? (int) $debut->diffInMinutes($fin) : $supposee;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Institution;
use App\Services\TenantManager;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\ConnectionInterface;
use stdClass;
use Throwable;

/**
 * Rattrapage de l'agrégat `lesson_progress.time_spent_minutes` (#944).
 *
 * Avant #944, l'agrégat était un cumul envoyé par le client (toujours 0, le
 * front additionnant une colonne morte), et le vrai temps vivait dans
 * `chapter_progress`. Ce rattrapage recalcule chaque couple (utilisateur,
 * leçon) dont la valeur stockée diffère de la somme des chapitres.
 *
 * - **Idempotent** : il compare avant d'écrire, et l'écriture passe par
 *   {@see ChapterTimeRecorder::recalculateLesson()}, qui ne fait que recalculer.
 *   Une seconde passe ne trouve donc plus rien à corriger.
 * - **Par établissement** : le tenant est posé pour chaque institution, si
 *   bien que les scopes globaux filtrent comme en requête HTTP. Les lignes à
 *   `institution_id` NULL ne sont pas traitées (#801).
 * - **Par pages, à la clé** (#1148) : les couples sont lus par pages bornées,
 *   ordonnées par `(user_id, lesson_id)`, chaque page reprenant APRÈS la
 *   dernière clé lue. Un couple corrigé à 0 sort de l'ensemble parcouru : un
 *   décalage (OFFSET) sauterait alors des couples, la clé n'en saute aucun.
 * - **Une erreur n'arrête pas les autres** : elle est journalisée avec son
 *   couple et comptée (`erreurs`), et le parcours continue.
 * - **Une simulation fidèle** : les couples que l'écriture refuserait (une
 *   ligne d'un autre établissement, une leçon supprimée ou absente) sont
 *   détectés AVANT l'écriture, par la même vérification dans les deux modes,
 *   comptés en `refus` et jamais écrits.
 */
final class LessonTimeRecomputation
{
    /** Couples lus par page : la mémoire reste bornée quel que soit l'établissement. */
    public const PAGE = 500;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ChapterTimeRecorder $recorder,
        private readonly TenantManager $tenants,
        private readonly CaughtExceptionLog $log,
    ) {}

    /**
     * @return array{couples: int, a_corriger: int, refus: int, corriges: int, erreurs: int}
     */
    public function run(bool $apply, ?int $institutionId = null, int $taille = self::PAGE): array
    {
        $bilan = ['couples' => 0, 'a_corriger' => 0, 'refus' => 0, 'corriges' => 0, 'erreurs' => 0];

        $institutions = Institution::query()
            ->when($institutionId !== null, fn ($q) => $q->whereKey($institutionId))
            ->orderBy('id')
            ->get();

        try {
            foreach ($institutions as $institution) {
                $this->tenants->set($institution);
                $this->recomputeInstitution((int) $institution->id, $apply, max(1, $taille), $bilan);
            }
        } finally {
            $this->tenants->reset();
        }

        return $bilan;
    }

    /**
     * @param  array{couples: int, a_corriger: int, refus: int, corriges: int, erreurs: int}  $bilan
     */
    private function recomputeInstitution(int $institutionId, bool $apply, int $taille, array &$bilan): void
    {
        $apres = null;

        do {
            $page = $this->couplesOf($institutionId, $apres, $taille);
            foreach ($page as $couple) {
                $this->traiter($institutionId, $couple, $apply, $bilan);
            }

            $dernier = end($page);
            $apres = $dernier === false ? null : [$this->entier($dernier->user_id ?? null), $this->entier($dernier->lesson_id ?? null)];
        } while (count($page) === $taille);
    }

    /**
     * @param  array{couples: int, a_corriger: int, refus: int, corriges: int, erreurs: int}  $bilan
     */
    private function traiter(int $institutionId, stdClass $couple, bool $apply, array &$bilan): void
    {
        $bilan['couples']++;
        if (intdiv($this->entier($couple->secondes ?? null), 60) === $this->entier($couple->stocke ?? null)) {
            return;
        }

        $bilan['a_corriger']++;
        if ($this->refuse($institutionId, $couple)) {
            $bilan['refus']++;

            return;
        }

        if (! $apply) {
            return;
        }

        $cle = ['institution_id' => $institutionId, 'user_id' => $this->entier($couple->user_id ?? null), 'lesson_id' => $this->entier($couple->lesson_id ?? null)];
        try {
            $this->recorder->recalculateLesson($cle['user_id'], $cle['lesson_id']);
            $bilan['corriges']++;
        } catch (Throwable $e) {
            $bilan['erreurs']++;
            $this->log->error('Rattrapage du temps : couple en erreur, les suivants continuent', $e, $cle);
        }
    }

    /**
     * Ce que l'écriture refuserait, vu à l'identique en simulation : une ligne
     * parente d'un autre établissement (invisible sous le tenant posé, l'écriture
     * lèverait), une leçon supprimée ou absente (l'écriture y créerait une ligne).
     */
    private function refuse(int $institutionId, stdClass $couple): bool
    {
        $ligne = $couple->ligne_institution ?? null;

        return ($ligne !== null && $this->entier($ligne) !== $institutionId)
            || ($couple->lecon ?? null) === null
            || ($couple->lecon_supprimee ?? null) !== null;
    }

    /** Valeur SQL (entier, chaîne numérique selon le pilote, ou NULL) vers un entier. */
    private function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /**
     * Une page des couples (utilisateur, leçon) de l'établissement qui portent du
     * temps de chapitre OU un agrégat non nul, APRÈS la clé `$apres`, avec la
     * somme des secondes, la valeur stockée, l'établissement de la ligne parente
     * et l'état de la leçon. Même périmètre de chapitres que l'agrégat : chapitres
     * actifs.
     *
     * @param  array{0: int, 1: int}|null  $apres
     * @return array<int, stdClass>
     */
    private function couplesOf(int $institutionId, ?array $apres, int $taille): array
    {
        $temps = $this->db->table('chapter_progress as cp')
            ->join('chapters as c', 'c.id', '=', 'cp.chapter_id')
            ->whereNull('c.deleted_at')
            ->where('c.institution_id', $institutionId)
            ->groupBy('cp.user_id', 'c.lesson_id')
            ->select('cp.user_id', 'c.lesson_id')
            ->selectRaw('SUM(cp.time_spent_seconds) as secondes');

        $agregats = $this->db->table('lesson_progress')
            ->where('institution_id', $institutionId)
            ->where('time_spent_minutes', '<>', 0)
            ->select('user_id', 'lesson_id')
            ->selectRaw('0 as secondes');

        return $this->db->table($temps->unionAll($agregats), 'u')
            ->leftJoin('lesson_progress as lp', function ($join): void {
                $join->on('lp.user_id', '=', 'u.user_id')->on('lp.lesson_id', '=', 'u.lesson_id');
            })
            ->leftJoin('lessons as l', 'l.id', '=', 'u.lesson_id')
            ->when($apres !== null, fn ($q) => $q->where(fn ($cle) => $cle->where('u.user_id', '>', $apres[0] ?? 0)
                ->orWhere(fn ($meme) => $meme->where('u.user_id', '=', $apres[0] ?? 0)->where('u.lesson_id', '>', $apres[1] ?? 0))))
            ->groupBy('u.user_id', 'u.lesson_id', 'lp.time_spent_minutes', 'lp.institution_id', 'l.id', 'l.deleted_at')
            ->orderBy('u.user_id')
            ->orderBy('u.lesson_id')
            ->limit($taille)
            ->select('u.user_id', 'u.lesson_id', 'lp.time_spent_minutes as stocke', 'lp.institution_id as ligne_institution')
            ->addSelect('l.id as lecon', 'l.deleted_at as lecon_supprimee')
            ->selectRaw('SUM(u.secondes) as secondes')
            ->get()->all();
    }
}

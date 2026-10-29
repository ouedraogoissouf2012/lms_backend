<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Institution;
use App\Services\TenantManager;
use Illuminate\Database\ConnectionInterface;

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
 */
final class LessonTimeRecomputation
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ChapterTimeRecorder $recorder,
        private readonly TenantManager $tenants,
    ) {}

    /**
     * @return array{couples: int, a_corriger: int, corriges: int}
     */
    public function run(bool $apply, ?int $institutionId = null): array
    {
        $bilan = ['couples' => 0, 'a_corriger' => 0, 'corriges' => 0];

        $institutions = Institution::query()
            ->when($institutionId !== null, fn ($q) => $q->whereKey($institutionId))
            ->orderBy('id')
            ->get();

        try {
            foreach ($institutions as $institution) {
                $this->tenants->set($institution);
                $this->recomputeInstitution((int) $institution->id, $apply, $bilan);
            }
        } finally {
            $this->tenants->reset();
        }

        return $bilan;
    }

    /**
     * @param  array{couples: int, a_corriger: int, corriges: int}  $bilan
     */
    private function recomputeInstitution(int $institutionId, bool $apply, array &$bilan): void
    {
        foreach ($this->couplesOf($institutionId) as $couple) {
            $bilan['couples']++;
            $attendu = intdiv($this->entier($couple->secondes ?? null), 60);
            $stocke = $this->entier($couple->stocke ?? null);

            if ($attendu === $stocke) {
                continue;
            }

            $bilan['a_corriger']++;
            if ($apply) {
                $this->recorder->recalculateLesson($this->entier($couple->user_id ?? null), $this->entier($couple->lesson_id ?? null));
                $bilan['corriges']++;
            }
        }
    }

    /** Valeur SQL (entier, chaîne numérique selon le pilote, ou NULL) vers un entier. */
    private function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /**
     * Couples (utilisateur, leçon) de l'établissement qui portent du temps de
     * chapitre OU un agrégat non nul, avec la somme des secondes et la valeur
     * stockée. Même périmètre de chapitres que l'agrégat : chapitres actifs.
     *
     * @return array<int, \stdClass>
     */
    private function couplesOf(int $institutionId): array
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

        $couples = $this->db->table($temps->unionAll($agregats), 'u')
            ->leftJoin('lesson_progress as lp', function ($join): void {
                $join->on('lp.user_id', '=', 'u.user_id')->on('lp.lesson_id', '=', 'u.lesson_id');
            })
            ->groupBy('u.user_id', 'u.lesson_id', 'lp.time_spent_minutes')
            ->orderBy('u.user_id')
            ->orderBy('u.lesson_id')
            ->select('u.user_id', 'u.lesson_id', 'lp.time_spent_minutes as stocke')
            ->selectRaw('SUM(u.secondes) as secondes');

        // Liste figée AVANT toute écriture : une pagination par décalage sauterait
        // des couples, puisqu'un agrégat corrigé à 0 sort de l'ensemble parcouru.
        return $couples->get()->all();
    }
}

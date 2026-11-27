<?php

declare(strict_types=1);

namespace App\Services\Attendances\Appel;

use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Exceptions\BusinessException;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Seances\LocalSeanceDesignee;
use App\Services\Seances\ParticipantsDeSeanceLocale;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * L'appel d'une séance d'une école autonome (#1649, ARRÊT 1 validé le 27/11).
 *
 * - Qui : la règle de la modification, {@see VisioActorAuthorization::canManage()} (l'auteur, ou la gestion).
 * - Qui est appelé : les élèves ACTIFS de la classe de la séance ({@see ParticipantsDeSeanceLocale}), une seule
 *   règle déjà lue par le détail. Un autre élève : 422, et rien n'est écrit (une transaction pour le lot).
 * - Quand : dès le DÉBUT de la séance ; l'historique reste corrigeable.
 * - Quoi : la marque (#717), son origine `delegated`, qui l'a posée et quand. La ligne visio d'un élève connecté
 *   garde son statut ; une ligne créée par l'appel a un statut visio NUL, jamais « connecté » (`PresenceRetenue`).
 * - Chaque CORRECTION d'une marque est auditée : qui, quand, avant, après.
 */
final class LocalSaisieDeLAppel implements SaisieDeLAppel
{
    public const NON_COMMENCEE = 'seance_not_started';

    public const HORS_SEANCE = 'student_not_in_seance';

    public function __construct(
        private readonly LocalSeanceDesignee $seances,
        private readonly VisioActorAuthorization $acteurs,
        private readonly ParticipantsDeSeanceLocale $participants,
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    public function lire(int $seanceId, User $acteur): array
    {
        $seance = $this->seanceGeree($seanceId, $acteur);
        $eleves = $this->eleves($seance);
        $lignes = $this->lignes($seance)->whereIn('user_id', array_map(static fn (User $e): int => $e->id, $eleves))
            ->get()->keyBy('user_id');

        return [
            'seance_id' => (int) $seance->id,
            'eleves' => array_map(static function (User $eleve) use ($lignes): array {
                $ligne = $lignes->get($eleve->id);

                return [
                    'user_id' => $eleve->id,
                    'nom' => (string) $eleve->name,
                    'mark' => $ligne?->mark?->value,
                    'origin' => $ligne?->origin?->value,
                    'delegated_at' => $ligne?->delegated_at?->toIso8601String(),
                ];
            }, $eleves),
        ];
    }

    public function saisir(int $seanceId, User $acteur, array $marques): void
    {
        $seance = $this->seanceGeree($seanceId, $acteur);
        if (Carbon::parse($seance->date_seance)->isAfter(now())) {
            throw new BusinessException('L\'appel s\'ouvre au début de la séance.', 409, null, self::NON_COMMENCEE);
        }
        $eleves = [];
        foreach ($this->eleves($seance) as $eleve) {
            $eleves[$eleve->id] = $eleve;
        }
        foreach ($marques as $marque) {
        }

        $this->db->transaction(function () use ($seance, $acteur, $marques, $eleves): void {
            foreach ($marques as $marque) {
                $this->marquer($seance, $eleves[$marque['user_id']], $marque['mark'], $acteur);
            }
        });
    }

    private function marquer(Seance $seance, User $eleve, AttendanceMark $marque, User $acteur): void
    {
        $ligne = $this->lignes($seance)->where('user_id', $eleve->id)->first()
            ?? new ESBTPAttendance([
                'seance_id' => $seance->id,
                'user_id' => $eleve->id,
                'institution_id' => $seance->institution_id,
                'nom' => $eleve->name,
                'email' => $eleve->email,
                // Aucune connexion visio : ni « connecté », ni aucun autre statut (#1649).
                'status' => null,
            ]);
        $avant = $ligne->exists && $ligne->mark !== null ? $this->etat($ligne) : null;

        $ligne->fill([
            'mark' => $marque,
            'origin' => AttendanceOrigin::Delegated,
            'delegated_by_id' => $acteur->id,
            'delegated_at' => now(),
        ])->save();

        if ($avant !== null && $avant['mark'] !== $marque->value) {
            $this->audit->logModelEvent('update', $ligne, $avant, $this->etat($ligne));
        }
    }

    /** @return array{mark: ?string, origin: ?string, delegated_by_id: ?int} */
    private function etat(ESBTPAttendance $ligne): array
    {
        return [
            'mark' => $ligne->mark?->value,
            'origin' => $ligne->origin?->value,
            'delegated_by_id' => $ligne->delegated_by_id,
        ];
    }

    /** @throws BusinessException 404 hors de l'école, 403 hors de l'auteur et de la gestion */
    private function seanceGeree(int $seanceId, User $acteur): Seance
    {
        $seance = $this->seances->pour($seanceId, $acteur);
        if (! $seance instanceof Seance) {
            throw new BusinessException('Séance non trouvée', 404);
        }
        if (! $this->acteurs->canManage($seance, $acteur)) {
            throw new BusinessException('Vous ne pouvez pas saisir l\'appel de cette séance.', 403);
        }

        return $seance;
    }

    /** @return list<User> par nom, puis par id : un ordre stable pour l'écran */
    private function eleves(Seance $seance): array
    {
        $eleves = $this->participants->de($seance)['eleves'];
        usort($eleves, static fn (User $a, User $b): int => [(string) $a->name, $a->id] <=> [(string) $b->name, $b->id]);

        return $eleves;
    }

    /** @return Builder<ESBTPAttendance> */
    private function lignes(Seance $seance): Builder
    {
        return ESBTPAttendance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $seance->institution_id)
            ->where('seance_id', $seance->id);
    }
}

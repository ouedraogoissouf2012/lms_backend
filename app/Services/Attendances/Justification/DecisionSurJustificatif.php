<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Enums\EtatJustificatif;
use App\Exceptions\BusinessException;
use App\Models\ESBTPAttendance;
use App\Models\JustificatifAbsence;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use App\Services\Attendances\PresenceRetenue;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * La gestion tranche un justificatif (#1681), UNE fois (409 ensuite), dans une transaction qui verrouille sa ligne.
 *
 * - Valider : chaque séance visée dont la présence retenue n'est pas « présent » passe à `excused`, origine
 *   `delegated`, avec qui et quand ; la ligne est créée si elle manque (statut visio NUL, #1649). Une présence n'est
 *   jamais écrasée, une absence déjà excusée reste telle quelle. L'ancienne marque est journalisée (avant, après).
 * - Rejeter : motif obligatoire, aucune marque touchée.
 * - L'élève est notifié de la décision.
 */
final class DecisionSurJustificatif
{
    public const DEJA_TRANCHE = 'justification_already_decided';

    public function __construct(
        private readonly JustificatifsDeLEcole $justificatifs,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly ConnectionInterface $db,
    ) {}

    public function valider(int $id, User $gestion): JustificatifAbsence
    {
        return $this->trancher($id, $gestion, function (JustificatifAbsence $j) use ($gestion): void {
            foreach ($j->seances as $seance) {
                $this->excuser($seance, $j, $gestion);
            }
            $j->etat = EtatJustificatif::Validee;
        });
    }

    public function rejeter(int $id, User $gestion, string $motif): JustificatifAbsence
    {
        return $this->trancher($id, $gestion, static function (JustificatifAbsence $j) use ($motif): void {
            $j->etat = EtatJustificatif::Rejetee;
            $j->motif_rejet = $motif;
        });
    }

    /** @param  callable(JustificatifAbsence): void  $decision */
    private function trancher(int $id, User $gestion, callable $decision): JustificatifAbsence
    {
        $designe = $this->justificatifs->pourLaGestionParId($id, $gestion);
        $tranche = $this->db->transaction(function () use ($designe, $gestion, $decision): JustificatifAbsence {
            $j = JustificatifAbsence::query()->withoutGlobalScope('institution')
                ->where('institution_id', $designe->institution_id)->whereKey($designe->id)->lockForUpdate()->firstOrFail();
            if ($j->etat !== EtatJustificatif::EnAttente) {
                throw new BusinessException('Ce justificatif a déjà été tranché.', 409, null, self::DEJA_TRANCHE);
            }
            $decision($j);
            $j->forceFill(['decide_par_id' => $gestion->id, 'decide_le' => now()])->save();

            return $j;
        });
        if (! $tranche instanceof JustificatifAbsence) {
            throw new LogicException('La décision n\'a rien retenu.');
        }
        $this->notifier($tranche);

        return $tranche;
    }

    private function excuser(Seance $seance, JustificatifAbsence $j, User $gestion): void
    {
        $ligne = ESBTPAttendance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $j->institution_id)
            ->where('seance_id', $seance->id)->where('user_id', $j->eleve_id)
            ->lockForUpdate()->first();
        if ($ligne instanceof ESBTPAttendance && (PresenceRetenue::estPresente($ligne) || $ligne->mark === AttendanceMark::Excused)) {
            return;
        }
        $avant = $ligne?->mark === null ? null : ['mark' => $ligne->mark->value, 'origin' => $ligne->origin?->value];
        $eleve = $j->eleve;
        $ligne ??= new ESBTPAttendance([
            'seance_id' => $seance->id,
            'user_id' => $j->eleve_id,
            'institution_id' => $j->institution_id,
            'nom' => $eleve?->name,
            'email' => $eleve?->email,
            'status' => null,
        ]);
        $ligne->fill([
            'mark' => AttendanceMark::Excused,
            'origin' => AttendanceOrigin::Delegated,
            'delegated_by_id' => $gestion->id,
            'delegated_at' => now(),
        ])->save();
        if ($avant !== null) {
            $this->audit->logModelEvent('update', $ligne, $avant, ['mark' => AttendanceMark::Excused->value, 'origin' => AttendanceOrigin::Delegated->value]);
        }
    }

    private function notifier(JustificatifAbsence $j): void
    {
        $eleve = $j->eleve;
        if (! $eleve instanceof User) {
            return;
        }
        [$titre, $message] = $j->etat === EtatJustificatif::Validee
            ? ['Justificatif validé', 'Votre justificatif d’absence a été validé : les absences visées sont excusées.']
            : ['Justificatif refusé', 'Votre justificatif d’absence a été refusé. Motif : '.$j->motif_rejet];
        $this->notifications->send($eleve, Notification::TYPE_ABSENCE_JUSTIFICATION_DECIDED, $titre, $message, [
            'justificatif_id' => $j->id,
            'etat' => $j->etat->value,
        ]);
    }
}

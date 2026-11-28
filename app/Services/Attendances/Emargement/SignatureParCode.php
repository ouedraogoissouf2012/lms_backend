<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Enums\AttendanceMark;
use App\Enums\AttendanceOrigin;
use App\Exceptions\BusinessException;
use App\Exceptions\EssaisDeCodeEpuises;
use App\Models\CodeEmargement;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\ParticipantsDeSeanceLocale;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * L'élève signe une séance par son code (#1680, ARRÊT 1 validé le 27/11).
 *
 * - Le code est cherché par son empreinte DANS l'école de l'élève : un code d'une autre école est inconnu (404).
 * - Expiré ou révoqué : 410. Chaque code inconnu ou périmé compte un ÉCHEC : 10 par élève sur 10 minutes, puis 429
 *   avec le délai ({@see EssaisDeCodeEpuises}), sur le modèle de `RejoindreParCodeService`.
 * - Seul un élève ACTIF de la classe de la séance signe (422 sinon) : la règle de l'appel.
 * - Marque `present`, ou `late` au-delà du seuil après le début ; origine `code`. Rejouée, elle ne double rien.
 * - Le code n'écrase JAMAIS la marque de l'enseignant (409) : l'appel reste le dernier juge.
 * - Une signature sans visio crée une ligne au statut visio NUL, jamais « connecté » (#1649).
 */
final class SignatureParCode
{
    public const ECHECS_MAX = 10;

    public const FENETRE_SECONDES = 600;

    public const INCONNU = 'attendance_code_unknown';

    public const PERIME = 'attendance_code_expired';

    public const DEJA_MARQUE = 'already_marked_by_teacher';

    public const HORS_SEANCE = 'student_not_in_seance';

    public function __construct(
        private readonly EmpreinteDeCode $empreinte,
        private readonly ParticipantsDeSeanceLocale $participants,
        private readonly RateLimiter $limiteur,
        private readonly ConnectionInterface $db,
    ) {}

    /** @return array{seance_id: int, mark: string, deja_signe: bool} */
    public function signer(User $eleve, string $code): array
    {
        $echecs = 'emargement-echecs:'.$eleve->id;
        if ($this->limiteur->tooManyAttempts($echecs, self::ECHECS_MAX)) {
            throw new EssaisDeCodeEpuises($this->limiteur->availableIn($echecs));
        }
        try {
            $ligneDeCode = $this->codeValide($eleve, $code);
        } catch (BusinessException $refus) {
            $this->limiteur->hit($echecs, self::FENETRE_SECONDES);

            throw $refus;
        }
        $seance = Seance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $ligneDeCode->institution_id)->whereKey($ligneDeCode->seance_id)->first();
        if (! $seance instanceof Seance || ! $this->estAttendu($seance, $eleve)) {
            throw new BusinessException('Vous n\'êtes pas un élève actif de la classe de cette séance.', 422, null, self::HORS_SEANCE);
        }

        try {
            return $this->retenir($seance, $eleve, $ligneDeCode)->enTableau();
        } catch (UniqueConstraintViolationException) {
            // Deux premières signatures simultanées (double envoi) : aucune ligne à verrouiller, les deux insèrent,
            // l'unicité (seance_id, user_id) refuse la seconde. Rejouée, elle trouve la ligne : rejeu idempotent.
            return $this->retenir($seance, $eleve, $ligneDeCode)->enTableau();
        }
    }

    private function retenir(Seance $seance, User $eleve, CodeEmargement $code): SignatureRetenue
    {
        $retenue = $this->db->transaction(fn (): SignatureRetenue => $this->marquer($seance, $eleve, $code));
        if (! $retenue instanceof SignatureRetenue) {
            throw new LogicException('La transaction de la signature n\'a rien retenu.');
        }

        return $retenue;
    }

    /** @throws BusinessException 404 inconnu, 410 expiré ou révoqué */
    private function codeValide(User $eleve, string $code): CodeEmargement
    {
        $ligne = CodeEmargement::query()->withoutGlobalScope('institution')
            ->where('institution_id', $eleve->institution_id)
            ->where('code_hash', $this->empreinte->de($code))
            ->latest('id')
            ->first();
        if (! $ligne instanceof CodeEmargement) {
            throw new BusinessException('Code inconnu.', 404, null, self::INCONNU);
        }
        if ($ligne->revoque_le !== null || ! $ligne->expire_le->isFuture()) {
            throw new BusinessException('Ce code n\'est plus valable.', 410, null, self::PERIME);
        }

        return $ligne;
    }

    private function estAttendu(Seance $seance, User $eleve): bool
    {
        foreach ($this->participants->de($seance)['eleves'] as $attendu) {
            if ($attendu->id === $eleve->id) {
                return true;
            }
        }

        return false;
    }

    private function marquer(Seance $seance, User $eleve, CodeEmargement $code): SignatureRetenue
    {
        $ligne = ESBTPAttendance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $seance->institution_id)
            ->where('seance_id', $seance->id)->where('user_id', $eleve->id)
            ->lockForUpdate()->first();
        if ($ligne instanceof ESBTPAttendance && $ligne->mark !== null) {
            if ($ligne->origin !== AttendanceOrigin::Code) {
                throw new BusinessException('L\'enseignant a déjà marqué votre présence à cette séance.', 409, null, self::DEJA_MARQUE);
            }

            return new SignatureRetenue((int) $seance->id, $ligne->mark, true);
        }

        $marque = $this->marqueAuMoment($seance, $code);
        ($ligne ?? new ESBTPAttendance([
            'seance_id' => $seance->id,
            'user_id' => $eleve->id,
            'institution_id' => $seance->institution_id,
            'nom' => $eleve->name,
            'email' => $eleve->email,
            'status' => null,
        ]))->fill(['mark' => $marque, 'origin' => AttendanceOrigin::Code])->save();
        $code->increment('signatures');

        return new SignatureRetenue((int) $seance->id, $marque, false);
    }

    /** `late` au-delà du seuil après le début de la séance, sinon `present`. */
    private function marqueAuMoment(Seance $seance, CodeEmargement $code): AttendanceMark
    {
        if ($code->retard_apres_minutes === null || $seance->date_seance === null) {
            return AttendanceMark::Present;
        }

        return now()->isAfter(Carbon::parse($seance->date_seance)->addMinutes($code->retard_apres_minutes))
            ? AttendanceMark::Late
            : AttendanceMark::Present;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\TrainingSessionStatus;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Catalogue\ClasseArchivee;

/**
 * Faire ENTRER un élève dans une classe, par décision de l'école (#1652, PR C).
 *
 * Le pendant de `StudentEnrolmentService::rejoindre()`, où c'est l'apprenant qui demande :
 * ici l'école décide, donc une adhésion close de l'élève dans cette classe est RÉACTIVÉE
 * (jamais doublée : l'unique `(classe_id, user_id)` le refuserait), sans date de fin ni motif.
 *
 * La classe doit recevoir, dans cet ordre :
 * - pas archivée : 409 `class_archived` ({@see ClasseArchivee}) ;
 * - sa Période, s'il y en a une, ne ferme pas les inscriptions : 409 `class_period_closed`
 *   ({@see TrainingSessionStatus::fermeLesInscriptions()}) ;
 * - l'élève n'y est pas déjà actif : 409 `already_in_class` ;
 * - une place : 409 `class_full`, la règle de `StudentEnrolmentService::exigerUnePlace()`.
 *
 * À appeler DANS une transaction : la ligne de la classe est verrouillée ici, et la place se
 * compte sous ce verrou (deux admissions simultanées ne dépassent pas la capacité).
 */
final class AdmissionParLEcole
{
    public const PERIODE_CLOSE = 'class_period_closed';

    public const DEJA_DANS_LA_CLASSE = 'already_in_class';

    public function __construct(private readonly StudentEnrolmentService $inscriptions) {}

    /**
     * @throws BusinessException 409 `class_archived`, `class_period_closed`, `already_in_class`, `class_full`
     */
    public function admettre(User $eleve, Classe $cible): void
    {
        $classe = Classe::query()->withoutGlobalScopes()->whereKey($cible->id)->lockForUpdate()->firstOrFail();

        if ($classe->estArchivee()) {
            throw ClasseArchivee::exception();
        }

        $this->exigerUnePeriodeOuverte($classe);

        $statut = $classe->etudiants()->newPivotStatementForId($eleve->id)->value('statut');

        if ($statut === ClasseEtudiantStatut::Actif->value) {
            throw new BusinessException('Cet élève est déjà inscrit dans cette classe.', 409, reason: self::DEJA_DANS_LA_CLASSE);
        }

        $this->inscriptions->exigerUnePlace($classe);

        if (is_string($statut)) {
            $classe->etudiants()->newPivotStatementForId($eleve->id)->update([
                'statut' => ClasseEtudiantStatut::Actif->value,
                'ends_on' => null,
                'motif_fin' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        $classe->etudiants()->attach($eleve->id, [
            'institution_id' => $classe->institution_id,
            'statut' => ClasseEtudiantStatut::Actif->value,
            'date_inscription' => now()->toDateString(),
        ]);
    }

    /** @throws BusinessException 409 `class_period_closed` */
    private function exigerUnePeriodeOuverte(Classe $classe): void
    {
        if ($classe->training_session_id === null) {
            return;
        }

        $periode = TrainingSession::query()->withoutGlobalScope('institution')->withTrashed()
            ->where('institution_id', $classe->institution_id)
            ->whereKey($classe->training_session_id)
            ->first();

        if ($periode instanceof TrainingSession && ($periode->trashed() || $periode->status->fermeLesInscriptions())) {
            throw new BusinessException(
                'La Période de cette classe est close : elle ne reçoit plus d\'inscription.',
                409,
                reason: self::PERIODE_CLOSE,
            );
        }
    }
}

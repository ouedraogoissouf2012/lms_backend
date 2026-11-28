<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Reclamation;

use App\Enums\Role;
use App\Enums\StatutDeReclamation;
use App\Exceptions\BusinessException;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\ReclamationDeNote;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\Evaluation\Student\CopiesFinales;
use App\Services\Evaluation\Student\ResultatsVisibles;
use App\Services\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * #1678 : la réclamation d'une note. L'élève conteste la note de SA copie, l'enseignant de
 * l'évaluation donne son avis, la gestion (admin, coordinateur) tranche ; chaque étape est
 * notifiée. Une note corrigée passe par l'écrivain des notes (`EvaluationGradingService::
 * fixerLaNote`), et repasse « à renvoyer » à KLASSCI quand l'évaluation y est liée.
 *
 * Les droits de l'avis et de la décision sont tranchés par les requêtes (la règle
 * `ChecksEvaluationOwnership`) ; ici, le circuit et ses refus nommés.
 */
final class ReclamationsDeNotes
{
    public const DELAI_PAR_DEFAUT = 7;

    public function __construct(
        private readonly CopiesFinales $copies,
        private readonly EvaluationGradingService $notation,
        private readonly NotificationService $notifications,
    ) {}

    public function deposer(int $evaluationId, User $eleve, string $motif): ReclamationDeNote
    {
        $copie = $this->copies->deLEleve($eleve)->first(static fn (EvaluationSubmission $c): bool => (int) $c->evaluation_id === $evaluationId);
        $evaluation = $copie?->evaluation;
        if (! $copie instanceof EvaluationSubmission || ! $evaluation instanceof Evaluation || ! ResultatsVisibles::pour($evaluation)) {
            throw new NotFoundHttpException('Aucune note à réclamer pour cette évaluation.');
        }
        $depuis = $copie->graded_at ?? $copie->submitted_at;
        if ($depuis instanceof CarbonInterface && Carbon::now()->greaterThan($depuis->copy()->addDays(self::delai($evaluation)))) {
            throw new BusinessException('Le délai de réclamation de cette note est passé.', 422, null, 'grade_claim_too_late');
        }
        if (self::ouvertes()->where('evaluation_submission_id', $copie->id)->exists()) {
            throw new BusinessException('Une réclamation de cette note est déjà en cours.', 409, null, 'grade_claim_already_open');
        }

        $reclamation = ReclamationDeNote::query()->create([
            'institution_id' => $copie->institution_id, 'evaluation_id' => $evaluation->id, 'evaluation_submission_id' => $copie->id,
            'student_id' => $eleve->id, 'motif' => trim($motif), 'statut' => StatutDeReclamation::Deposee,
            'note_contestee' => $copie->note_sur_20,
        ]);
        $this->notifier(self::enseignant($evaluation), Notification::TYPE_RECLAMATION_DEPOSEE, 'Réclamation de note', 'Un élève conteste sa note à « '.$evaluation->titre.' ».', $reclamation);

        return $reclamation;
    }

    public function donnerAvis(ReclamationDeNote $reclamation, User $enseignant, string $avis, ?string $commentaire, ?float $note): ReclamationDeNote
    {
        self::etape($reclamation, StatutDeReclamation::Deposee);
        $reclamation->fill([
            'statut' => StatutDeReclamation::AvisDonne, 'avis' => $avis, 'avis_commentaire' => $commentaire,
            'note_proposee' => $avis === 'corriger' ? $note : null, 'avis_par' => $enseignant->id, 'avis_le' => Carbon::now(),
        ])->save();
        foreach (self::gestion((int) $reclamation->institution_id) as $gestionnaire) {
            $this->notifier($gestionnaire, Notification::TYPE_RECLAMATION_A_TRANCHER, 'Réclamation à trancher', 'L\'enseignant a donné son avis sur une réclamation de note.', $reclamation);
        }

        return $reclamation;
    }

    public function trancher(ReclamationDeNote $reclamation, User $gestionnaire, string $decision, ?float $note, ?string $commentaire): ReclamationDeNote
    {
        self::etape($reclamation, StatutDeReclamation::AvisDonne);
        DB::transaction(function () use ($reclamation, $gestionnaire, $decision, $note, $commentaire): void {
            if ($decision === 'corrigee' && $note !== null && $reclamation->copie instanceof EvaluationSubmission) {
                $this->notation->fixerLaNote($reclamation->copie, $note);
            }
            $reclamation->fill([
                'statut' => StatutDeReclamation::Tranchee, 'decision' => $decision, 'note_decidee' => $decision === 'corrigee' ? $note : null,
                'decision_commentaire' => $commentaire, 'decision_par' => $gestionnaire->id, 'decision_le' => Carbon::now(),
            ])->save();
        });
        $eleve = User::query()->withoutGlobalScope('institution')->find($reclamation->student_id);
        $this->notifier($eleve, Notification::TYPE_RECLAMATION_TRANCHEE, 'Réclamation tranchée', $decision === 'corrigee' ? 'Votre note a été corrigée.' : 'Votre note est maintenue.', $reclamation);

        return $reclamation;
    }

    /** La réclamation de cette évaluation de l'école ; introuvable sinon. */
    public function de(int $evaluationId, int $id, User $lecteur): ReclamationDeNote
    {
        $reclamation = ReclamationDeNote::query()->withoutGlobalScope('institution')
            ->where('institution_id', $lecteur->institution_id)->where('evaluation_id', $evaluationId)->whereKey($id)->first();
        if (! $reclamation instanceof ReclamationDeNote) {
            throw new NotFoundHttpException('Réclamation introuvable.');
        }

        return $reclamation;
    }

    /**
     * Ce que le lecteur voit : l'élève, les siennes ; l'enseignant, celles de SES évaluations (la
     * règle de propriété de `ChecksEvaluationOwnership`, en requête : l'auteur local, sinon
     * l'identité KLASSCI) ; la gestion, toute l'école. Les plus récentes d'abord.
     *
     * @return Collection<int, ReclamationDeNote>
     */
    public function visibles(User $lecteur, ?StatutDeReclamation $statut): Collection
    {
        return self::portee($lecteur)->when($statut !== null, static fn (Builder $q) => $q->where('statut', $statut))
            ->with(['copie', 'evaluation'])->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** @return array{en_attente_enseignant: int, a_trancher: int, corrigees_30j: int, maintenues_30j: int, a_renvoyer_a_klassci: int} */
    public function compteurs(User $lecteur): array
    {
        $recentes = static fn (Builder $q) => $q->where('statut', StatutDeReclamation::Tranchee)->where('decision_le', '>=', Carbon::now()->subDays(30));

        return [
            'en_attente_enseignant' => self::portee($lecteur)->where('statut', StatutDeReclamation::Deposee)->count(),
            'a_trancher' => self::portee($lecteur)->where('statut', StatutDeReclamation::AvisDonne)->count(),
            'corrigees_30j' => self::portee($lecteur)->where($recentes)->where('decision', 'corrigee')->count(),
            'maintenues_30j' => self::portee($lecteur)->where($recentes)->where('decision', 'maintenue')->count(),
            'a_renvoyer_a_klassci' => self::portee($lecteur)->where('decision', 'corrigee')
                ->whereHas('evaluation', static fn (Builder $e) => $e->withoutGlobalScope('institution')->whereNotNull('klassci_evaluation_id'))
                ->whereHas('copie', static fn (Builder $c) => $c->withoutGlobalScope('institution')->where('synced_to_klassci', false))->count(),
        ];
    }

    /**
     * L'état KLASSCI d'une note corrigée : `a_renvoyer` tant que la copie n'est pas repartie (un
     * échec ou un refus de KLASSCI le laisse ainsi, visible), `a_jour` ensuite ; nul sans lien
     * KLASSCI ou sans correction.
     */
    public static function etatKlassci(ReclamationDeNote $reclamation): ?string
    {
        if ($reclamation->decision !== 'corrigee' || ! is_numeric($reclamation->evaluation?->klassci_evaluation_id)) {
            return null;
        }

        return $reclamation->copie?->synced_to_klassci === true ? 'a_jour' : 'a_renvoyer';
    }

    /** @return Builder<ReclamationDeNote> */
    private static function portee(User $lecteur): Builder
    {
        $requete = ReclamationDeNote::query()->withoutGlobalScope('institution')->where('institution_id', $lecteur->institution_id);
        if ($lecteur->isManager()) {
            return $requete;
        }
        if ($lecteur->isTeacher()) {
            return $requete->whereHas('evaluation', static fn (Builder $e) => $e->withoutGlobalScope('institution')
                ->where(static fn (Builder $p) => $p->where('enseignant_id', $lecteur->id)
                    ->when($lecteur->klassci_enseignant_id !== null, static fn (Builder $k) => $k->orWhere('klassci_enseignant_id', $lecteur->klassci_enseignant_id))));
        }

        return $requete->where('student_id', $lecteur->id);
    }

    /** @return Builder<ReclamationDeNote> */
    private static function ouvertes(): Builder
    {
        return ReclamationDeNote::query()->withoutGlobalScope('institution')->where('statut', '!=', StatutDeReclamation::Tranchee);
    }

    private static function etape(ReclamationDeNote $reclamation, StatutDeReclamation $attendue): void
    {
        if ($reclamation->statut !== $attendue) {
            throw new BusinessException('Cette réclamation n\'est pas à cette étape.', 409, null, 'grade_claim_wrong_step');
        }
    }

    /** Le délai de réclamation de l'école, en jours (`settings.delai_reclamation_jours`, 7 par défaut). */
    private static function delai(Evaluation $evaluation): int
    {
        $reglages = Institution::query()->find($evaluation->institution_id, ['id', 'settings'])?->settings;
        $jours = is_array($reglages) ? ($reglages['delai_reclamation_jours'] ?? null) : null;

        return is_numeric($jours) && (int) $jours >= 0 ? (int) $jours : self::DELAI_PAR_DEFAUT;
    }

    private static function enseignant(Evaluation $evaluation): ?User
    {
        return $evaluation->enseignant_id === null ? null : User::query()->withoutGlobalScope('institution')->find($evaluation->enseignant_id);
    }

    /** @return Collection<int, User> la gestion de l'école : admins et coordinateurs, alias compris */
    private static function gestion(int $institution): Collection
    {
        $roles = array_merge(Role::Coordinateur->aliases(), Role::Admin->aliases(), Role::SuperAdmin->aliases());

        return User::query()->withoutGlobalScope('institution')->where('institution_id', $institution)->whereIn('role', $roles)->get();
    }

    private function notifier(?User $destinataire, string $type, string $titre, string $message, ReclamationDeNote $reclamation): void
    {
        if ($destinataire instanceof User) {
            $this->notifications->send($destinataire, $type, $titre, $message, ['reclamation_id' => $reclamation->id, 'evaluation_id' => $reclamation->evaluation_id]);
        }
    }
}

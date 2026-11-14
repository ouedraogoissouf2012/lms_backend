<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Student\LearnerEvaluationScope;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Liste (personnel) + détail des évaluations. Le détail sert aussi l'élève qui
 * compose : il ne lui rend que ce que sa propre liste lui propose
 * ({@see LearnerEvaluationScope}).
 *
 * Extrait de `EvaluationCrudController::index` et `::show` (split §5).
 *
 * Délègue l'enrichissement KLASSCI à {@see EvaluationEnrichmentService} (classes,
 * matières, enseignant, métadonnées). Ajoute en plus la dernière soumission
 * de l'utilisateur connecté quand celui-ci est étudiant — PERF-03 batch 2 :
 * une seule query batched plutôt que N*2 (`find` + `latest()->first()`).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Le constructeur injecte ses trois collaborateurs (enrichissement, audience du
 * corrigé, portée de l'élève). Aucun `app()`, aucune Facade. Pas de logger ici —
 * le service ne logge pas (lecture pure).
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationListService
{
    public function __construct(
        private readonly AnswerKeyAudience $answerKeyAudience,
        private readonly LearnerEvaluationScope $learnerScope,
        private readonly RosterAuthorityFactory $sources,
    ) {}

    /**
     * Liste les évaluations avec filtres optionnels et enrichissement KLASSCI.
     *
     * Si `$user` est un étudiant synchronisé KLASSCI (`klassci_id` non null),
     * sa dernière soumission par évaluation est attachée au payload via
     * batch query (clé `student_submission`).
     *
     * @param  array<string, mixed>  $filters  Clés acceptées : classe_id,
     *                                          matiere_id, status, is_published,
     *                                          limit (borné 1-100, #548 — pas
     *                                          de pagination réelle, plafond
     *                                          simple pour préserver la forme
     *                                          de réponse en tableau plat).
     *                                          Les clés absentes sont ignorées.
     * @return array<int, array<string, mixed>>
     */
    public function listForTeacher(User $user, array $filters): array
    {
        // `submissions` n'est PAS chargée : elle était sérialisée telle quelle
        // et livrait les copies de tout l'établissement — réponses, score et
        // note — à quiconque appelait cette route, alors sans garde de rôle.
        // Elle est désormais réservée au personnel (ListEvaluationsRequest::
        // authorize) ; la copie reste hors de la réponse.
        //
        // Rien ne consommait le tableau : les deux écrans qui affichent le
        // nombre de copies préfèrent déjà `submissions_count`.
        //
        // Ce compteur est demandé ici, par `withCount`, et non laissé à
        // l'enrichissement : celui-ci est *fail-soft* et, quand KLASSCI est
        // injoignable, il rend les évaluations SANS ses métadonnées calculées
        // (`EvaluationEnrichmentService:97`). Le compteur aurait alors disparu
        // en même temps que la relation, et le bouton « Voir les notes » avec
        // lui — une panne KLASSCI aurait effacé les notes des enseignants.
        // Porté par le modèle, il survit aux deux modes.
        //
        // D540 : `submissions_to_sync_count` compte ce que l'envoi vers KLASSCI
        // enverra, par la même règle (`EvaluationSubmission::aSynchroniser`).
        $aSynchroniser = static function (Builder $copies): void {
            /** @var Builder<EvaluationSubmission> $copies */
            $copies->aSynchroniser();
        };
        $query = Evaluation::withCount(['submissions', 'submissions as submissions_to_sync_count' => $aSynchroniser])
            ->with(['questions']);

        // D72, E4b (C27) : les identifiants des filtres sont dans l'espace de l'école.
        $catalogue = $this->catalogue();

        if (array_key_exists('classe_id', $filters)) {
            $query->where($catalogue->colonne(FiltreDeListe::Classe), $filters['classe_id']);
        }

        if (array_key_exists('matiere_id', $filters)) {
            $query->where($catalogue->colonne(FiltreDeListe::Matiere), $filters['matiere_id']);
        }

        if (array_key_exists('status', $filters)) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('is_published', $filters)) {
            $query->where('is_published', (bool) $filters['is_published']);
        }

        $limitInput = $filters['limit'] ?? 100;
        $limit = is_numeric($limitInput) ? (int) $limitInput : 100;

        $evaluations = $query
            ->orderBy('date_evaluation', 'desc')
            ->limit($limit)
            ->get();

        $enrichedEvaluations = $catalogue->presenter($evaluations, $user);

        // PERF-03 batch 2 — pour un étudiant KLASSCI sync, attacher sa dernière
        // soumission par évaluation via une seule query batched.
        if ($user->klassci_id && $evaluations->isNotEmpty()) {
            $userLatestSubmissions = EvaluationSubmission::query()
                ->whereIn('evaluation_id', $evaluations->pluck('id'))
                ->where('klassci_etudiant_id', $user->klassci_id)
                ->orderByDesc('id')
                ->get()
                ->groupBy('evaluation_id')
                ->map(fn ($subs) => $subs->first());

            $enrichedEvaluations = collect($enrichedEvaluations)
                ->map(function (array $evalArray) use ($userLatestSubmissions): array {
                    $evalArray['student_submission'] = $userLatestSubmissions->get($evalArray['id']);
                    return $evalArray;
                })
                ->toArray();
        }

        return $enrichedEvaluations;
    }

    /**
     * Récupère une évaluation par id, avec ses questions et enrichissement KLASSCI.
     *
     * Retourne `null` quand l'évaluation n'existe pas, ou qu'un apprenant ne la
     * voit pas dans sa propre liste — le caller (controller) convertit en HTTP
     * 404, sans distinguer les deux cas.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(int $id, User $user): ?array
    {
        // Pour un apprenant, la portée est résolue AVANT la lecture : il ne
        // charge que dans ce que sa liste lui propose. Le 404 ne distingue donc
        // jamais « n'existe pas » de « pas la vôtre ».
        $query = $user->isLearnerAudience() ? $this->learnerScope->visibleFor($user) : Evaluation::query();
        $evaluation = $query?->with('questions')->find($id);

        if (! $evaluation instanceof Evaluation) {
            return null;
        }

        // Ce chemin sert DEUX publics : l'enseignant qui rédige ses questions,
        // et l'élève qui compose. Le corrigé est masqué par défaut sur le
        // modèle ; seul le premier le voit, et il faut le dire explicitement.
        if ($this->answerKeyAudience->maySee($user)) {
            $evaluation->questions->each->makeVisible(['correct_answers', 'explanation']);
        }

        return $this->catalogue()->presenter(collect([$evaluation]), $user)[0];
    }

    /**
     * Le catalogue de l'école de l'appelant (D72, E4b), résolu À CHAQUE APPEL : ce
     * service est reçu par le constructeur d'un contrôleur que Laravel garde sur
     * l'objet Route (règle 3 d'ADR-EI-01). Aucun mode n'est comparé ici.
     */
    private function catalogue(): CatalogueDesEvaluations
    {
        return $this->sources->sourceForCurrentTenant(KlassciCatalogueDesEvaluations::class, LocalCatalogueDesEvaluations::class);
    }
}

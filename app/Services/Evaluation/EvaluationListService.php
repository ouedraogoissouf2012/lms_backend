<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;

/**
 * Liste + détail des évaluations côté enseignant/admin.
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
 * Constructeur injecte uniquement `EvaluationEnrichmentService`. Aucun `app()`,
 * aucune Facade. Pas de logger ici — le service ne logge pas (lecture pure).
 *
 * ## Comportement préservé
 *
 * Aucun changement runtime : la logique de filtrage, d'enrichissement et de
 * batch query est déplacée verbatim depuis le controller.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationListService
{
    public function __construct(
        private readonly EvaluationEnrichmentService $enrichmentService,
    ) {}

    /**
     * Liste les évaluations avec filtres optionnels et enrichissement KLASSCI.
     *
     * Si `$user` est un étudiant synchronisé KLASSCI (`klassci_id` non null),
     * sa dernière soumission par évaluation est attachée au payload via
     * batch query (clé `student_submission`).
     *
     * @param  array<string, mixed>  $filters  Clés acceptées : classe_id,
     *                                          matiere_id, status, is_published.
     *                                          Les clés absentes sont ignorées.
     * @return array<int, array<string, mixed>>
     */
    public function listForTeacher(User $user, array $filters): array
    {
        $query = Evaluation::with(['questions', 'submissions']);

        if (array_key_exists('classe_id', $filters)) {
            $query->where('klassci_classe_id', $filters['classe_id']);
        }

        if (array_key_exists('matiere_id', $filters)) {
            $query->where('klassci_matiere_id', $filters['matiere_id']);
        }

        if (array_key_exists('status', $filters)) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('is_published', $filters)) {
            $query->where('is_published', (bool) $filters['is_published']);
        }

        $evaluations = $query->orderBy('date_evaluation', 'desc')->get();

        $enrichedEvaluations = $this->enrichmentService->enrich($evaluations);

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
     * Retourne `null` quand l'évaluation n'existe pas — le caller (controller)
     * convertit en HTTP 404.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(int $id): ?array
    {
        $evaluation = Evaluation::with('questions')->find($id);

        if (! $evaluation) {
            return null;
        }

        return $this->enrichmentService->enrich(collect([$evaluation]))[0];
    }
}

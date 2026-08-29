<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationEnrichmentService;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Résultats agrégés enseignant — split §22 du god-controller
 * (`EvaluationTeacherController::getResultsByClass`, ~126 l).
 *
 * Construit la liste exhaustive des étudiants d'une classe KLASSCI (et
 * non plus seulement ceux ayant soumis) avec, pour chacun, sa dernière
 * soumission "live" (les essais marqués `[PRACTICE]` dans le feedback
 * sont exclus — convention historique du controller original) puis
 * agrège les statistiques de classe (moyenne, min/max, taux de
 * participation).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * `KlassciProxyService`, `EvaluationEnrichmentService` et PSR-3
 * `LoggerInterface` sont injectés par constructor. Aucun `app()`, aucune
 * Facade `Log::`.
 *
 * ## Comportement préservé
 *
 * Logique déplacée verbatim depuis le controller — mêmes try/catch,
 * mêmes filtres `feedback NOT LIKE [PRACTICE]%`, même payload renvoyé.
 * Le caller (`EvaluationTeacherController`) se contente de `response()
 * ->json($result['payload'], $result['status'])`.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationTeacherController.php
 */
final class TeacherEvaluationResultsService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly EvaluationEnrichmentService $enrichmentService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Construit le payload "résultats par classe" pour une évaluation.
     *
     * Retourne un array `{status, payload}` consommable directement par le
     * controller (cf. pattern des autres services Evaluation).
     *
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function getResultsByClass(int $evaluationId, User $teacher): array
    {
        try {
            $evaluation = Evaluation::with(['questions', 'submissions'])->find($evaluationId);

            if ($evaluation === null) {
                return [
                    'status'  => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Évaluation non trouvée',
                    ],
                ];
            }

            $this->logger->info('📊 Récupération résultats évaluation', [
                'evaluation_id' => $evaluationId,
                'classe_id'     => $evaluation->klassci_classe_id,
                'teacher_id'    => $teacher->id,
            ]);

            $teacherToken = $teacher->klassci_token;
            if (! is_string($teacherToken) || $teacherToken === '') {
                return [
                    'status'  => 401,
                    'payload' => [
                        'success' => false,
                        'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
                    ],
                ];
            }

            $classeEtudiants = $this->klassciService->getClasseEtudiants($teacherToken, (int) $evaluation->klassci_classe_id);
            $etudiants       = $classeEtudiants['data'] ?? [];

            $this->logger->info('👥 Étudiants de la classe', [
                'total_etudiants' => count($etudiants),
            ]);

            $evaluationEnrichie = $this->enrichmentService->enrich(collect([$evaluation]), $teacherToken)[0];

            $resultats = $this->buildResultats($evaluation, $etudiants);

            // Tri alphabétique par nom complet — préserve l'ordre que le
            // frontend attend.
            usort($resultats, static fn (array $a, array $b): int
                => strcmp($a['etudiant_nom_complet'], $b['etudiant_nom_complet']));

            $statistiques = $this->buildStatistiques($resultats, count($etudiants));

            $this->logger->info('✅ Résultats calculés', [
                'total_etudiants' => $statistiques['total_etudiants'],
                'soumis'          => $statistiques['etudiants_soumis'],
                'moyenne'         => $statistiques['moyenne_classe'],
            ]);

            return [
                'status'  => 200,
                'payload' => [
                    'success' => true,
                    'data'    => [
                        'evaluation'   => $evaluationEnrichie,
                        'resultats'    => $resultats,
                        'statistiques' => $statistiques,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('❌ Erreur récupération résultats évaluation', [
                'evaluation_id' => $evaluationId,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return [
                'status'  => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération des résultats',
                    'error'   => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Construit la liste des résultats pour TOUS les étudiants d'une classe
     * (pas uniquement ceux ayant soumis), avec leur dernière soumission "live".
     *
     * @param  array<int, array<string, mixed>>  $etudiants  Liste KLASSCI
     * @return array<int, array<string, mixed>>
     */
    private function buildResultats(Evaluation $evaluation, array $etudiants): array
    {
        // #503 — préchargements en amont pour éliminer le N+1 :
        // users locaux par email (1 requête), et submissions "live" groupées par
        // klassci_etudiant_id depuis la collection DÉJÀ eager-loaded (0 requête,
        // cf. `Evaluation::with('submissions')` dans getResultsByClass).
        $usersByEmail = $this->preloadUsersByEmail($etudiants);
        $submissionsByKlassci = $this->liveSubmissionsByKlassciId($evaluation);

        $resultats = [];

        foreach ($etudiants as $etudiant) {
            $email     = KlassciPayload::toStringOrNull($etudiant['email'] ?? null) ?? '';
            $userLocal = $email !== '' ? $usersByEmail->get($email) : null;

            // 1) Via klassci_id du user local synchronisé ; 2) fallback ID KLASSCI
            // direct. La plus récente par groupe (collection déjà triée desc).
            $submission = null;
            if ($userLocal !== null && $userLocal->klassci_id !== null) {
                $submission = $submissionsByKlassci->get($userLocal->klassci_id)?->first();
            }
            if ($submission === null) {
                $submission = $submissionsByKlassci->get(KlassciPayload::toInt($etudiant['id'] ?? null))?->first();
            }

            $resultats[] = [
                'etudiant_id'           => $etudiant['id'],
                'etudiant_nom'          => $etudiant['nom'] ?? '',
                'etudiant_prenom'       => $etudiant['prenom'] ?? '',
                'etudiant_nom_complet'  => trim(($etudiant['nom'] ?? '') . ' ' . ($etudiant['prenom'] ?? '')),
                'note'                  => $submission?->note_sur_20,
                'score'                 => $submission?->score,
                'status'                => $submission?->status ?? 'non_passee',
                'submitted_at'          => $submission?->submitted_at,
                'attempt'               => $submission?->attempt,
                'feedback'              => $submission?->feedback,
            ];
        }

        return $resultats;
    }

    /**
     * Users locaux indexés par email, en une seule requête (#503).
     *
     * @param  array<int, array<string, mixed>>  $etudiants
     * @return \Illuminate\Support\Collection<string, User>
     */
    private function preloadUsersByEmail(array $etudiants): \Illuminate\Support\Collection
    {
        $emails = array_values(array_filter(
            array_map(fn (array $e): string => KlassciPayload::toStringOrNull($e['email'] ?? null) ?? '', $etudiants),
            fn (string $email): bool => $email !== ''
        ));

        /** @var \Illuminate\Support\Collection<string, User> $users */
        $users = User::query()->whereIn('email', $emails)->get()->keyBy('email');

        return $users;
    }

    /**
     * Submissions "live" (hors `[PRACTICE]`) groupées par `klassci_etudiant_id`,
     * la plus récente en tête de chaque groupe. Utilise la collection DÉJÀ
     * eager-loaded sur l'évaluation → aucune requête supplémentaire (#503).
     *
     * @return \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission>>
     */
    private function liveSubmissionsByKlassciId(Evaluation $evaluation): \Illuminate\Support\Collection
    {
        return $evaluation->submissions
            ->filter(fn (EvaluationSubmission $s): bool =>
                $s->feedback === null || ! str_starts_with((string) $s->feedback, '[PRACTICE]'))
            ->sortByDesc('created_at')
            ->groupBy('klassci_etudiant_id');
    }

    /**
     * Agrège les stats de classe à partir des résultats individuels.
     *
     * Les statuts `soumis` ET `corrige` comptent comme "soumis" (le
     * corrigé reste une participation valide).
     *
     * @param  array<int, array<string, mixed>>  $resultats
     * @return array<string, mixed>
     */
    private function buildStatistiques(array $resultats, int $totalEtudiants): array
    {
        $soumissions = collect($resultats)->whereIn('status', ['soumis', 'corrige']);
        $notes       = $soumissions->pluck('note')->filter();

        return [
            'total_etudiants'      => $totalEtudiants,
            'etudiants_soumis'     => $soumissions->count(),
            'etudiants_en_cours'   => collect($resultats)->where('status', 'en_cours')->count(),
            'etudiants_non_passes' => collect($resultats)->where('status', 'non_passee')->count(),
            'taux_participation'   => $totalEtudiants > 0
                ? round(($soumissions->count() / $totalEtudiants) * 100, 2)
                : 0,
            'moyenne_classe'       => $notes->count() > 0 ? round((float) $notes->avg(), 2) : null,
            'note_max'             => $notes->count() > 0 ? round((float) $notes->max(), 2) : null,
            'note_min'             => $notes->count() > 0 ? round((float) $notes->min(), 2) : null,
        ];
    }
}

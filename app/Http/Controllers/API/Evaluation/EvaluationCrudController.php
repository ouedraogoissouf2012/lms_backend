<?php

namespace App\Http\Controllers\API\Evaluation;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteEvaluationRequest;
use App\Http\Requests\PublishEvaluationRequest;
use App\Http\Requests\StartEvaluationRequest;
use App\Http\Requests\StoreEvaluationRequest;
use App\Http\Requests\SubmitEvaluationRequest;
use App\Http\Requests\UpdateEvaluationRequest;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationEnrichmentService;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EvaluationCrudController — extrait verbatim de EvaluationController
 * dans le cadre du split god-controller (1676 lignes -> 5 fichiers SRP).
 *
 * Aucun changement comportemental : les méthodes sont déplacées telles
 * quelles, avec injection DI cohérente. Phase 2 du refactor identifié
 * dans le bilan d'audit post-PR #149.
 */
class EvaluationCrudController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private EvaluationEnrichmentService $enrichmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $query = Evaluation::with(['questions', 'submissions']);

        // Filtres
        if ($request->has('classe_id')) {
            $query->where('klassci_classe_id', $request->classe_id);
        }

        if ($request->has('matiere_id')) {
            $query->where('klassci_matiere_id', $request->matiere_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $evaluations = $query->orderBy('date_evaluation', 'desc')->get();

        // Enrichir avec les données KLASSCI (classe, matière)
        $enrichedEvaluations = $this->enrichmentService->enrich($evaluations);

        // Si c'est un étudiant avec un ID KLASSCI sync, ajouter sa soumission à chaque évaluation.
        // PERF-03 batch 2 — Avant : `Evaluation::find()` + `submissions()->latest()->first()`
        // POUR CHAQUE évaluation = 2×N queries. Après : 1 batch query qui ramène
        // toutes les latest submissions du user pour les évaluations listées.
        if ($user->klassci_id && $evaluations->isNotEmpty()) {
            $userLatestSubmissions = EvaluationSubmission::query()
                ->whereIn('evaluation_id', $evaluations->pluck('id'))
                ->where('klassci_etudiant_id', $user->klassci_id)
                ->orderByDesc('id')
                ->get()
                ->groupBy('evaluation_id')
                ->map(fn ($subs) => $subs->first());

            $enrichedEvaluations = collect($enrichedEvaluations)->map(function ($evalArray) use ($userLatestSubmissions) {
                $evalArray['student_submission'] = $userLatestSubmissions->get($evalArray['id']);
                return $evalArray;
            })->toArray();
        }

        return response()->json([
            'success' => true,
            'data' => $enrichedEvaluations
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $evaluation = Evaluation::with('questions')->find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
        }

        // Enrichir avec les données KLASSCI
        $enrichedEvaluation = $this->enrichmentService->enrich(collect([$evaluation]))[0];

        return response()->json([
            'success' => true,
            'data' => $enrichedEvaluation
        ]);
    }

    public function store(StoreEvaluationRequest $request): JsonResponse
    {
        // FormRequest handles authorization (role check)

        // Issue #124 — sécurité : l'identité enseignant est dérivée du token Sanctum,
        // jamais du body. Un utilisateur sans `klassci_enseignant_id` synchronisé
        // (admin LMS local, compte service) n'a pas vocation à créer d'évaluation —
        // refus explicite ici plutôt qu'un check d'ownership qui échouerait plus tard.
        $user = $this->authenticatedUser($request);
        if ($user->klassci_enseignant_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être un enseignant KLASSCI synchronisé pour créer une évaluation.',
            ], 403);
        }

        // Vérifier qu'une évaluation LMS n'existe pas déjà pour cette évaluation KLASSCI
        if ($request->klassci_evaluation_id) {
            $existing = Evaluation::where('klassci_evaluation_id', $request->klassci_evaluation_id)->first();
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une version en ligne existe déjà pour cette évaluation KLASSCI.',
                    'data' => $existing
                ], 409);
            }
        }

        try {
            DB::beginTransaction();

            // Récupérer les noms de matière et classe depuis KLASSCI
            $matiereNom = null;
            $classeNom = null;

            try {
                // Récupérer matière
                if ($request->klassci_matiere_id) {
                    $matieres = $this->klassciService->getMatieres();
                    if (isset($matieres['data'])) {
                        foreach ($matieres['data'] as $matiere) {
                            if ($matiere['id'] == $request->klassci_matiere_id) {
                                $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? null;
                                break;
                            }
                        }
                    }
                }

                // Récupérer classe
                if ($request->klassci_classe_id) {
                    $classes = $this->klassciService->getClasses();
                    if (isset($classes['data'])) {
                        foreach ($classes['data'] as $classe) {
                            if ($classe['id'] == $request->klassci_classe_id) {
                                $classeNom = $classe['libelle'] ?? $classe['nom'] ?? null;
                                // Si pas de nom, utiliser le code du niveau
                                if (!$classeNom && isset($classe['niveau']['code'])) {
                                    $classeNom = $classe['niveau']['code'];
                                } elseif (!$classeNom && isset($classe['niveau']['libelle'])) {
                                    $classeNom = $classe['niveau']['libelle'];
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Impossible de récupérer les noms depuis KLASSCI', ['error' => $e->getMessage()]);
            }

            // Créer l'évaluation.
            // Issue #124 — sécurité : `klassci_enseignant_id` est forcé depuis le
            // token (jamais lu du body via `$request->only(...)`). Empêche un
            // enseignant de polluer l'inbox d'un collègue ou de créer une éval
            // attribuée à un autre prof.
            $evaluation = Evaluation::create(array_merge(
                $request->only([
                    'klassci_matiere_id',
                    'klassci_classe_id',
                    'klassci_evaluation_id',
                    'titre',
                    'description',
                    'type',
                    'status',
                    'date_evaluation',
                    'duree_minutes',
                    'coefficient',
                    'bareme',
                    'is_online',
                    'allow_retake',
                    'max_attempts',
                    'shuffle_questions',
                    'show_results',
                ]),
                [
                    'matiere_nom' => $matiereNom,
                    'classe_nom' => $classeNom,
                    'klassci_enseignant_id' => $user->klassci_enseignant_id,
                ]
            ));

            // Créer les questions si fournies
            if ($request->has('questions')) {
                foreach ($request->questions as $index => $questionData) {
                    // Décoder les options et correct_answers si elles sont déjà encodées en JSON
                    $options = $questionData['options'] ?? null;
                    if (is_string($options) && $options !== null) {
                        $decoded = json_decode($options, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $options = $decoded;
                        }
                    }

                    $correctAnswers = $questionData['correct_answers'] ?? null;
                    if (is_string($correctAnswers) && $correctAnswers !== null) {
                        $decoded = json_decode($correctAnswers, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $correctAnswers = $decoded;
                        }
                    }

                    EvaluationQuestion::create([
                        'evaluation_id' => $evaluation->id,
                        'question' => $questionData['question'],
                        'type' => $questionData['type'],
                        'ordre' => $index + 1,
                        'points' => $questionData['points'] ?? 1,
                        'options' => $options,
                        'correct_answers' => $correctAnswers,
                        'explanation' => $questionData['explanation'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $evaluation->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Évaluation créée avec succès',
                'data' => $evaluation
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création évaluation', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'évaluation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    public function update(UpdateEvaluationRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        // Vérifier si l'évaluation peut être modifiée (état check)
        if (!$evaluation->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier: des étudiants ont déjà soumis leurs réponses'
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Mettre à jour les champs de base.
            // Issue #124 — sécurité : les champs d'identité de l'évaluation sont
            // write-once post-create. Aucune mutation possible via PUT, même par
            // l'owner. Si un client envoie ces champs, ils sont silencieusement
            // ignorés (backward-compat). Champs exclus :
            //   • klassci_enseignant_id (ownership — empêche transfert via PUT)
            //   • institution_id (isolation tenant)
            //   • klassci_classe_id (cible — immuable post-create)
            //   • klassci_matiere_id (matière — immuable post-create)
            //   • klassci_evaluation_id (référence KLASSCI — immuable post-create)
            $evaluation->update($request->except([
                'questions',
                'klassci_enseignant_id',
                'institution_id',
                'klassci_classe_id',
                'klassci_matiere_id',
                'klassci_evaluation_id',
            ]));

            // Si des questions sont fournies, les remplacer
            if ($request->has('questions')) {
                // Supprimer les anciennes questions
                $evaluation->questions()->delete();

                // Créer les nouvelles questions
                foreach ($request->questions as $index => $questionData) {
                    // Décoder les options et correct_answers si elles sont déjà encodées en JSON
                    $options = $questionData['options'] ?? null;
                    if (is_string($options) && $options !== null) {
                        $decoded = json_decode($options, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $options = $decoded;
                        }
                    }

                    $correctAnswers = $questionData['correct_answers'] ?? null;
                    if (is_string($correctAnswers) && $correctAnswers !== null) {
                        $decoded = json_decode($correctAnswers, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $correctAnswers = $decoded;
                        }
                    }

                    EvaluationQuestion::create([
                        'evaluation_id' => $evaluation->id,
                        'question' => $questionData['question'],
                        'type' => $questionData['type'],
                        'ordre' => $index + 1,
                        'points' => $questionData['points'] ?? 1,
                        'options' => $options,
                        'correct_answers' => $correctAnswers,
                        'explanation' => $questionData['explanation'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $evaluation->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Évaluation mise à jour',
                'data' => $evaluation
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur mise à jour évaluation', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    public function destroy(DeleteEvaluationRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        // Vérifier si l'évaluation peut être supprimée (état check)
        if (!$evaluation->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer: des étudiants ont déjà soumis leurs réponses'
            ], 403);
        }

        $evaluation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Évaluation supprimée'
        ]);
    }

    public function publish(PublishEvaluationRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        // Vérifier que l'évaluation a des questions avant de publier
        if ($evaluation->questions()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de publier: l\'évaluation n\'a aucune question. Ajoutez des questions avant de publier.'
            ], 422);
        }

        // Vérifier que l'évaluation n'est pas déjà terminée
        if ($evaluation->isTerminee()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de publier: l\'évaluation est déjà terminée.'
            ], 422);
        }

        // Utiliser la méthode publish() du model pour synchroniser status et is_published
        $evaluation->publish();

        return response()->json([
            'success' => true,
            'message' => 'Évaluation publiée',
            'data' => $evaluation->fresh()
        ]);
    }
}

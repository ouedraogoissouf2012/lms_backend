<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\EvaluationAnswer;
use Illuminate\Support\Facades\DB;

class EvaluationTestDataSeeder extends Seeder
{
    /**
     * Seed des données de test pour la fonctionnalité Résultats Évaluations
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            echo "🌱 Création des données de test pour Résultats Évaluations...\n\n";

            // 1. Créer une évaluation de test
            echo "📝 Création de l'évaluation de test...\n";
            $evaluation = Evaluation::firstOrCreate(
                ['titre' => 'Test Mathématiques - Algèbre'],
                [
                    'description' => 'Évaluation de test pour valider la fonctionnalité Résultats',
                    'klassci_classe_id' => 1,
                    'klassci_matiere_id' => 1,
                    'klassci_enseignant_id' => 1,
                    'bareme' => 20,
                    'duree_minutes' => 60,
                    'date_evaluation' => now()->subDays(3),
                    'max_attempts' => 1,
                    'shuffle_questions' => true,
                    'show_results' => true,
                    'is_published' => true,
                    'type' => 'qcm',
                    'status' => 'active',
                ]
            );
            echo "   ✅ Évaluation créée: ID {$evaluation->id}\n\n";

            // 2. Créer des questions
            echo "❓ Création des questions...\n";
            $questions = [
                [
                    'question' => 'Résoudre: 2x + 5 = 15',
                    'type' => 'qcm',
                    'points' => 5,
                    'options' => ['x = 5', 'x = 10', 'x = 15', 'x = 20'],
                    'reponse_correcte' => 'x = 5'
                ],
                [
                    'question' => 'Calculer: (3 + 5) × 2',
                    'type' => 'qcm',
                    'points' => 3,
                    'options' => ['8', '16', '10', '13'],
                    'reponse_correcte' => '16'
                ],
                [
                    'question' => 'Simplifier: 12/16',
                    'type' => 'qcm',
                    'points' => 4,
                    'options' => ['3/4', '6/8', '1/2', '2/3'],
                    'reponse_correcte' => '3/4'
                ],
                [
                    'question' => 'Résoudre: x² = 25',
                    'type' => 'qcm',
                    'points' => 5,
                    'options' => ['x = 5', 'x = -5', 'x = ±5', 'x = 12.5'],
                    'reponse_correcte' => 'x = ±5'
                ],
                [
                    'question' => 'Calculer: 15% de 80',
                    'type' => 'qcm',
                    'points' => 3,
                    'options' => ['10', '12', '15', '20'],
                    'reponse_correcte' => '12'
                ],
            ];

            foreach ($questions as $index => $questionData) {
                $question = EvaluationQuestion::firstOrCreate(
                    [
                        'evaluation_id' => $evaluation->id,
                        'question' => $questionData['question']
                    ],
                    [
                        'type' => $questionData['type'],
                        'points' => $questionData['points'],
                        'ordre' => $index + 1,
                        'options' => json_encode($questionData['options']),
                        'reponse_correcte' => $questionData['reponse_correcte'],
                        'explication' => 'Explication de la réponse correcte'
                    ]
                );
                $num = $index + 1;
                echo "   ✅ Question {$num} créée: {$question->points} points\n";
            }
            echo "\n";

            // 3. Créer des soumissions de test
            echo "📤 Création des soumissions d'étudiants...\n";

            // Simuler 10 étudiants
            $etudiants = [
                ['id' => 1, 'nom' => 'Dupont', 'prenom' => 'Marie', 'score_pct' => 95],
                ['id' => 2, 'nom' => 'Martin', 'prenom' => 'Jean', 'score_pct' => 85],
                ['id' => 3, 'nom' => 'Bernard', 'prenom' => 'Sophie', 'score_pct' => 75],
                ['id' => 4, 'nom' => 'Dubois', 'prenom' => 'Pierre', 'score_pct' => 90],
                ['id' => 5, 'nom' => 'Thomas', 'prenom' => 'Julie', 'score_pct' => 65],
                ['id' => 6, 'nom' => 'Robert', 'prenom' => 'Lucas', 'score_pct' => 80],
                ['id' => 7, 'nom' => 'Petit', 'prenom' => 'Emma', 'score_pct' => 70],
                ['id' => 8, 'nom' => 'Richard', 'prenom' => 'Tom', 'score_pct' => 55],
                // 2 étudiants n'ont pas encore soumis (pour tester le cas "non passée")
            ];

            $totalPoints = collect($questions)->sum(function($q) {
                return $q['points'];
            });

            foreach ($etudiants as $etudiant) {
                $score = ($etudiant['score_pct'] / 100) * $totalPoints;
                $noteSur20 = ($score / $totalPoints) * 20;

                $submission = EvaluationSubmission::firstOrCreate(
                    [
                        'evaluation_id' => $evaluation->id,
                        'klassci_etudiant_id' => $etudiant['id']
                    ],
                    [
                        'score' => $score,
                        'note_sur_20' => round($noteSur20, 2),
                        'duree_secondes' => rand(1800, 3600),
                        'status' => 'soumis',
                        'attempt' => 1,
                        'submitted_at' => now()->subDays(rand(1, 5)),
                    ]
                );

                echo "   ✅ Soumission créée: {$etudiant['prenom']} {$etudiant['nom']} - {$submission->note_sur_20}/20\n";

                // Créer les réponses pour chaque question
                foreach ($questions as $index => $questionData) {
                    $question = EvaluationQuestion::where('evaluation_id', $evaluation->id)
                        ->where('question', $questionData['question'])
                        ->first();

                    // Simuler des bonnes/mauvaises réponses selon le score
                    $isCorrect = (rand(1, 100) <= $etudiant['score_pct']);
                    $reponse = $isCorrect ? $questionData['reponse_correcte'] : $questionData['options'][0];
                    $points = $isCorrect ? $questionData['points'] : 0;

                    EvaluationAnswer::firstOrCreate(
                        [
                            'submission_id' => $submission->id,
                            'question_id' => $question->id,
                        ],
                        [
                            'reponse' => $reponse,
                            'points_obtenus' => $points,
                            'est_correcte' => $isCorrect,
                        ]
                    );
                }
            }

            DB::commit();

            echo "\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "✅ DONNÉES DE TEST CRÉÉES AVEC SUCCÈS!\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "📊 Résumé:\n";
            echo "   - 1 Évaluation (ID: {$evaluation->id})\n";
            echo "   - " . count($questions) . " Questions\n";
            echo "   - " . count($etudiants) . " Soumissions\n";
            echo "   - 2 Étudiants sans soumission (pour tester 'Non passée')\n";
            echo "═══════════════════════════════════════════════════════════════\n";

        } catch (\Exception $e) {
            DB::rollBack();
            echo "\n❌ ERREUR: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

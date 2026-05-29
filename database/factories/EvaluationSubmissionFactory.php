<?php

namespace Database\Factories;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EvaluationSubmissionFactory extends Factory
{
    protected $model = EvaluationSubmission::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'student_id' => User::factory(),
            'klassci_etudiant_id' => $this->faker->numberBetween(1000, 9999),
            'attempt' => 1,
            'status' => 'soumis',
            'started_at' => now()->subHours(1),
            'submitted_at' => now(),
            'answers' => [],
            'score' => $this->faker->numberBetween(0, 100),
            'note_sur_20' => $this->faker->numberBetween(0, 20),
            'feedback' => null,
            'synced_to_klassci' => false,
            // institution_id hérité du parent Evaluation : sans ça, le global scope
            // BelongsToInstitution filtre la submission hors de son évaluation parente.
            'institution_id' => fn (array $attrs) => Evaluation::find($attrs['evaluation_id'])?->institution_id
                ?? \App\Models\Institution::factory(),
        ];
    }
}

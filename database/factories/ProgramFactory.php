<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
final class ProgramFactory extends Factory
{
    protected $model = Program::class;

    /**
     * `institution_id` est posé EXPLICITEMENT : la colonne est NOT NULL sur ces
     * tables neuves, et `BelongsToInstitution` ne la remplit que si un tenant
     * est résolu — ce qui n'est pas le cas dans la plupart des tests.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'titre' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'version' => 1,
        ];
    }
}

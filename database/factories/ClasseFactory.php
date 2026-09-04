<?php

namespace Database\Factories;

use App\Models\Institution;
use Database\Factories\Concerns\MintsKlassciIdentifiers;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClasseFactory extends Factory
{
    use MintsKlassciIdentifiers;

    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'libelle' => fake()->unique()->bothify('Classe ??#'),
            'klassci_id' => $this->mintKlassciId('classe'),
            'code' => fake()->unique()->bothify('CLS-???-###'),
            'effectif' => fake()->numberBetween(20, 50),
        ];
    }
}

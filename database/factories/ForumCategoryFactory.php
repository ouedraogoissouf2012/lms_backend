<?php

namespace Database\Factories;

use App\Models\ForumCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ForumCategory>
 */
class ForumCategoryFactory extends Factory
{
    protected $model = ForumCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'order' => $this->faker->numberBetween(1, 100),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_name' => $this->faker->word() . '.pdf',
            'stored_name' => $this->faker->uuid() . '.pdf',
            'path' => 'documents/' . $this->faker->uuid() . '.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => $this->faker->numberBetween(100000, 5000000),
            'type' => 'document',
            'category' => $this->faker->randomElement(['course_material', 'assignment', 'resource', 'other']),
            'description' => $this->faker->sentence(),
            'downloads_count' => 0,
            'is_public' => $this->faker->boolean(),
            'is_validated' => true,
            'virus_scan_status' => 'clean',
        ];
    }
}

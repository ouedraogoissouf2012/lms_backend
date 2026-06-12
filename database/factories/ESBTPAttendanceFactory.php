<?php

namespace Database\Factories;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ESBTPAttendance>
 */
class ESBTPAttendanceFactory extends Factory
{
    protected $model = ESBTPAttendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seance_id' => Seance::factory(),
            'user_id' => User::factory(),
            'klassci_etudiant_id' => $this->faker->numberBetween(1, 9999),
            'nom' => $this->faker->lastName(),
            'prenom' => $this->faker->firstName(),
            'email' => $this->faker->safeEmail(),
            'joined_at' => now()->subMinutes(30),
            'last_seen_at' => now(),
            'status' => 'connected',
            'is_validated' => false,
            'is_observer' => false,
            'institution_id' => fn (array $attrs) => Seance::find($attrs['seance_id'])?->institution_id
                ?? Institution::factory(),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (): array => ['status' => 'connected']);
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'status' => 'disconnected',
            'left_at' => now(),
        ]);
    }
}

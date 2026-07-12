<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            InstitutionSeeder::class,
            TestUsersSeeder::class,
            DemoDataSeeder::class,
            EvaluationTestDataSeeder::class,
        ]);

        $institution = Institution::where('slug', 'presentation')->first();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'role' => 'etudiant',
                'institution_id' => $institution?->id,
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Créer des utilisateurs de test pour le développement
     */
    public function run(): void
    {
        // Étudiant de test
        User::firstOrCreate(
            ['email' => 'etudiant@test.com'],
            [
                'klassci_id' => 'TEST_STUDENT_001',
                'name' => 'Étudiant Test',
                'password' => Hash::make('password'),
                'role' => 'étudiant',
            ]
        );

        // Enseignant de test
        User::firstOrCreate(
            ['email' => 'enseignant@test.com'],
            [
                'klassci_id' => 'TEST_TEACHER_001',
                'name' => 'Enseignant Test',
                'password' => Hash::make('password'),
                'role' => 'enseignant',
            ]
        );

        // Coordinateur de test
        User::firstOrCreate(
            ['email' => 'coordinateur@test.com'],
            [
                'klassci_id' => 'TEST_COORDINATOR_001',
                'name' => 'Coordinateur Test',
                'password' => Hash::make('password'),
                'role' => 'coordinateur',
            ]
        );

        echo "✅ 3 utilisateurs de test créés :\n";
        echo "   - etudiant@test.com / password (Étudiant)\n";
        echo "   - enseignant@test.com / password (Enseignant)\n";
        echo "   - coordinateur@test.com / password (Coordinateur)\n";
    }
}

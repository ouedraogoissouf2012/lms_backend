<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Créer des utilisateurs de test pour le développement
     */
    public function run(): void
    {
        $institution = Institution::firstOrCreate(
            ['slug' => 'presentation'],
            [
                'name' => 'KLASSCI Présentation',
                'klassci_api_url' => env('KLASSCI_PRESENTATION_URL', 'http://presentation.klassci.com/api/lms'),
                'klassci_api_token_encrypted' => env('KLASSCI_PRESENTATION_TOKEN', env('KLASSCI_API_TOKEN')),
                'is_active' => true,
            ]
        );

        // Étudiant de test
        User::firstOrCreate(
            ['email' => 'etudiant@test.com'],
            [
                'klassci_id' => 100001,
                'name' => 'Étudiant Test',
                'password' => Hash::make('password'),
                'role' => 'etudiant',
                'institution_id' => $institution->id,
            ]
        );

        // Enseignant de test
        User::firstOrCreate(
            ['email' => 'enseignant@test.com'],
            [
                'klassci_id' => 100002,
                'klassci_enseignant_id' => 100002,
                'name' => 'Enseignant Test',
                'password' => Hash::make('password'),
                'role' => 'enseignant',
                'institution_id' => $institution->id,
            ]
        );

        // Enseignant principal utilisé pour les tests navigateur
        User::firstOrCreate(
            ['email' => 'prof.bede.test'],
            [
                'klassci_id' => 200001,
                'klassci_enseignant_id' => 200001,
                'name' => 'BEDE ABEL TEST',
                'password' => Hash::make('Coucou123@'),
                'role' => 'enseignant',
                'institution_id' => $institution->id,
            ]
        );

        // Coordinateur de test
        User::firstOrCreate(
            ['email' => 'coordinateur@test.com'],
            [
                'klassci_id' => 100003,
                'name' => 'Coordinateur Test',
                'password' => Hash::make('password'),
                'role' => 'coordinateur',
                'institution_id' => $institution->id,
            ]
        );

        echo "✅ 4 utilisateurs de test créés :\n";
        echo "   - etudiant@test.com / password (Étudiant)\n";
        echo "   - enseignant@test.com / password (Enseignant)\n";
        echo "   - prof.bede.test / Coucou123@ (Enseignant principal)\n";
        echo "   - coordinateur@test.com / password (Coordinateur)\n";
    }
}

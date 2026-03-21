<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SupradminSeeder extends Seeder
{
    public function run(): void
    {
        User::withoutGlobalScope('institution')->firstOrCreate(
            ['email' => 'admin@klassci.com'],
            [
                'name' => 'Supradmin',
                'password' => Hash::make('Klassci@2026!'),
                'role' => 'supradmin',
                'institution_id' => null,
            ]
        );

        $this->command->info('Compte supradmin créé : admin@klassci.com');
    }
}

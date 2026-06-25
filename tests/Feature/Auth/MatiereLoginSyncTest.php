<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\Matiere;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Déclenchement de la sync des matières au login (issue #258).
 *
 * Un enseignant/coordinateur qui se connecte doit voir ses matières KLASSCI
 * peuplées en local (validations `exists:matieres,id` + affichage du libellé).
 * Un étudiant ne déclenche PAS cette sync.
 *
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php (syncTeacherMatieres)
 * @see app/Services/MatiereSyncService.php
 */
final class MatiereLoginSyncTest extends TestCase
{
    use RefreshDatabase;

    private function synchronizer(): KlassciUserSynchronizer
    {
        return app(KlassciUserSynchronizer::class);
    }

    public function test_teacher_login_triggers_matiere_sync(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [
                ['id' => 11, 'code' => 'PHY', 'libelle' => 'Physique'],
                ['id' => 12, 'code' => 'CHM', 'libelle' => 'Chimie'],
            ],
        ], 200)]);

        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $this->synchronizer()->sync(
            ['id' => 7, 'nom' => 'PROF DUPONT', 'email' => 'prof@school.edu', 'role' => 'enseignant'],
            'teacher-token',
            'https://school-a.klassci.test',
            $institution,
        );

        $matieres = Matiere::withoutGlobalScope('institution')->where('institution_id', $institution->id);
        $this->assertSame(2, $matieres->count());
        $this->assertSame(
            'Physique',
            Matiere::withoutGlobalScope('institution')->where('klassci_id', 11)->value('libelle'),
        );
    }

    public function test_student_login_does_not_trigger_matiere_sync(): void
    {
        // L'étudiant déclenche me/dashboard (ici sans classe), jamais la sync matières.
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $this->synchronizer()->sync(
            ['id' => 3, 'nom' => 'ETU MARTIN', 'email' => 'etu@school.edu', 'role' => 'etudiant'],
            'student-token',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame(0, Matiere::withoutGlobalScope('institution')->count());
    }
}

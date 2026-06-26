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

    protected function setUp(): void
    {
        parent::setUp();

        // #270 — la sync matières résout l'URL de base via la config globale
        // (priorité 3, aucun user Sanctum authentifié pendant le login). Le garde
        // #270 exige désormais une URL valide AVANT toute requête ; en prod
        // `KLASSCI_API_URL` est toujours renseignée. On la configure donc ici pour
        // refléter cet environnement (auparavant l'URL vide était silencieusement
        // masquée par `Http::fake('*')`).
        config()->set('services.klassci.url', 'https://klassci.test');
        config()->set('services.klassci.token', 'system-token');
    }

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

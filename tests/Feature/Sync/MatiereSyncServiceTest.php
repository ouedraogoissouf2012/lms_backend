<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Institution;
use App\Models\Matiere;
use App\Services\KlassciProxyService;
use App\Services\MatiereSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sync des matières depuis KLASSCI (issue #258).
 *
 * Le modèle Matiere est explicitement conçu pour la sync KLASSCI (klassci_id,
 * klassci_data, last_klassci_sync, isKlassciDataFresh) mais aucun service ne
 * l'alimentait — la table restait vide en prod, cassant les validations
 * `exists:matieres,id` (Quiz/ForumTopic/UpdateLesson) et l'affichage du libellé.
 *
 * Ces tests verrouillent : (1) upsert idempotent, (2) isolation multi-tenant
 * (klassci_id non unique entre institutions), (3) capture des champs.
 *
 * @see app/Services/MatiereSyncService.php
 * @see app/Models/Matiere.php
 */
final class MatiereSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $matieres
     */
    private function fakeKlassci(array $matieres): void
    {
        $this->mock(KlassciProxyService::class, function ($mock) use ($matieres): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('user-token', 'matieres', 'GET')
                ->andReturn(['data' => $matieres]);
        });
    }

    private function service(): MatiereSyncService
    {
        return app(MatiereSyncService::class);
    }

    public function test_creates_matiere_with_all_fields_from_klassci(): void
    {
        $institution = Institution::factory()->create();

        $this->fakeKlassci([[
            'id' => 42,
            'code' => 'MATH101',
            'libelle' => 'Mathématiques',
            'description' => 'Algèbre et analyse',
            'coefficient' => 3,
            'credit' => 6,
            'filiere' => ['id' => 7],
            'niveau' => ['id' => 2],
            'semestre_id' => 1,
        ]]);

        $stats = $this->service()->syncUserMatieres('user-token', $institution->id);

        $this->assertSame(1, $stats['created']);

        $matiere = Matiere::withoutGlobalScope('institution')->where('klassci_id', 42)->first();
        $this->assertNotNull($matiere);
        $this->assertSame('MATH101', $matiere->code);
        $this->assertSame('Mathématiques', $matiere->libelle);
        $this->assertSame(3, $matiere->coefficient);
        $this->assertSame(6, $matiere->credit);
        $this->assertSame(7, $matiere->filiere_id);
        $this->assertSame(2, $matiere->niveau_id);
        $this->assertSame($institution->id, $matiere->institution_id);
        $this->assertNotNull($matiere->last_klassci_sync);
        $this->assertSame(42, $matiere->klassci_data['id']);
    }

    public function test_sync_is_idempotent_and_updates_existing(): void
    {
        $institution = Institution::factory()->create();

        $this->fakeKlassci([[
            'id' => 42, 'code' => 'MATH101', 'libelle' => 'Mathématiques',
        ]]);
        $this->service()->syncUserMatieres('user-token', $institution->id);

        // 2e sync avec libellé modifié → pas de doublon, mise à jour.
        $this->fakeKlassci([[
            'id' => 42, 'code' => 'MATH101', 'libelle' => 'Mathématiques avancées',
        ]]);
        $stats = $this->service()->syncUserMatieres('user-token', $institution->id);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, Matiere::withoutGlobalScope('institution')->where('klassci_id', 42)->count());
        $this->assertSame(
            'Mathématiques avancées',
            Matiere::withoutGlobalScope('institution')->where('klassci_id', 42)->value('libelle'),
        );
    }

    public function test_same_klassci_id_isolated_across_institutions(): void
    {
        $schoolA = Institution::factory()->create();
        $schoolB = Institution::factory()->create();

        $this->fakeKlassci([['id' => 5, 'libelle' => 'Matière A']]);
        $this->service()->syncUserMatieres('user-token', $schoolA->id);

        $this->fakeKlassci([['id' => 5, 'libelle' => 'Matière B']]);
        $this->service()->syncUserMatieres('user-token', $schoolB->id);

        // Même klassci_id=5 mais 2 lignes distinctes (1 par institution).
        $this->assertSame(2, Matiere::withoutGlobalScope('institution')->where('klassci_id', 5)->count());
        $this->assertSame(
            'Matière A',
            Matiere::withoutGlobalScope('institution')->where('institution_id', $schoolA->id)->where('klassci_id', 5)->value('libelle'),
        );
        $this->assertSame(
            'Matière B',
            Matiere::withoutGlobalScope('institution')->where('institution_id', $schoolB->id)->where('klassci_id', 5)->value('libelle'),
        );
    }
}

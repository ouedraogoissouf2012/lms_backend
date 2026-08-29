<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\SeanceCacheDataBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #474 — Contrat du collaborateur partagé SeanceCacheDataBuilder.
 *
 * Verrouille le mapping payload KLASSCI → cache local et les edge cases, pour
 * garantir que le job et le fetcher produisent des `Seance` identiques.
 */
final class SeanceCacheDataBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $institution = Institution::factory()->create();

        return User::factory()->for($institution)->create([
            'name' => 'Prof Test',
            'klassci_id' => 909,
        ]);
    }

    public function test_build_maps_full_payload(): void
    {
        $owner = $this->owner();

        $data = (new SeanceCacheDataBuilder)->build(
            [
                'id' => 42,
                'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:30:00Z'],
                'classe' => ['id' => 501, 'nom' => 'Terminale A'],
            ],
            ['id' => 10, 'nom' => 'Mathématiques'],
            $owner,
        );

        self::assertSame($owner->institution_id, $data['institution_id']);
        self::assertSame(10, $data['klassci_matiere_id']);
        self::assertSame(501, $data['klassci_classe_id']);
        self::assertSame(909, $data['klassci_enseignant_id']);
        self::assertSame('Prof Test', $data['enseignant_nom']);
        self::assertSame('Mathématiques', $data['matiere_nom']);
        self::assertSame('Mathématiques', $data['titre']);
        self::assertSame('Terminale A', $data['classe_nom']);
        self::assertInstanceOf(Carbon::class, $data['date_seance']);
        self::assertSame('2026-08-01 09:30:00', $data['date_seance']->format('Y-m-d H:i:s'));
    }

    public function test_build_falls_back_to_libelle_when_nom_absent(): void
    {
        $data = (new SeanceCacheDataBuilder)->build(
            ['id' => 1, 'programmation' => ['date' => '2026-08-01']],
            ['id' => 10, 'libelle' => 'Physique-Chimie'],
            $this->owner(),
        );

        self::assertSame('Physique-Chimie', $data['matiere_nom']);
        self::assertSame('Physique-Chimie', $data['titre']);
    }

    public function test_build_uses_date_only_when_heure_debut_missing(): void
    {
        $data = (new SeanceCacheDataBuilder)->build(
            ['id' => 1, 'programmation' => ['date' => '2026-08-01']],
            ['id' => 10, 'nom' => 'Maths'],
            $this->owner(),
        );

        self::assertInstanceOf(Carbon::class, $data['date_seance']);
        self::assertSame('2026-08-01 00:00:00', $data['date_seance']->format('Y-m-d H:i:s'));
    }

    public function test_build_returns_null_date_when_no_date(): void
    {
        $data = (new SeanceCacheDataBuilder)->build(
            ['id' => 1, 'programmation' => []],
            ['id' => 10, 'nom' => 'Maths'],
            $this->owner(),
        );

        self::assertNull($data['date_seance']);
    }

    public function test_build_tolerates_absent_classe(): void
    {
        $data = (new SeanceCacheDataBuilder)->build(
            ['id' => 1, 'programmation' => ['date' => '2026-08-01']],
            ['id' => 10, 'nom' => 'Maths'],
            $this->owner(),
        );

        self::assertNull($data['klassci_classe_id']);
        self::assertNull($data['classe_nom']);
    }

    public function test_apply_to_updates_only_non_null_fields_and_persists_once(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($institution)->create([
            'enseignant_nom' => 'Ancien Nom',
            'classe_nom' => 'Ancienne Classe',
        ]);

        (new SeanceCacheDataBuilder)->applyTo($seance, [
            'enseignant_nom' => 'Nouveau Nom',
            'classe_nom' => null, // null → ne doit PAS écraser l'existant
        ]);

        $seance->refresh();
        self::assertSame('Nouveau Nom', $seance->enseignant_nom);
        self::assertSame('Ancienne Classe', $seance->classe_nom);
    }

    public function test_apply_to_is_noop_when_all_fields_null(): void
    {
        $institution = Institution::factory()->create();
        $seance = Seance::factory()->forInstitution($institution)->create(['enseignant_nom' => 'Inchangé']);
        $originalUpdatedAt = $seance->updated_at;

        (new SeanceCacheDataBuilder)->applyTo($seance, ['enseignant_nom' => null, 'classe_nom' => null]);

        $seance->refresh();
        self::assertSame('Inchangé', $seance->enseignant_nom);
        self::assertEquals($originalUpdatedAt, $seance->updated_at);
    }
}

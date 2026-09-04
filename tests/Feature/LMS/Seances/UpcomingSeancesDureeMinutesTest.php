<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Issue #487 — `duree_minutes` sur le listing « séances à venir ».
 *
 * Le calcul de durée dans `UpcomingSeancesFetcher::enrichWithVisio()` lisait des
 * clés racine (`date_seance`/`heure_debut`/`heure_fin`) que le mapper ne produit
 * jamais (il n'émet que `programmation.*`) → `duree_minutes` était code mort.
 *
 * Ces tests figent le contrat corrigé : la durée est calculée depuis
 * `programmation.heure_debut/heure_fin`, cohérent avec `SeanceDetailQueryService`.
 *
 * Chemin testé : NON-manager (enseignant) → walk KLASSCI → `enrichWithVisio`.
 * Le chemin manager (coordinateur/admin) court-circuite avant et n'est pas
 * concerné (hors scope, cf. requirements §Portée).
 *
 * @see app/Services/Seances/UpcomingSeancesFetcher.php
 * @see .claude/specs/upcoming-seances-duree-minutes/
 */
final class UpcomingSeancesDureeMinutesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'school-duree']);
    }

    private function teacher(): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_id' => 555,
            'klassci_token' => 'fake-token',
        ]);
    }

    /**
     * Monte un mock KLASSCI qui expose UNE matière (id 11) avec UNE séance
     * programmée (id 900) dont la programmation porte les heures fournies.
     *
     * @param  array<string, mixed>  $programmation
     */
    private function mockKlassciWithSeance(array $programmation): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($programmation): void {
            // Source = dashboard de l'enseignant, plus le catalogue global
            // (§1.4 : `GET matieres` renvoyait les 452 matieres du tenant).
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [['id' => 11, 'nom' => 'Maths', 'code' => 'MAT']]]]);

            $mock->shouldReceive('fetchManyMatieresDetails')
                ->andReturn([
                    11 => ['data' => ['seances_programmees' => [[
                        'id' => 900,
                        'programmation' => $programmation,
                        'classe' => ['id' => 44, 'nom' => 'Classe 44'],
                    ]]]],
                ]);
        });
    }

    public function test_upcoming_listing_exposes_duree_minutes_from_programmation(): void
    {
        Sanctum::actingAs($this->teacher());

        $date = now()->addDays(2)->toDateString();
        $this->mockKlassciWithSeance([
            'date' => $date,
            'heure_debut' => $date . 'T08:00:00',
            'heure_fin' => $date . 'T09:30:00',
        ]);

        $response = $this->getJson('/api/lms/seances/upcoming?days=30');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(900, $data[0]['id']);
        // 08:00 → 09:30 = 90 minutes (REQ-1, REQ-3).
        $this->assertSame(90, $data[0]['duree_minutes']);
    }

    public function test_upcoming_listing_omits_duree_minutes_when_heure_missing(): void
    {
        Sanctum::actingAs($this->teacher());

        $date = now()->addDays(2)->toDateString();
        // heure_fin absente → durée non calculable → clé absente, pas d'exception.
        $this->mockKlassciWithSeance([
            'date' => $date,
            'heure_debut' => $date . 'T08:00:00',
        ]);

        $response = $this->getJson('/api/lms/seances/upcoming?days=30');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertArrayNotHasKey('duree_minutes', $data[0]); // REQ-2
    }

    public function test_upcoming_listing_preserves_other_contract_keys(): void
    {
        Sanctum::actingAs($this->teacher());

        $date = now()->addDays(2)->toDateString();
        $this->mockKlassciWithSeance([
            'date' => $date,
            'heure_debut' => $date . 'T08:00:00',
            'heure_fin' => $date . 'T09:30:00',
        ]);

        $data = $this->getJson('/api/lms/seances/upcoming?days=30')->json('data');

        // REQ-4 : aucune régression des clés de contrat existantes.
        $this->assertSame($date, $data[0]['programmation']['date']);
        $this->assertSame('Maths', $data[0]['matiere']['libelle']);
        $this->assertSame('Classe 44', $data[0]['classe']['libelle']);
        $this->assertArrayHasKey('visio_enabled', $data[0]);
        $this->assertFalse($data[0]['visio_enabled']);
    }
}

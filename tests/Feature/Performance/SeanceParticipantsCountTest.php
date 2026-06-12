<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de performance — compteur de participants Seance (#224).
 *
 * L'accessor `current_participants_count` exécutait 1 COUNT par séance
 * sérialisée (N+1 sur les listes). Le scope `withConnectedParticipantsCount()`
 * précharge le compteur en 1 sous-requête. Ces tests prouvent :
 *   - le compteur préchargé est correct (0 requête supplémentaire) ;
 *   - une liste de N séances ne déclenche PAS N COUNT ;
 *   - l'accès unitaire sans scope garde un fallback correct.
 *
 * @see app/Models/Seance.php::getCurrentParticipantsCountAttribute
 * @see app/Models/Seance.php::scopeWithConnectedParticipantsCount
 */
final class SeanceParticipantsCountTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function seanceWithParticipants(int $connected, int $disconnected): Seance
    {
        $seance = Seance::factory()->create([
            'institution_id' => $this->institution->id,
            'visio_enabled' => true,
        ]);

        ESBTPAttendance::factory()->count($connected)->create([
            'seance_id' => $seance->id,
            'institution_id' => $this->institution->id,
            'status' => 'connected',
        ]);
        ESBTPAttendance::factory()->count($disconnected)->create([
            'seance_id' => $seance->id,
            'institution_id' => $this->institution->id,
            'status' => 'disconnected',
        ]);

        return $seance;
    }

    public function test_preloaded_count_is_correct_and_adds_no_query(): void
    {
        $this->seanceWithParticipants(connected: 3, disconnected: 2);

        $seance = Seance::withConnectedParticipantsCount()->first();

        // Le compteur préchargé est exact (seuls les `connected`).
        DB::enableQueryLog();
        $count = $seance->current_participants_count;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(3, $count);
        $this->assertCount(
            0,
            $queries,
            'Le compteur préchargé ne doit déclencher AUCUNE requête supplémentaire'
        );
    }

    public function test_list_of_seances_does_not_trigger_n_plus_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seanceWithParticipants(connected: 2, disconnected: 1);
        }

        // Chargement de la liste avec le scope = 2 requêtes au total
        // (le SELECT + la sous-requête de count intégrée), pas 1 + N COUNT.
        $seances = Seance::withConnectedParticipantsCount()->get();

        DB::enableQueryLog();
        $total = $seances->sum(fn (Seance $s): int => $s->current_participants_count);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(10, $total); // 5 séances × 2 connectés
        $this->assertCount(
            0,
            $queries,
            'Sérialiser 5 séances préchargées ne doit déclencher AUCUN COUNT (N+1 éliminé)'
        );
    }

    public function test_unit_access_without_scope_falls_back_correctly(): void
    {
        $seance = $this->seanceWithParticipants(connected: 4, disconnected: 0);

        // Accès unitaire sans le scope : fallback en 1 COUNT, valeur correcte.
        $fresh = Seance::find($seance->id);

        $this->assertSame(4, $fresh->current_participants_count);
    }

    public function test_json_contract_field_is_preserved(): void
    {
        $seance = $this->seanceWithParticipants(connected: 1, disconnected: 0);

        // Le champ reste exposé dans la sérialisation JSON (contrat frontend).
        $array = Seance::withConnectedParticipantsCount()->find($seance->id)->toArray();

        $this->assertArrayHasKey('current_participants_count', $array);
        $this->assertSame(1, $array['current_participants_count']);
    }
}

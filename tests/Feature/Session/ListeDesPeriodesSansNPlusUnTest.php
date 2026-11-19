<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1206 — la liste des Périodes rend le titre de chaque Programme SANS une requête par
 * ligne. Le Programme est chargé en une requête pour toute la page : le nombre de
 * requêtes d'une page de 6 Périodes sur 6 Programmes distincts est celui d'une page
 * d'une seule Période. Un N+1 le ferait croître de 5.
 *
 * Compté par `DB::listen`, sur la requête HTTP réelle (vrai jeton), pagination
 * comprise.
 */
final class ListeDesPeriodesSansNPlusUnTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_nombre_de_requetes_ne_depend_pas_du_nombre_de_periodes(): void
    {
        $uneSeule = $this->requetesPourUnePageDe(1);
        $six = $this->requetesPourUnePageDe(6);

        self::assertSame($uneSeule, $six, "1 Période : {$uneSeule} requêtes ; 6 Périodes : {$six}.");
    }

    private function requetesPourUnePageDe(int $periodes): int
    {
        $ecole = Institution::factory()->standalone()->create();
        $responsable = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
        for ($i = 0; $i < $periodes; $i++) {
            $programme = Program::factory()->create(['institution_id' => $ecole->id]);
            TrainingSession::factory()->create(['institution_id' => $ecole->id, 'program_id' => $programme->id]);
        }
        $jeton = $responsable->createToken('n-plus-un-1206')->plainTextToken;

        // Une requête de chauffe : les caches (établissement, jeton) sont chauds dans les
        // deux mesures, et seule la page elle-même est comptée.
        $this->withToken($jeton)->getJson('/api/training-sessions')->assertOk();
        $this->app['auth']->forgetGuards();

        $compte = 0;
        DB::listen(static function () use (&$compte): void {
            $compte++;
        });

        $reponse = $this->withToken($jeton)->getJson('/api/training-sessions')->assertOk();
        self::assertCount($periodes, (array) $reponse->json('data'));
        self::assertNotNull($reponse->json('data.0.program.titre'));

        // `DB::listen` ne se retire pas : on garde la valeur au moment de la mesure.
        return $compte;
    }
}

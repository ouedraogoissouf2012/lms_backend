<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1206 — la liste des Périodes rend le titre de chaque Programme SANS une requête par
 * ligne. Le Programme est chargé en une requête pour toute la page : les LECTURES d'une
 * page de 6 Périodes sur 6 Programmes distincts sont celles d'une page d'une seule
 * Période. Un N+1 y ajouterait 5 `select … from programs`.
 *
 * Relevé par `DB::listen`, sur la requête HTTP réelle (vrai jeton), pagination comprise.
 *
 * ## Pourquoi les seules lectures, et l'horloge figée
 *
 * Mesuré en CI (#1206) : sans ces deux précautions, le total différait sans aucun N+1.
 * Sanctum n'écrit `last_used_at` que si la seconde a changé depuis la requête de chauffe,
 * et le cache en base ajoutait deux `insert` selon le moment : des ÉCRITURES
 * d'infrastructure, dépendantes de l'horloge, étrangères à la page. Les listes `in (…)`
 * portent les identifiants en ligne : elles sont normalisées.
 */
final class ListeDesPeriodesSansNPlusUnTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_nombre_de_requetes_ne_depend_pas_du_nombre_de_periodes(): void
    {
        $uneSeule = $this->lectures($this->requetesPourUnePageDe(1));
        $six = $this->lectures($this->requetesPourUnePageDe(6));

        self::assertSame(
            $uneSeule,
            $six,
            sprintf("1 Période : %d lectures ; 6 Périodes : %d.\nEn plus avec 6 : %s\nEn moins avec 6 : %s",
                count($uneSeule), count($six), $this->ecart($six, $uneSeule), $this->ecart($uneSeule, $six)),
        );
    }

    /**
     * Les `select`, listes `in (…)` normalisées, triés : la forme des lectures de la page.
     *
     * @param  list<string>  $requetes
     * @return list<string>
     */
    private function lectures(array $requetes): array
    {
        $lectures = array_values(array_map(
            static fn (string $sql): string => (string) preg_replace('/ in \([^)]*\)/i', ' in (…)', $sql),
            array_filter($requetes, static fn (string $sql): bool => stripos(ltrim($sql), 'select') === 0),
        ));
        sort($lectures);

        return $lectures;
    }

    /**
     * Les requêtes de `$a` absentes de `$b`, multiplicité comprise : c'est ce qui dit
     * QUOI diffère quand les nombres diffèrent.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function ecart(array $a, array $b): string
    {
        $reste = array_count_values($b);
        $ecart = [];
        foreach ($a as $sql) {
            if (($reste[$sql] ?? 0) > 0) {
                $reste[$sql]--;
            } else {
                $ecart[] = $sql;
            }
        }

        return $ecart === [] ? '(rien)' : implode(' | ', $ecart);
    }

    /** @return list<string> le SQL de chaque requête de la page, dans l'ordre */
    private function requetesPourUnePageDe(int $periodes): array
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
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

        $requetes = [];
        DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $reponse = $this->withToken($jeton)->getJson('/api/training-sessions')->assertOk();
        self::assertCount($periodes, (array) $reponse->json('data'));
        self::assertNotNull($reponse->json('data.0.program.titre'));

        // `DB::listen` ne se retire pas : on garde la liste au moment de la mesure.
        return $requetes;
    }
}

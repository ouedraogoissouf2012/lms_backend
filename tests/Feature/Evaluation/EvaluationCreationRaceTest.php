<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Institution;
use App\Models\User;
use App\Services\Evaluation\EvaluationCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * #541 — la course que l'index unique tranche désormais doit rester un 409.
 *
 * `EvaluationCreationService::create()` garde le conflit par un `SELECT` exécuté
 * hors transaction : deux requêtes simultanées le franchissent toutes les deux.
 * Depuis #541, la perdante est rejetée par `evaluations_klassci_link_unique` —
 * et sans traduction explicite, ce rejet ressortait en 500 opaque alors que
 * l'endpoint définit déjà le 409 « une version en ligne existe déjà ».
 */
#[CoversClass(EvaluationCreationService::class)]
final class EvaluationCreationRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_losing_the_creation_race_returns_409_not_500(): void
    {
        $institution = Institution::factory()->create();
        $teacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
            'klassci_enseignant_id' => 77,
        ]);

        // Reproduit la course : la ligne concurrente apparaît APRÈS le garde
        // applicatif, juste avant notre insertion.
        $raced = false;
        // Le motif accepte les DEUX quotings d'identifiant : `"..."` sous SQLite,
        // backticks sous MySQL. Un motif mono-moteur passerait vert sur une jambe
        // et laisserait l'autre sans aucune couverture de la course.
        DB::beforeExecuting(function (string $query) use (&$raced, $institution): void {
            if ($raced || preg_match('/insert into [`"]evaluations[`"]/i', $query) !== 1) {
                return;
            }

            $raced = true;
            DB::table('evaluations')->insert([
                'institution_id' => $institution->id,
                'klassci_evaluation_id' => 4242,
                'klassci_matiere_id' => 1,
                'klassci_classe_id' => 1,
                'titre' => 'Créée par la requête concurrente',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $result = app(EvaluationCreationService::class)->create([
            'klassci_evaluation_id' => 4242,
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Perdante de la course',
        ], $teacher);

        self::assertTrue($raced, 'La course doit avoir été déclenchée.');
        self::assertSame(409, $result['status']);
        self::assertFalse($result['payload']['success']);
        self::assertStringContainsString('existe déjà', (string) $result['payload']['message']);

        // La perdante n'a rien persisté. On ne compte pas les lignes totales : la
        // requête « concurrente » est ici simulée SUR LA MÊME connexion, donc son
        // insertion vit dans la transaction que le service annule — un vrai
        // concurrent aurait la sienne. Ce que ce test verrouille, c'est le CODE de
        // retour, pas la comptabilité d'une course à une seule connexion.
        self::assertSame(
            0,
            DB::table('evaluations')->where('titre', 'Perdante de la course')->count(),
            'La requête perdante ne doit rien avoir persisté.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Factories;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use Database\Factories\SeanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les fixtures ne doivent jamais empiéter sur les identifiants écrits à la main
 * par les tests (#682).
 *
 * ## Le défaut corrigé
 *
 * Les factories tiraient au hasard **dans les plages que les tests utilisent
 * aussi en dur** (`1..10000`, et même `1000..9999` pour les séances) :
 *
 * ```
 * UNIQUE constraint failed: users.klassci_id, users.institution_id
 * ```
 *
 * `unique()` de Faker ne garantit l'unicité qu'entre les valeurs qu'il génère
 * lui-même ; il ignore tout de ce que les tests écrivent. Et comme Faker part
 * d'une graine déterministe, **ajouter un test n'importe où décale la séquence
 * entière** : le défaut restait invisible tant qu'on ne touchait à rien, puis
 * tombait sur une PR sans rapport, sur une seule jambe CI.
 *
 * ## Ce que ce fichier verrouille
 *
 * Un test peut coder `'klassci_id' => 100` sans se demander si une factory
 * voisine va tomber dessus. C'est la promesse ; ces tests la rendent
 * falsifiable, y compris pour une factory écrite demain.
 */
final class KlassciIdentifierMintingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Plus haute valeur relevée dans `tests/` au moment de #682 (`200002`),
     * arrondie largement au-dessus. Tout identifiant frappé par une factory doit
     * rester strictement au-dessus.
     */
    private const AU_DESSUS_DE_TOUT_CE_QUI_EST_ECRIT_A_LA_MAIN = 900_000_000;

    public function test_every_factory_mints_identifiers_out_of_reach_of_tests(): void
    {
        $institution = Institution::factory()->create();

        $releves = [
            'users.klassci_id' => User::factory()->for($institution)->create()->klassci_id,
            'users.klassci_enseignant_id' => User::factory()->for($institution)->create()->klassci_enseignant_id,
            'classes.klassci_id' => Classe::factory()->for($institution)->create()->klassci_id,
            'matieres.klassci_id' => Matiere::factory()->for($institution)->create()->klassci_id,
            'seances.klassci_seance_id' => Seance::factory()->for($institution)->create()->klassci_seance_id,
            'evaluations.klassci_evaluation_id' => Evaluation::factory()->for($institution)->create()->klassci_evaluation_id,
        ];

        foreach ($releves as $colonne => $valeur) {
            self::assertGreaterThan(
                self::AU_DESSUS_DE_TOUT_CE_QUI_EST_ECRIT_A_LA_MAIN,
                (int) $valeur,
                "{$colonne} est frappé dans une plage que les tests utilisent a la main : collision aleatoire garantie a terme.",
            );
        }
    }

    /**
     * LE scénario qui faisait echouer la CI au hasard : un fichier qui mélange
     * identifiants en dur et utilisateurs tirés par la factory, dans la même
     * institution — donc sous le même index unique.
     */
    public function test_hardcoded_identifiers_coexist_with_generated_ones(): void
    {
        $institution = Institution::factory()->create();

        User::factory()->for($institution)->create(['klassci_id' => 100]);
        User::factory()->for($institution)->create(['klassci_id' => 101]);

        // Une centaine de tirages : sous l'ancien regime, 100 chances sur 10 000
        // de heurter l'une des deux valeurs ci-dessus a chaque execution.
        User::factory()->for($institution)->count(100)->create();

        self::assertSame(102, User::where('institution_id', $institution->id)->count());
    }

    /**
     * Les espaces sont disjoints, donc un appariement fortuit est impossible.
     *
     * Ce risque-la est plus sournois que la collision : `klassci_enseignant_id`
     * ne porte aucun index unique, donc une coïncidence ne leve **aucune
     * erreur** — elle ferait silencieusement PASSER une assertion d'autorisation
     * qui aurait du echouer.
     *
     * Un lien voulu reste evidemment possible : il s'ecrit explicitement, comme
     * dans {@see SeanceFactory::forTeacher()}.
     */
    public function test_a_seance_never_lands_by_chance_on_a_teacher(): void
    {
        $institution = Institution::factory()->create();

        $klassciIds = User::factory()->for($institution)->count(50)->create()
            ->pluck('klassci_id')->all();
        $enseignantIdsDeSeances = Seance::factory()->for($institution)->count(50)->create()
            ->pluck('klassci_enseignant_id')->all();

        self::assertSame(
            [],
            array_intersect($klassciIds, $enseignantIdsDeSeances),
            'Une seance est tombee par hasard sur un enseignant : une assertion d autorisation peut passer a tort.',
        );
    }

    /**
     * Garde structurelle, pour les factories ecrites demain.
     *
     * Sans elle, la correction de #682 ne survit qu'aussi longtemps que personne
     * ne recopie le motif voisin — et c'est precisement ainsi qu'il s'est
     * repandu sur sept factories.
     */
    public function test_no_factory_draws_a_klassci_identifier_at_random(): void
    {
        $coupables = [];

        foreach (glob(database_path('factories/*.php')) ?: [] as $fichier) {
            $source = (string) file_get_contents($fichier);

            if (preg_match('/[\'"]klassci_\w+[\'"]\s*=>[^,\n]*(numberBetween|randomNumber|randomDigit)/', $source) === 1) {
                $coupables[] = basename($fichier);
            }
        }

        self::assertSame(
            [],
            $coupables,
            'Ces factories tirent un identifiant KLASSCI au hasard (#682) : utiliser '
            .'`MintsKlassciIdentifiers::mintKlassciId()`, dont les plages sont hors de portee des tests.',
        );
    }
}

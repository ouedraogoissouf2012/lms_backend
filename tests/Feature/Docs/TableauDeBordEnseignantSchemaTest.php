<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Seance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Support\OpenApi\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * Le schéma du tableau de bord MORD sur la charge locale.
 *
 * `TableauDeBordEnseignant` est un `oneOf` : la charge KLASSCI, relayée telle
 * quelle et ouverte, sans `meta` ; la charge locale, fermée, marquée
 * `meta.source: lms_local`. La charge locale RÉELLE, écartée de sa forme d'une
 * seule façon, est refusée. La charge KLASSCI qui porterait la marque locale
 * l'est aussi : la marque départage les deux branches.
 *
 * Limite déclarée : une charge locale PRIVÉE de `meta` ressemble à une charge
 * KLASSCI, que la spec garde ouverte ; elle ne peut donc pas la refuser. Le test
 * de contrat épingle la présence de `meta` sur la réponse réelle.
 */
final class TableauDeBordEnseignantSchemaTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const URL = '/api/proxy/me/teacher-dashboard';

    private const CHEMIN = '/proxy/me/teacher-dashboard';

    private static ?OpenApiSchemaValidator $spec = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Carbon::setTestNow('2026-11-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: string}> */
    public static function ecarts(): iterable
    {
        yield 'une clé non décrite à la racine' => ['racine'];
        yield 'une clé non décrite dans data' => ['data'];
        yield 'une clé non décrite dans une classe' => ['classe'];
        yield 'une clé non décrite dans les heures' => ['heures'];
        yield 'des évaluations, que la source locale ne mesure pas' => ['evaluations'];
    }

    #[DataProvider('ecarts')]
    public function test_la_spec_refuse_une_charge_locale_ecartee_de_sa_forme(string $ecart): void
    {
        $charge = $this->chargeLocale();
        self::assertSame([], self::spec()->erreurs($charge, self::schema()), 'la charge locale réelle suit la spec');

        match ($ecart) {
            'racine' => $charge->cle_non_decrite = true,
            'data' => $charge->data->cle_non_decrite = true,
            'classe' => $charge->data->classes[0]->cle_non_decrite = true,
            'heures' => $charge->data->statistiques->heures->cle_non_decrite = true,
            'evaluations' => $charge->data->evaluations = [],
        };

        self::assertNotSame([], self::spec()->erreurs($charge, self::schema()), "la spec admet : $ecart");
    }

    public function test_la_spec_refuse_une_charge_klassci_marquee_locale(): void
    {
        $charge = json_decode((string) json_encode([
            'success' => true,
            'data' => [
                'matieres' => [['id' => 3, 'nom' => 'Anglais']],
                'classes' => [['id' => 4, 'name' => 'BTS Genie Civil', 'libelle' => 'BTS Genie Civil']],
                'evaluations' => [],
                'prochaines_seances' => [],
                'statistiques' => ['heures' => ['total_seances' => 105, 'seances_effectuees' => 0]],
            ],
        ]), false);
        self::assertInstanceOf(stdClass::class, $charge);
        self::assertSame([], self::spec()->erreurs($charge, self::schema()), 'la charge KLASSCI suit la spec');

        $charge->meta = (object) ['source' => 'lms_local'];

        self::assertNotSame([], self::spec()->erreurs($charge, self::schema()));
    }

    /** La réponse RÉELLE de la source locale : une classe, une matière, une séance à venir. */
    private function chargeLocale(): stdClass
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $this->programme($classe, $this->matiere($ecole, 'Maths'), $enseignant);
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');
        Seance::factory()->for($ecole)->create([
            'created_by' => $enseignant->id,
            'classe_id' => $classe->id,
            'date_seance' => '2026-11-11 08:00:00',
            'is_active' => true,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);

        $reponse = $this->asTenant($enseignant)->getJson(self::URL)->assertOk()->assertJsonPath('meta.source', 'lms_local');
        $charge = json_decode((string) $reponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $charge);
        self::assertNotEmpty($charge->data->classes);

        return $charge;
    }

    private static function spec(): OpenApiSchemaValidator
    {
        return self::$spec ??= OpenApiSchemaValidator::depuisFichier(base_path('docs/openapi.yaml'));
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $schema = self::spec()->schemaDeReponse(self::CHEMIN, 'get', 200);
        self::assertNotNull($schema);

        return $schema;
    }
}

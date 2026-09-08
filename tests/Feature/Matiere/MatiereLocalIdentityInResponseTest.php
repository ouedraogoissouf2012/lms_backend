<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * La réponse `/lms/matieres/{id}` doit porter l'identifiant LOCAL de la matière.
 *
 * ## Le défaut, observé EN PRODUCTION LOCALE le 2026-09-07
 *
 * L'enseignant ouvre `/lms/matieres/3` — un identifiant KLASSCI, celui de
 * « Anglais ». Le frontend construit ensuite le payload de création de leçon
 * avec `matiere_id: matiereId`, c'est-à-dire ce MÊME paramètre de route
 * (`lms-frontend/src/utils/matiereDetails.js:106`).
 *
 * Le backend reçoit donc `matiere_id = 3` dans l'espace KLASSCI, mais
 * `lessons.matiere_id` est une clé LOCALE, et la résolution d'un identifiant
 * entrant tranche — par construction — en faveur de l'espace local. Or la
 * matière LOCALE 3 existait : « Algorithme ».
 *
 * Mesure en base après le test manuel : la leçon #17 « TEST », créée depuis la
 * page Anglais, s'est retrouvée rattachée à **Algorithme**. En 201, sans la
 * moindre erreur, et invisible sur la page où elle venait d'être créée.
 *
 * Le même défaut existait sur `classe_id` et a été corrigé en rendant
 * `classes_concernees` dans l'espace local. `matiere_id`, lui, ne vient pas
 * d'une liste : il vient de la ROUTE. Il fallait donc que la réponse expose
 * elle-même l'identité locale de la matière.
 *
 * ## Pourquoi `null` plutôt qu'un repli sur l'identifiant KLASSCI
 *
 * Quand la matière n'est pas encore miroitée, le champ vaut `null` et le
 * frontend omet `matiere_id`. Émettre l'autre espace « pour dépanner »
 * recréerait exactement l'ambiguïté qu'on supprime. Une leçon sans matière est
 * réparable ; une leçon sur la MAUVAISE matière est une corruption
 * silencieuse — personne ne la cherche, puisque rien n'a échoué.
 */
#[CoversClass(MatiereDetailsQueryService::class)]
final class MatiereLocalIdentityInResponseTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_MATIERE_ID = 3;

    private const TOKEN = 'jeton-test';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * LE cas réel, reproduit : « Anglais » porte le `klassci_id` 3, et une
     * AUTRE matière occupe l'`id` local 3.
     */
    public function test_the_response_carries_the_local_identifier_not_the_klassci_one(): void
    {
        [$anglais, $piege] = $this->deuxMatieresEnCollision();

        $reponse = $this->fetchDetails();

        self::assertSame($anglais->id, $reponse['matiere_id_local'] ?? null);
        self::assertNotSame($piege->id, $reponse['matiere_id_local'] ?? null);
        self::assertNotSame(
            self::KLASSCI_MATIERE_ID,
            $reponse['matiere_id_local'] ?? null,
            'la réponse rend encore l\'espace KLASSCI',
        );
    }

    /**
     * Le bloc `matiere` reste le passthrough KLASSCI, intact : le champ est
     * ADDITIF, aucun consommateur existant n'est déplacé.
     */
    public function test_the_klassci_passthrough_block_is_left_untouched(): void
    {
        $this->deuxMatieresEnCollision();

        $reponse = $this->fetchDetails();

        self::assertSame(self::KLASSCI_MATIERE_ID, $reponse['matiere']['id']);
        self::assertSame('Anglais', $reponse['matiere']['nom']);
    }

    /**
     * Matière non miroitée : `null`, jamais l'identifiant de l'autre espace.
     */
    public function test_an_unmirrored_matiere_yields_null_not_the_klassci_id(): void
    {
        $reponse = $this->fetchDetails();

        // `??` ne distingue pas « clé absente » de « valeur nulle » : les deux
        // vérifications sont donc séparées. Le contrat exige que la clé soit
        // TOUJOURS présente — un frontend qui teste `'matiere_id_local' in data`
        // doit pouvoir s'y fier.
        self::assertArrayHasKey('matiere_id_local', $reponse);
        self::assertNull($reponse['matiere_id_local']);
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * @return array{0: Matiere, 1: Matiere}
     */
    private function deuxMatieresEnCollision(): array
    {
        $anglais = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => self::KLASSCI_MATIERE_ID,
            'libelle' => 'Anglais',
        ]);

        // Occupe l'id local égal au klassci_id visé — la collision réelle.
        $piege = Matiere::withoutGlobalScope('institution')->firstOrCreate(
            ['klassci_id' => 9002, 'institution_id' => $this->institution->id],
            ['libelle' => 'Algorithme'],
        );

        return [$anglais, $piege];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDetails(): array
    {
        $enseignant = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
            'klassci_token' => self::TOKEN,
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::TOKEN, 'matieres/'.self::KLASSCI_MATIERE_ID, 'GET')
                ->andReturn(['data' => [
                    'matiere' => ['id' => self::KLASSCI_MATIERE_ID, 'nom' => 'Anglais', 'code' => 'ID5356'],
                    'combinaisons' => [],
                    'enseignants' => [['id' => 700, 'nom' => 'Prof Test']],
                    'evaluations' => [],
                ]]);
        });

        $reponse = app(MatiereDetailsQueryService::class)
            ->getDetailsForUser(self::KLASSCI_MATIERE_ID, $enseignant);

        self::assertNotNull($reponse);

        return $reponse;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1629 (#832) : les synchronisations faites à la connexion, la classe de l'élève et les matières de
 * l'enseignant, visent l'URL de l'ÉCOLE du compte, jamais la configuration globale du serveur.
 *
 * Avant : pendant le login, aucun utilisateur n'était authentifié, et `KlassciConfigResolver`
 * retombait sur `services.klassci.url`. Vide en production, les deux synchronisations échouaient
 * (`missingBaseUrl`) : l'enseignant n'avait pas ses matières, l'élève pas sa classe. Posée, elle
 * aurait envoyé le jeton du compte au KLASSCI du serveur, et non à celui de son école.
 *
 * Vraie route `/api/auth/login`, KLASSCI simulé au niveau HTTP.
 */
final class LesSynchrosDuLoginVisentLEcoleTest extends TestCase
{
    use RefreshDatabase;

    private const ECOLE = 'https://ecole1629.klassci.test/api/lms';

    private const SERVEUR = 'https://serveur1629.klassci.test/api/lms';

    private const CLASSE = 4401;

    private const MATIERE = 8801;

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ecole = Institution::factory()->create([
            'slug' => 'ecole1629',
            'is_active' => true,
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::ECOLE,
        ]);
    }

    /** @return array<string, array{string}> La configuration globale : vide (la prod), ou un autre hôte. */
    public static function configurationsGlobales(): array
    {
        return ['config globale vide' => [''], 'config globale vers un autre hote' => [self::SERVEUR]];
    }

    #[DataProvider('configurationsGlobales')]
    public function test_la_classe_de_l_eleve_est_lue_chez_son_ecole(string $globale): void
    {
        config(['services.klassci.url' => $globale]);
        $this->klassci(['id' => 9201, 'email' => 'eleve1629@ecole.test', 'name' => 'Élève 1629', 'role' => 'etudiant']);

        $this->connecter('eleve.1629')->assertOk();

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), self::ECOLE.'/me/dashboard'));
        Http::assertNotSent(static fn (Request $r): bool => str_starts_with($r->url(), self::SERVEUR));
        self::assertSame([self::CLASSE], UserClass::query()->withoutGlobalScopes()->pluck('klassci_classe_id')->map(static fn ($id): int => (int) $id)->all());
    }

    #[DataProvider('configurationsGlobales')]
    public function test_les_matieres_de_l_enseignant_sont_lues_chez_son_ecole(string $globale): void
    {
        config(['services.klassci.url' => $globale]);
        $this->klassci(['id' => 9202, 'email' => 'prof1629@ecole.test', 'name' => 'Prof 1629', 'role' => 'enseignant']);

        $this->connecter('prof.1629')->assertOk();

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), self::ECOLE.'/matieres'));
        Http::assertNotSent(static fn (Request $r): bool => str_starts_with($r->url(), self::SERVEUR));
        self::assertTrue(
            Matiere::query()->withoutGlobalScopes()->where('institution_id', $this->ecole->id)->where('klassci_id', self::MATIERE)->exists(),
            'La matière KLASSCI de l\'enseignant est synchronisée dans son école.',
        );
    }

    /** @param  array<string, mixed>  $compte */
    private function klassci(array $compte): void
    {
        Http::fake(static function (Request $requete) use ($compte) {
            $url = $requete->url();

            return match (true) {
                str_ends_with($url, '/auth/check-user') => Http::response(['success' => true, 'data' => ['found' => true]], 200),
                str_ends_with($url, '/auth/login') => Http::response(['success' => true, 'data' => ['user' => $compte, 'token' => 'jeton-1629'], 'meta' => []], 200),
                str_contains($url, '/me/dashboard') => Http::response(['success' => true, 'data' => ['classe' => ['id' => self::CLASSE, 'name' => 'BTS 1629']]], 200),
                str_contains($url, '/matieres') => Http::response(['success' => true, 'data' => [['id' => self::MATIERE, 'nom' => 'Topographie']]], 200),
                default => Http::response(['success' => true, 'data' => []], 200),
            };
        });
    }

    private function connecter(string $identifiant): TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => $identifiant, 'password' => 'mot-de-passe-1629']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * La porte anonyme ne dit pas si un compte existe (#914).
 *
 * Un compte neuf, une adresse déjà prise, un numéro déjà pris : la MÊME réponse,
 * statut et corps entiers. Le compte existant n'est jamais touché, et son cas
 * paie le même coût de hachage que la création. Le message dit à chacun quoi
 * faire, sans dire lequel des deux il est.
 */
final class InscriptionSansOracleTest extends TestCase
{
    use PreparesClasseWithCode;
    use RefreshDatabase;

    private const URL = '/api/inscriptions';

    public function test_compte_neuf_adresse_prise_et_numero_pris_recoivent_la_meme_reponse(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => 'moussa@test.ci']);
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => null, 'phone' => '+22507000000']);
        $entete = ['X-Institution' => (string) $ecole->slug];

        $neuf = $this->postJson(self::URL, $this->charge($code, email: 'awa@test.ci'), $entete);
        $adressePrise = $this->postJson(self::URL, $this->charge($code, email: 'moussa@test.ci'), $entete);
        $numeroPris = $this->postJson(self::URL, $this->charge($code, telephone: '+22507000000'), $entete);

        $neuf->assertStatus(202);
        self::assertSame($neuf->status(), $adressePrise->status());
        self::assertSame($neuf->status(), $numeroPris->status());
        self::assertSame($neuf->json(), $adressePrise->json(), 'La réponse dit qu\'une adresse est déjà prise.');
        self::assertSame($neuf->json(), $numeroPris->json(), 'La réponse dit qu\'un numéro est déjà pris.');
    }

    public function test_le_compte_existant_n_est_ni_touche_ni_inscrit_ni_double(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        $existant = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'email' => 'moussa@test.ci',
            'password' => Hash::make('son-vrai-secret'),
        ]);

        $this->postJson(self::URL, $this->charge($code, email: 'moussa@test.ci'), ['X-Institution' => (string) $ecole->slug])
            ->assertStatus(202);

        self::assertTrue(Hash::check('son-vrai-secret', (string) $existant->fresh()?->password));
        self::assertSame(1, User::query()->withoutGlobalScopes()->where('email', 'moussa@test.ci')->count());
        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    /**
     * Le cas existant calcule une empreinte, comme la création : pas d'écart de hachage.
     *
     * Le service reçoit le contrat `Hasher`, alias de `hash.driver` (pas de `hash`) :
     * un hacheur témoin, lié à ce contrat, délègue au vrai et compte ses calculs.
     */
    public function test_le_compte_existant_paie_le_meme_cout_de_hachage(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        User::factory()->create(['institution_id' => $ecole->getKey(), 'email' => 'moussa@test.ci']);
        $temoin = $this->hacheurTemoin();

        $this->postJson(self::URL, $this->charge($code, email: 'moussa@test.ci'), ['X-Institution' => (string) $ecole->slug])
            ->assertStatus(202);

        self::assertSame(1, $temoin->empreintes, 'Le cas existant n\'a pas payé le hachage de la création.');
    }

    private function hacheurTemoin(): object
    {
        $temoin = new class(app(Hasher::class)) implements Hasher
        {
            public int $empreintes = 0;

            public function __construct(private readonly Hasher $vrai) {}

            /** @param  string  $hashedValue */
            public function info($hashedValue): array
            {
                return $this->vrai->info($hashedValue);
            }

            /** @param  array<string, mixed>  $options */
            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                $this->empreintes++;

                return $this->vrai->make($value, $options);
            }

            /** @param  array<string, mixed>  $options */
            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                return $this->vrai->check($value, $hashedValue, $options);
            }

            /** @param  array<string, mixed>  $options */
            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->vrai->needsRehash($hashedValue, $options);
            }
        };
        $this->app->instance(Hasher::class, $temoin);

        return $temoin;
    }

    /** @return array<string, string> */
    private function charge(string $code, ?string $email = null, ?string $telephone = null): array
    {
        return array_filter([
            'code' => $code,
            'nom' => 'Awa Kabore',
            'email' => $email,
            'telephone' => $telephone,
            'password' => 'mot-de-passe-choisi',
            'password_confirmation' => 'mot-de-passe-choisi',
        ], static fn (?string $valeur): bool => $valeur !== null);
    }
}

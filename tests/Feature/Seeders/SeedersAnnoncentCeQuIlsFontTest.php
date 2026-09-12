<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Institution;
use App\Models\User;
use Database\Seeders\InstitutionSeeder;
use Database\Seeders\SupradminSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * #688 — un seeder ne doit jamais annoncer ce qu'il n'a pas fait.
 *
 * ## Ce que ça a coûté
 *
 * Le 2026-09-03, la base de développement a été repeuplée. Le seeding a affiché
 * un succès, et le propriétaire du produit s'est retrouvé sans supradmin, à
 * chercher la cause ailleurs pendant longtemps.
 *
 * L'issue accusait un `return` silencieux sur variables d'environnement
 * absentes. Vérification faite, ce `return` n'existe plus depuis la PR #59 :
 * `SupradminSeeder` lève déjà une exception nommant les deux variables, et
 * `SupradminSeederTest` le couvre.
 *
 * Le mensonge restant était ailleurs, et plus discret : au **second** passage,
 * `firstOrCreate` ne crée rien — et le seeder affichait quand même
 * « Compte supradmin créé ». Un message exact au premier lancement, faux à tous
 * les suivants. C'est très exactement « affiche un succès sans avoir agi ».
 *
 * ## Et la portée que l'issue demandait d'examiner
 *
 * `InstitutionSeeder` écrivait `env('KLASSCI_ESBTP_YAKRO_TOKEN')` sans défaut :
 * variable absente → jeton `null`, institution créée et `is_active = true`,
 * aucun avertissement. Le tenant ne pouvait plus joindre KLASSCI. Même famille.
 *
 * Son défaut par défaut pour `presentation` était en outre l'URL en `http://`
 * de #685 : un `db:seed` réintroduisait la panne que #768 venait de corriger,
 * en écrivant une valeur que `App\Rules\KlassciApiUrl` refuse désormais à
 * l'enregistrement.
 *
 * @see database/seeders/SupradminSeeder.php
 * @see database/seeders/InstitutionSeeder.php
 */
final class SeedersAnnoncentCeQuIlsFontTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Console d'essai : retient ce que le seeder a réellement affiché.
     *
     * `Seeder::setCommand()` exige un vrai `Illuminate\Console\Command` — d'où
     * l'héritage plutôt qu'un simple objet anonyme.
     */
    private function consoleDEssai(): Command
    {
        return new class extends Command
        {
            /** @var list<string> */
            public array $lignes = [];

            public function info($string, $verbosity = null): void
            {
                $this->lignes[] = (string) $string;
            }

            public function warn($string, $verbosity = null): void
            {
                $this->lignes[] = (string) $string;
            }

            public function line($string, $style = null, $verbosity = null): void
            {
                $this->lignes[] = (string) $string;
            }
        };
    }

    private function lancerSupradmin(Command $console): void
    {
        $seeder = new SupradminSeeder;
        $seeder->setCommand($console);
        $seeder->run();
    }

    public function test_le_premier_passage_annonce_une_creation_et_la_fait(): void
    {
        Config::set('supradmin.email', 'sup@688.test');
        Config::set('supradmin.password', 'un-mot-de-passe-solide');
        $console = $this->consoleDEssai();

        $this->lancerSupradmin($console);

        $this->assertDatabaseHas('users', ['email' => 'sup@688.test', 'role' => 'supradmin']);
        $this->assertStringContainsString('créé', implode("\n", $console->lignes));
    }

    public function test_le_second_passage_ne_ment_pas(): void
    {
        Config::set('supradmin.email', 'sup@688.test');
        Config::set('supradmin.password', 'un-mot-de-passe-solide');
        $this->lancerSupradmin($this->consoleDEssai());

        $console = $this->consoleDEssai();
        $this->lancerSupradmin($console);
        $sortie = implode("\n", $console->lignes);

        // Un seul compte : l'idempotence est intacte…
        $this->assertSame(1, User::withoutGlobalScope('institution')
            ->where('email', 'sup@688.test')->count());
        // …mais la sortie ne doit plus revendiquer une création.
        $this->assertStringContainsString('DÉJÀ PRÉSENT', $sortie);
        $this->assertStringNotContainsString('Compte supradmin créé', $sortie);
        // Et elle dit ce que l'utilisateur a besoin de savoir ensuite.
        $this->assertStringContainsString('INCHANGÉ', $sortie);
    }

    public function test_il_ne_plante_pas_sans_console(): void
    {
        // Appelé hors commande Artisan, `$this->command` est null.
        Config::set('supradmin.email', 'sup2@688.test');
        Config::set('supradmin.password', 'un-mot-de-passe-solide');

        (new SupradminSeeder)->run();

        $this->assertDatabaseHas('users', ['email' => 'sup2@688.test']);
    }

    public function test_un_jeton_klassci_absent_est_annonce_en_nommant_la_variable(): void
    {
        putenv('KLASSCI_ESBTP_YAKRO_TOKEN');
        $console = $this->consoleDEssai();

        $seeder = new InstitutionSeeder;
        $seeder->setCommand($console);
        $seeder->run();

        $sortie = implode("\n", $console->lignes);

        $this->assertStringContainsString('KLASSCI_ESBTP_YAKRO_TOKEN', $sortie);
        $this->assertStringContainsString('ABSENT', $sortie);
        // L'institution existe quand même : on avertit, on ne bloque pas un poste
        // de développement légitimement dépourvu de jeton.
        $this->assertDatabaseHas('institutions', ['slug' => 'esbtp-yakro']);
    }

    public function test_le_seeder_n_ecrit_plus_jamais_une_url_en_clair(): void
    {
        // Le défaut historique de `presentation` était `http://` — la panne #685
        // elle-même. Un `db:seed` la réintroduisait.
        putenv('KLASSCI_PRESENTATION_URL');

        (new InstitutionSeeder)->run();

        $urls = Institution::query()->pluck('klassci_api_url')->all();

        $this->assertNotEmpty($urls);
        foreach ($urls as $url) {
            $this->assertStringStartsNotWith('http://', (string) $url);
        }
    }
}

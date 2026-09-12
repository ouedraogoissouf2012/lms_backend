<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Rules\KlassciApiUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #685 — la migration `2026_09_11_210000` rattrape les lignes déjà écrites.
 *
 * La règle {@see KlassciApiUrl} ferme la porte d'entrée, mais elle ne
 * dit rien des institutions créées AVANT elle — dont `institutions#1` en
 * production, qui portait `http://presentation.klassci.com/api/lms` et coupait
 * toute la liaison amont du tenant.
 *
 * L'issue proposait un `UPDATE` manuel. Un ordre SQL joué une fois ne laisse
 * aucune trace, ne se rejoue pas ailleurs, et ne protège pas les autres
 * environnements. On teste donc la migration — y compris son idempotence et la
 * frontière du bouclage, qu'un `UPDATE ... LIKE 'http://%'` naïf aurait piétinée.
 *
 * @see database/migrations/2026_09_11_210000_force_https_on_klassci_api_url.php
 */
final class ForceHttpsOnKlassciApiUrlMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La migration est anonyme : on la charge par son chemin, comme le ferait
     * le migrateur, plutôt que d'en recopier la logique — recopier la logique
     * testerait le test, pas la migration.
     */
    private function jouerLaMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_11_210000_force_https_on_klassci_api_url.php'
        );

        $migration->up();
    }

    private function creerInstitution(string $slug, ?string $url): int
    {
        return (int) DB::table('institutions')->insertGetId([
            'slug' => $slug,
            'name' => $slug,
            'klassci_api_url' => $url,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function urlDe(int $id): ?string
    {
        $valeur = DB::table('institutions')->where('id', $id)->value('klassci_api_url');

        return is_string($valeur) ? $valeur : null;
    }

    public function test_elle_bascule_une_url_en_clair_vers_https(): void
    {
        // Le cas EXACT de la production le 2026-09-03.
        $id = $this->creerInstitution('prod-cassee', 'http://presentation.klassci.com/api/lms');

        $this->jouerLaMigration();

        $this->assertSame('https://presentation.klassci.com/api/lms', $this->urlDe($id));
    }

    public function test_elle_ne_touche_pas_une_url_deja_chiffree(): void
    {
        $id = $this->creerInstitution('deja-ok', 'https://esbtp-yakro.klassci.com/api/lms');

        $this->jouerLaMigration();

        $this->assertSame('https://esbtp-yakro.klassci.com/api/lms', $this->urlDe($id));
    }

    public function test_elle_epargne_la_boucle_locale(): void
    {
        // Réécrire ceci casserait un KLASSCI servi en local, sans rien sécuriser :
        // le trafic ne quitte pas la machine.
        $ids = [
            $this->creerInstitution('local-nom', 'http://localhost:8080/api'),
            $this->creerInstitution('local-v4', 'http://127.0.0.1:8000/api/lms'),
            $this->creerInstitution('local-v6', 'http://[::1]:8080/api'),
        ];

        $this->jouerLaMigration();

        $this->assertSame('http://localhost:8080/api', $this->urlDe($ids[0]));
        $this->assertSame('http://127.0.0.1:8000/api/lms', $this->urlDe($ids[1]));
        $this->assertSame('http://[::1]:8080/api', $this->urlDe($ids[2]));
    }

    public function test_un_hote_public_qui_ressemble_a_du_local_est_bien_bascule(): void
    {
        // `localhost.attaquant.example` n'est PAS la boucle locale. Un test de
        // préfixe naïf l'aurait laissé en clair.
        $id = $this->creerInstitution('faux-local', 'http://localhost.attaquant.example/api');

        $this->jouerLaMigration();

        $this->assertSame('https://localhost.attaquant.example/api', $this->urlDe($id));
    }

    public function test_elle_est_idempotente(): void
    {
        $id = $this->creerInstitution('rejouee', 'http://presentation.klassci.com/api/lms');

        $this->jouerLaMigration();
        $this->jouerLaMigration();

        // Pas de `https://https://`, pas de seconde réécriture.
        $this->assertSame('https://presentation.klassci.com/api/lms', $this->urlDe($id));
    }
}

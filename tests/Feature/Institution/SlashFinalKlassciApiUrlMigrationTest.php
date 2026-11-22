<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1331 — la migration retire le « / » final des URL KLASSCI déjà écrites.
 *
 * Les lignes sont posées par `DB::table`, comme avant le mutateur du modèle : c'est
 * l'état que la migration doit aligner. Elle est chargée par son chemin, comme le
 * ferait le migrateur, plutôt que d'en recopier la logique.
 */
final class SlashFinalKlassciApiUrlMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_22_120000_normalize_trailing_slash_on_klassci_api_url.php';

    public function test_elle_rogne_un_ou_plusieurs_slashs_finaux(): void
    {
        $un = $this->creerInstitution('un-slash', 'https://ecole-a.klassci.test/api/lms/');
        $plusieurs = $this->creerInstitution('plusieurs-slashs', 'https://ecole-b.klassci.test/api/lms///');

        $this->jouerLaMigration();

        self::assertSame('https://ecole-a.klassci.test/api/lms', $this->urlDe($un));
        self::assertSame('https://ecole-b.klassci.test/api/lms', $this->urlDe($plusieurs));
    }

    public function test_elle_ne_touche_ni_une_url_canonique_ni_une_ecole_sans_url(): void
    {
        $canonique = $this->creerInstitution('canonique', 'https://ecole-c.klassci.test/api/lms');
        $sansUrl = $this->creerInstitution('sans-url', null);

        $this->jouerLaMigration();

        self::assertSame('https://ecole-c.klassci.test/api/lms', $this->urlDe($canonique));
        self::assertNull($this->urlDe($sansUrl));
    }

    public function test_elle_est_idempotente(): void
    {
        $id = $this->creerInstitution('deux-passages', 'https://ecole-d.klassci.test/api/lms/');

        $this->jouerLaMigration(2);

        self::assertSame('https://ecole-d.klassci.test/api/lms', $this->urlDe($id));
    }

    private function jouerLaMigration(int $passages = 1): void
    {
        $chemin = database_path(self::MIGRATION);
        self::assertFileExists($chemin);

        $migration = require $chemin;
        for ($i = 0; $i < $passages; $i++) {
            $migration->up();
        }
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
}

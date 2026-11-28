<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #1868 : une table tenant-scopée NEUVE se déclare dans son propre fragment, `database/tenancy/<issue>-<sujet>.php`,
 * qui rend une liste de noms ; `config/tenancy.php` les ajoute à `institution_scoped_tables`. Plus de liste partagée
 * qui rouvre les conflits des PR (16 PR ouvertes la touchaient le 28/11, 34 paires sur 120 en conflit).
 *
 * Ce que la liste garantissait reste gardé ailleurs, inchangé : `LaConfigDesTenantsSuitLeSchemaTest` (la liste
 * contre le schéma, dans les deux sens) et `InstitutionForeignKeyTest` (la clé RESTRICT). Ici : le mécanisme, la
 * santé des fragments, et la mise en cache de la configuration.
 */
final class LesFragmentsDeTenancyTest extends TestCase
{
    public function test_un_fragment_ajoute_ses_tables_a_la_liste(): void
    {
        $fragment = base_path('database/tenancy/zz-essai-'.Str::lower(Str::random(8)).'.php');
        file_put_contents($fragment, "<?php\n\nreturn ['table_d_essai_un', 'table_d_essai_deux'];\n");
        try {
            $config = require config_path('tenancy.php');
        } finally {
            unlink($fragment);
        }

        $tables = $config['institution_scoped_tables'];
        self::assertContains('table_d_essai_un', $tables);
        self::assertContains('table_d_essai_deux', $tables);
        self::assertContains('users', $tables, 'La liste existante reste en place.');
    }

    public function test_chaque_fragment_est_une_liste_de_noms_et_aucune_table_n_est_declaree_deux_fois(): void
    {
        foreach (glob(base_path('database/tenancy/*.php')) ?: [] as $fichier) {
            $tables = require $fichier;
            self::assertIsArray($tables, basename($fichier));
            self::assertTrue(array_is_list($tables), basename($fichier).' : une liste, sans clés.');
            self::assertNotEmpty($tables, basename($fichier).' : un fragment vide ne déclare rien.');
            foreach ($tables as $table) {
                self::assertIsString($table, basename($fichier));
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $table, basename($fichier));
            }
        }

        $toutes = config('tenancy.institution_scoped_tables');
        self::assertIsArray($toutes);
        $doublons = array_keys(array_filter(array_count_values($toutes), static fn (int $n): bool => $n > 1));
        self::assertSame([], $doublons, 'Une table déclarée deux fois (liste et fragment, ou deux fragments).');
    }

    public function test_la_configuration_mise_en_cache_garde_les_tables_des_fragments(): void
    {
        $fragment = base_path('database/tenancy/zz-cache-'.Str::lower(Str::random(8)).'.php');
        file_put_contents($fragment, "<?php\n\nreturn ['table_d_essai_en_cache'];\n");
        $cache = $this->app->getCachedConfigPath();
        try {
            Artisan::call('config:cache');
            $enCache = require $cache;
        } finally {
            unlink($fragment);
            Artisan::call('config:clear');
        }

        self::assertFileDoesNotExist($cache);
        self::assertContains('table_d_essai_en_cache', $enCache['tenancy']['institution_scoped_tables']);
        self::assertContains('users', $enCache['tenancy']['institution_scoped_tables']);
    }
}

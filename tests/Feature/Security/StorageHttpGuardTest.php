<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Garde-fou de contenu des `.htaccess` de défense en profondeur (issue #577).
 *
 * ⚠️ Portée honnête : ce test vérifie que les directives protectrices sont
 * PRÉSENTES dans les fichiers versionnés. Il **ne prouve PAS** le comportement
 * d'Apache (non exécutable en CI — cf. spec #537 §5). Son rôle : empêcher qu'une
 * modification future supprime ou affaiblisse silencieusement la protection
 * (p. ex. réintroduire `storage` dans la règle racine, ce qui casserait le
 * service des assets publics ; ou retirer un `Require all denied`).
 *
 * La vérification réelle du comportement se fait par `curl` après déploiement
 * (GUIDE_DEPLOIEMENT_PRODUCTION.md §5).
 */
class StorageHttpGuardTest extends TestCase
{
    private function htaccess(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path, "Le .htaccess versionné {$relativePath} est absent.");

        return (string) file_get_contents($path);
    }

    public function test_storage_htaccess_denies_all_access(): void
    {
        $content = $this->htaccess('storage/.htaccess');

        $this->assertStringContainsString('Require all denied', $content);
        // Repli Apache 2.2.
        $this->assertStringContainsString('Deny from all', $content);
    }

    public function test_bootstrap_cache_htaccess_denies_all_access(): void
    {
        $content = $this->htaccess('bootstrap/cache/.htaccess');

        $this->assertStringContainsString('Require all denied', $content);
        $this->assertStringContainsString('Deny from all', $content);
    }

    public function test_public_storage_htaccess_reallows_public_assets(): void
    {
        // Non-régression : storage/app/public/ (assets servis par le symlink
        // /storage) doit rester accessible malgré le deny de storage/.htaccess.
        $content = $this->htaccess('storage/app/public/.htaccess');

        $this->assertStringContainsString('Require all granted', $content);
        $this->assertStringContainsString('Allow from all', $content);
    }

    public function test_chapter_slides_htaccess_forbids_direct_png_access(): void
    {
        $content = $this->htaccess('storage/app/public/chapters/.htaccess');

        $this->assertStringContainsString('slides/', $content);
        $this->assertStringContainsString('[F,L]', $content);
    }

    /**
     * Extrait le motif de la RewriteCond #577 (répertoires applicatifs) tel qu'il
     * est réellement versionné dans le .htaccess racine. mod_rewrite d'Apache
     * évalue ce motif en PCRE — le tester ici avec preg_match est donc une
     * simulation FIDÈLE du moteur (et non une fausse couverture).
     */
    private function rootDirectoryRulePattern(): string
    {
        $content = $this->htaccess('.htaccess');

        // Ligne : RewriteCond %{REQUEST_URI} <motif> [NC]  (celle listant les répertoires).
        $matched = preg_match(
            '/RewriteCond\s+%\{REQUEST_URI\}\s+(\S*\(app\|bootstrap\|[^\s]*)\s+\[NC\]/',
            $content,
            $m
        );
        $this->assertSame(1, $matched, 'La RewriteCond #577 des répertoires applicatifs est introuvable.');

        return $m[1];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function requestUriProvider(): array
    {
        return [
            // Menaces — doivent être bloquées (403).
            'code source (racine appli)'        => ['/app/Models/User.php', true],
            'code source (sous /lms-backend)'   => ['/lms-backend/app/Models/User.php', true],
            'config sous préfixe'               => ['/lms-backend/config/app.php', true],
            'base sqlite'                       => ['/lms-backend/database/database.sqlite', true],
            'vendor'                            => ['/vendor/autoload.php', true],
            'cache compilé'                     => ['/lms-backend/bootstrap/cache/config.php', true],
            // Chemins légitimes — ne doivent PAS être bloqués par cette règle.
            'route API'                         => ['/api/lessons/123/chapters', false],
            'asset public (symlink storage)'    => ['/storage/chapters/9/slides/1.png', false],
            'storage sous préfixe (→ storage/.htaccess)' => ['/lms-backend/storage/logs/laravel.log', false],
            'config profond dans une route'     => ['/api/institutions/5/config/reset', false],
            'faux ami apps'                     => ['/apps/list', false],
            'health check'                      => ['/up', false],
        ];
    }

    #[DataProvider('requestUriProvider')]
    public function test_root_directory_rule_matches_threats_not_legit_paths(string $uri, bool $shouldBlock): void
    {
        // [NC] côté Apache → drapeau i côté PCRE.
        $pattern = '#' . str_replace('#', '\#', $this->rootDirectoryRulePattern()) . '#i';

        $this->assertSame(
            $shouldBlock,
            (bool) preg_match($pattern, $uri),
            "URI « {$uri} » : blocage attendu=" . ($shouldBlock ? 'oui' : 'non')
        );
    }

    public function test_root_directory_lists_stay_in_sync(): void
    {
        // La liste des répertoires est répétée deux fois dans le .htaccess racine
        // (RewriteCond mod_rewrite + repli RedirectMatch) — Apache ne permet pas de
        // la factoriser. Ce test verrouille leur égalité : ajouter/retirer un
        // répertoire dans une seule des deux copies doit casser la CI.
        $content = $this->htaccess('.htaccess');

        preg_match_all('/\((app\|[a-z|]+)\)/', $content, $m);
        $lists = $m[1];

        $this->assertCount(2, $lists, 'Attendu exactement 2 listes de répertoires (RewriteCond + RedirectMatch).');
        $this->assertSame($lists[0], $lists[1], 'Les deux listes de répertoires du .htaccess racine doivent être identiques.');
    }

    public function test_root_htaccess_excludes_storage_from_directory_rule(): void
    {
        // storage est une URL publique légitime (symlink) : il NE doit PAS figurer
        // dans la liste des répertoires bloqués au niveau racine, sinon /storage/
        // (diapositives, vidéos) renverrait 403. Régression à empêcher.
        $content = $this->htaccess('.htaccess');

        $this->assertDoesNotMatchRegularExpression(
            '/\((?:[a-z]+\|)*storage(?:\|[a-z]+)*\)/',
            $content,
            'storage ne doit pas être dans la liste des répertoires bloqués (URL publique légitime).'
        );
    }

    public function test_root_htaccess_preserves_dotfile_rule_from_537(): void
    {
        // Le durcissement #577 ne doit pas retirer la protection fichiers-point #537.
        $content = $this->htaccess('.htaccess');

        $this->assertStringContainsString('(^|/)\.', $content);
        $this->assertStringContainsString('!^/\.well-known/', $content);
    }
}

<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExceptionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_uncaught_exception_returns_generic_message_in_production(): void
    {
        $this->app['config']->set('app.debug', false);

        $this->get('/api/test-throw-exception', [
            'Accept' => 'application/json',
        ])->assertJson([
            'success' => false,
            'message' => 'Une erreur est survenue.',
        ])->assertStatus(500);
    }

    public function test_validation_exception_returns_422(): void
    {
        $this->post('/api/auth/login', [], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }

    public function test_json_response_never_contains_exception_detail(): void
    {
        $this->app['config']->set('app.debug', false);

        // Faire une requête qui provoque une exception
        $response = $this->get('/api/test-throw-exception', [
            'Accept' => 'application/json',
        ]);

        $body = $response->getContent();

        // Vérifier qu'il n'y a pas d'exception details
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('PDOException', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('at ', $body);
        $this->assertStringNotContainsString('trace', $body);
    }

    /**
     * Audit statique : aucun `response()->json(...)` du dossier Controllers
     * ne doit transporter `getMessage()` (qui exposerait le détail de
     * l'exception au client). Implémentation portable PHP — l'ancienne
     * version utilisait `shell_exec(grep | wc -l)` Unix-spécifique
     * (TEST-01 fix).
     */
    public function test_getMessage_not_exposed_in_controllers(): void
    {
        $leaks = $this->scanLinesMatchingBoth(
            base_path('app/Http/Controllers'),
            'response()->json',
            'getMessage()'
        );

        $this->assertSame(
            [],
            $leaks,
            'getMessage() must not appear in any response()->json(...) call. Found in: '
                . implode("\n", $leaks)
        );
    }

    /**
     * Régression inverse : on doit CONSERVER `Log::*(... getMessage() ...)`
     * pour les logs serveur — sinon le détail des exceptions disparaît
     * complètement. Le seuil n'est pas critique, on s'assure juste qu'on
     * n'a pas vidé les logs par erreur.
     */
    public function test_log_still_contains_exception_details(): void
    {
        $logged = $this->scanLinesMatchingBoth(
            base_path('app/Http/Controllers'),
            'Log::',
            'getMessage()'
        );

        // de la logique de logging dans les services (TIER 1 + splits §5/§1.6 D).
        // Les controllers migrent de la Facade `Log::` vers l'injection PSR-3
        // `LoggerInterface` au fil des splits, donc le compteur peut baisser.
        // Seuil ajusté à >3 après split-19/search (`SearchController` migré vers
        // PSR-3 `LoggerInterface` dans `GlobalSearchService`). Le logging des
        // détails d'exception est toujours préservé — il passe simplement par
        // l'injection au lieu de la Facade. Reste >3 pour garantir qu'on n'a
        // pas vidé les logs serveur par erreur.
        $this->assertGreaterThan(
            3,
            count($logged),
            'Log:: should still contain getMessage() for server-side logging — found only ' . count($logged) . ' lines.'
        );
    }

    /**
     * @return list<string>  Liste de "path:line" pour les lignes contenant
     *                       les deux substrings simultanément.
     */
    private function scanLinesMatchingBoth(string $dir, string $needle1, string $needle2): array
    {
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            foreach ($lines as $i => $line) {
                if (str_contains($line, $needle1) && str_contains($line, $needle2)) {
                    $matches[] = $file->getPathname() . ':' . ($i + 1);
                }
            }
        }

        return $matches;
    }
}

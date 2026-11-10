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

    /**
     * #906 — une `HttpResponseException` sort avec la réponse qu'elle porte.
     *
     * Mesuré avant le correctif : le rendu générique \Throwable passait avant
     * Laravel et la transformait en 500 « Une erreur est survenue. », en-têtes
     * perdus. C'est pourtant par elle qu'un seau de débit rend son propre 429.
     */
    public function test_http_response_exception_keeps_its_own_response(): void
    {
        $this->app['config']->set('app.debug', false);

        $portee = response()->json(['success' => false, 'reason' => 'essai'], 429, ['Retry-After' => '42']);
        $requete = \Illuminate\Http\Request::create('/api/nimporte', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $rendue = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($requete, new \Illuminate\Http\Exceptions\HttpResponseException($portee));

        $this->assertSame(429, $rendue->getStatusCode());
        $this->assertSame('42', $rendue->headers->get('Retry-After'));
        $this->assertSame('{"success":false,"reason":"essai"}', $rendue->getContent());
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
     * Régression inverse : les contrôleurs journalisent encore leurs exceptions
     * attrapées, désormais par `CaughtExceptionLog` (au moins quatre appels à
     * l'aide avec une exception). Le seuil n'est pas critique : on s'assure
     * qu'on n'a pas vidé les journaux par erreur.
     *
     * Ce test ne prouve PAS le contenu du journal : la description de
     * l'exception est prouvée par `tests/Feature/Logging/CaughtExceptionLogTest`
     * et `SitesDesControleursTest`, et l'ancienne forme (`getMessage()` écrit à
     * la main) est interdite par `NoRawExceptionMessageTest`.
     */
    public function test_controllers_still_log_caught_exceptions_through_the_helper(): void
    {
        $logged = $this->scanLinesMatchingBoth(
            base_path('app/Http/Controllers'),
            '$this->journal->',
            ', $'
        );

        $this->assertGreaterThan(
            3,
            count($logged),
            'Les contrôleurs doivent encore journaliser leurs exceptions attrapées par CaughtExceptionLog — trouvé seulement ' . count($logged) . ' ligne(s).'
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

<?php

declare(strict_types=1);

namespace Tests\Feature\FileConversion;

use App\Services\FileConversion\PdfToPngRenderer;
use App\Support\Shell\ShellExecutionException;
use App\Support\Shell\ShellExecutorInterface;
use App\Support\Shell\ShellResult;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #79 Phase C — integration tests for {@see PdfToPngRenderer}.
 *
 * Strategy : mock {@see ShellExecutor} and use Reflection on the private
 * `renderWithGhostscript()` method so the Ghostscript path is testable
 * regardless of whether Ghostscript or Imagick is installed on the runner.
 * The Imagick path is exercised by the public `render()` method only when
 * the `imagick` PHP extension is loaded — otherwise the test is skipped.
 *
 * Storage is faked via Laravel's `Storage::fake('public')` so created
 * directories and files stay in-memory and don't pollute the test runner.
 */
#[CoversClass(PdfToPngRenderer::class)]
final class PdfToPngRendererTest extends TestCase
{
    private ShellExecutorInterface&MockInterface $shellMock;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        /** @var ShellExecutorInterface&MockInterface $mock */
        $mock = Mockery::mock(ShellExecutorInterface::class);
        $this->shellMock = $mock;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Contract : when Ghostscript is found, the renderer must invoke
     * {@see ShellExecutor::run()} with the **exact** argv-array expected
     * by Ghostscript — every flag passed as its own element so the shell
     * never sees a concatenated command (Req 10.4).
     */
    public function test_render_with_ghostscript_calls_shell_with_exact_argv(): void
    {
        $chapterId = 42;
        $outputDir = storage_path("app/public/chapters/{$chapterId}/slides");

        $this->shellMock
            ->shouldReceive('locate')
            ->with('ghostscript', Mockery::type('array'))
            ->once()
            ->andReturn('/usr/bin/gs');

        $this->shellMock
            ->shouldReceive('run')
            ->once()
            ->withArgs(function (array $command) use ($outputDir): bool {
                return $command[0] === '/usr/bin/gs'
                    && $command[1] === '-dSAFER'
                    && $command[2] === '-dBATCH'
                    && $command[3] === '-dNOPAUSE'
                    && $command[4] === '-sDEVICE=png16m'
                    && $command[5] === '-r150'
                    && in_array('-dTextAlphaBits=4', $command, true)
                    && in_array('-dGraphicsAlphaBits=4', $command, true)
                    && in_array("-sOutputFile={$outputDir}/slide_%03d.png", $command, true);
            })
            ->andReturnUsing(function () use ($outputDir): ShellResult {
                // Simulate Ghostscript producing two PNG slides so the
                // subsequent glob() / sort() / array_map() pipeline has
                // something realistic to consume.
                if (! is_dir($outputDir)) {
                    mkdir($outputDir, 0755, true);
                }
                touch("{$outputDir}/slide_001.png");
                touch("{$outputDir}/slide_002.png");

                return new ShellResult('', '', 0);
            });

        $renderer = new PdfToPngRenderer($this->shellMock);

        $result = $this->callRenderWithGhostscript($renderer, '/tmp/fake.pdf', $outputDir, $chapterId);

        $this->assertSame(
            [
                "chapters/{$chapterId}/slides/slide_001.png",
                "chapters/{$chapterId}/slides/slide_002.png",
            ],
            $result,
            'Returned paths must be the public-disk relative paths sorted by slide number.',
        );
    }

    /**
     * Fail-secure when Ghostscript is missing from the host : raise a
     * generic {@see RuntimeException} (Req 5.1) rather than crashing
     * or leaking the binary search list.
     */
    public function test_render_with_ghostscript_throws_when_binary_not_found(): void
    {
        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->andReturn(null);

        $this->shellMock->shouldNotReceive('run');

        $renderer = new PdfToPngRenderer($this->shellMock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Outil de conversion non installé sur le serveur');

        $this->callRenderWithGhostscript($renderer, '/tmp/fake.pdf', '/tmp', 1);
    }

    /**
     * When the shell call fails (`ShellExecutionException`), the renderer
     * must wrap it in a **generic** RuntimeException — the original
     * stderr / command / exit code stay on the inner exception for
     * server-side logs but are NOT exposed to the caller (Req 5.2).
     */
    public function test_render_with_ghostscript_wraps_shell_exception_generically(): void
    {
        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->andReturn('/usr/bin/gs');

        $this->shellMock
            ->shouldReceive('run')
            ->once()
            ->andThrow(new ShellExecutionException(
                command: ['/usr/bin/gs', '-dSAFER'],
                stderr: 'Cannot open input file',
                exitCode: 1,
            ));

        $renderer = new PdfToPngRenderer($this->shellMock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Échec de la conversion PDF');

        $this->callRenderWithGhostscript($renderer, '/tmp/fake.pdf', '/tmp', 1);
    }

    /**
     * Imagick path is preferred when the extension is loaded. We assert
     * the public `render()` orchestration by ensuring {@see ShellExecutor}
     * is NEVER touched on a host with Imagick — i.e. the renderer truly
     * skips the Ghostscript branch.
     *
     * Test is skipped when Imagick is absent : the contract is then
     * irrelevant and the Ghostscript tests above cover the other branch.
     */
    public function test_render_skips_shell_when_imagick_extension_loaded(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick PHP extension is not installed on this runner.');
        }

        $this->shellMock->shouldNotReceive('locate');
        $this->shellMock->shouldNotReceive('run');

        // Minimal valid 1-page PDF — small enough to inline without an external fixture.
        $pdfPath = sys_get_temp_dir() . '/imagick-test-' . uniqid() . '.pdf';
        file_put_contents(
            $pdfPath,
            "%PDF-1.0\n"
            . "1 0 obj<</Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/MediaBox[0 0 3 3]/Parent 2 0 R/Resources<<>>>>endobj\n"
            . "trailer<</Root 1 0 R/Size 4>>\n"
            . "%%EOF\n"
        );

        try {
            $renderer = new PdfToPngRenderer($this->shellMock);
            $result   = $renderer->render($pdfPath, 99);

            $this->assertNotEmpty($result, 'Imagick should produce at least one slide.');
            $this->assertStringStartsWith('chapters/99/slides/slide_', $result[0]);
        } finally {
            @unlink($pdfPath);
        }
    }

    /**
     * @return list<string>
     */
    private function callRenderWithGhostscript(
        PdfToPngRenderer $renderer,
        string $pdfPath,
        string $outputDir,
        int $chapterId,
    ): array {
        $method = (new ReflectionClass($renderer))->getMethod('renderWithGhostscript');
        $method->setAccessible(true);

        /** @var list<string> $result */
        $result = $method->invokeArgs($renderer, [$pdfPath, $outputDir, $chapterId]);

        return $result;
    }
}

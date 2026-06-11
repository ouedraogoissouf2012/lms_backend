<?php

declare(strict_types=1);

namespace Tests\Feature\FileConversion;

use App\Services\FileConversion\FileValidator;
use App\Services\FileConversion\WordConverter;
use App\Support\Shell\ShellExecutionException;
use App\Support\Shell\ShellExecutorInterface;
use App\Support\Shell\ShellResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #79 Phase C — integration tests for {@see WordConverter}.
 *
 * Four scenarios from the design's Testing Strategy :
 *   1. LibreOffice success → HTML produced and read.
 *   2. LibreOffice missing on the host → generic RuntimeException.
 *   3. LibreOffice exits non-zero → generic RuntimeException, server log
 *      keeps the technical detail but the message does not leak it.
 *   4. Validation rejects non-`.docx` uploads.
 *
 * No ConvertAPI fallback to test — by design, the Word converter routes
 * straight to LibreOffice because the public output is HTML (`content_type:
 * 'text'`), not images. Adding a ConvertAPI Word→HTML path would change
 * the return shape and would violate Invariant 1.
 */
#[CoversClass(WordConverter::class)]
final class WordConverterTest extends TestCase
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private ShellExecutorInterface&MockInterface $shellMock;
    private FileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        /** @var ShellExecutorInterface&MockInterface $shellMock */
        $shellMock = Mockery::mock(ShellExecutorInterface::class);
        $this->shellMock = $shellMock;

        $this->validator = new FileValidator();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_html_content_when_libreoffice_succeeds(): void
    {
        $file = UploadedFile::fake()->create('lesson.docx', 100, self::DOCX_MIME);

        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->with('libreoffice', Mockery::type('array'))
            ->andReturn('/usr/bin/soffice');

        $expectedHtml = '<html><body><p>Lesson content</p></body></html>';

        $this->shellMock
            ->shouldReceive('run')
            ->once()
            ->withArgs(function (array $command): bool {
                return $command[0] === '/usr/bin/soffice'
                    && in_array('--headless', $command, true)
                    && in_array('html', $command, true);
            })
            ->andReturnUsing(function (array $command) use ($expectedHtml): ShellResult {
                // Simulate LibreOffice producing the HTML file at the
                // canonical path so the converter's `file_get_contents`
                // returns content the test can assert on.
                $docxPath = $command[count($command) - 1];
                $outdir   = $command[count($command) - 2];
                if (! is_dir($outdir)) {
                    mkdir($outdir, 0755, true);
                }
                file_put_contents(
                    "{$outdir}/" . pathinfo($docxPath, PATHINFO_FILENAME) . '.html',
                    $expectedHtml,
                );

                return new ShellResult('', '', 0);
            });

        $result = $this->makeConverter()->convert($file, 7);

        $this->assertTrue($result['success']);
        $this->assertSame($expectedHtml, $result['content']);
        $this->assertSame('text', $result['content_type']);
        $this->assertIsString($result['file_original_path']);
        $this->assertStringStartsWith('chapters/7/original/', $result['file_original_path']);
    }

    public function test_throws_generic_runtime_exception_when_libreoffice_binary_missing(): void
    {
        $file = UploadedFile::fake()->create('lesson.docx', 100, self::DOCX_MIME);

        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->andReturn(null);

        $this->shellMock->shouldNotReceive('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LibreOffice non installé sur le serveur');

        $this->makeConverter()->convert($file, 7);
    }

    public function test_wraps_shell_failure_in_generic_runtime_exception(): void
    {
        $file = UploadedFile::fake()->create('lesson.docx', 100, self::DOCX_MIME);

        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->andReturn('/usr/bin/soffice');

        $this->shellMock
            ->shouldReceive('run')
            ->once()
            ->andThrow(new ShellExecutionException(
                command: ['/usr/bin/soffice'],
                stderr: 'Internal LibreOffice error : path /tmp/x not found',
                exitCode: 7,
            ));

        try {
            $this->makeConverter()->convert($file, 7);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            // Public message must NOT leak the stderr or filesystem path.
            $this->assertStringContainsString('Échec de la conversion Word', $e->getMessage());
            $this->assertStringNotContainsString('/tmp/x', $e->getMessage());
            $this->assertStringNotContainsString('stderr', $e->getMessage());
        }
    }

    public function test_rejects_non_word_extension(): void
    {
        $file = UploadedFile::fake()->create('rogue.pdf', 100, 'application/pdf');

        $this->shellMock->shouldNotReceive('locate');
        $this->shellMock->shouldNotReceive('run');

        $this->expectException(InvalidArgumentException::class);

        $this->makeConverter()->convert($file, 7);
    }

    private function makeConverter(): WordConverter
    {
        return new WordConverter(
            new \Psr\Log\NullLogger(),
            $this->shellMock,
            $this->validator,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\FileConversion;

use App\Services\ConvertApiService;
use App\Services\FileConversion\FileValidator;
use App\Services\FileConversion\PdfToPngRendererInterface;
use App\Services\FileConversion\PowerPointConverter;
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
 * Issue #79 Phase C — integration tests for {@see PowerPointConverter}.
 *
 * Six scenarios from the design's Testing Strategy :
 *   1. ConvertAPI success path
 *   2. Fallback path (ConvertAPI fails → LibreOffice → PdfToPngRenderer)
 *   3. Fallback path with LibreOffice missing → generic RuntimeException
 *   4. Fallback path with PdfToPngRenderer failing → generic RuntimeException
 *   5. Validation reject : wrong extension
 *   6. Validation reject : oversize file
 *
 * The real {@see FileValidator} is used (it is pure logic without external
 * dependencies) so we exercise the actual size / extension / MIME checks
 * end-to-end. Every external collaborator (`ShellExecutor`, `ConvertApi`,
 * `PdfToPngRenderer`) is mocked so the test runs without LibreOffice,
 * Ghostscript or an API key (Req 9.4).
 */
#[CoversClass(PowerPointConverter::class)]
final class PowerPointConverterTest extends TestCase
{
    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private ShellExecutorInterface&MockInterface $shellMock;
    private ConvertApiService&MockInterface $convertApiMock;
    private PdfToPngRendererInterface&MockInterface $pdfRendererMock;
    private FileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        /** @var ShellExecutorInterface&MockInterface $shellMock */
        $shellMock = Mockery::mock(ShellExecutorInterface::class);
        $this->shellMock = $shellMock;

        /** @var ConvertApiService&MockInterface $convertApiMock */
        $convertApiMock = Mockery::mock(ConvertApiService::class);
        $this->convertApiMock = $convertApiMock;

        /** @var PdfToPngRendererInterface&MockInterface $pdfRendererMock */
        $pdfRendererMock = Mockery::mock(PdfToPngRendererInterface::class);
        $this->pdfRendererMock = $pdfRendererMock;

        $this->validator = new FileValidator();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_convertapi_branch_when_convertapi_succeeds(): void
    {
        $file = UploadedFile::fake()->create('lesson.pptx', 100, self::PPTX_MIME);

        $this->convertApiMock
            ->shouldReceive('convertPowerPointToImages')
            ->once()
            ->andReturn([
                'chapters/42/slides/slide_1.png',
                'chapters/42/slides/slide_2.png',
            ]);

        $this->shellMock->shouldNotReceive('locate');
        $this->shellMock->shouldNotReceive('run');
        $this->pdfRendererMock->shouldNotReceive('render');

        $result = $this->makeConverter()->convert($file, 42);

        $this->assertSame('ConvertAPI', $result['conversion_method']);
        $this->assertSame(2, $result['slides_count']);
        $this->assertSame(
            ['chapters/42/slides/slide_1.png', 'chapters/42/slides/slide_2.png'],
            $result['slides_images'],
        );
        $this->assertTrue($result['success']);
        $this->assertSame($result['file_original_path'], $result['file_converted_path']);
    }

    public function test_falls_back_to_libreoffice_then_pdf_renderer_when_convertapi_fails(): void
    {
        $file = UploadedFile::fake()->create('lesson.pptx', 100, self::PPTX_MIME);

        $this->convertApiMock
            ->shouldReceive('convertPowerPointToImages')
            ->once()
            ->andThrow(new RuntimeException('ConvertAPI down'));

        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->with('libreoffice', Mockery::type('array'))
            ->andReturn('/usr/bin/soffice');

        $this->shellMock
            ->shouldReceive('run')
            ->once()
            ->withArgs(function (array $command): bool {
                return $command[0] === '/usr/bin/soffice'
                    && in_array('--headless', $command, true)
                    && in_array('--convert-to', $command, true)
                    && in_array('pdf', $command, true);
            })
            ->andReturnUsing(function (array $command): ShellResult {
                // Simulate LibreOffice producing the PDF at the expected path.
                $pptxPath = $command[count($command) - 1];
                $outdir   = $command[count($command) - 2];
                if (! is_dir($outdir)) {
                    mkdir($outdir, 0755, true);
                }
                touch("{$outdir}/" . pathinfo($pptxPath, PATHINFO_FILENAME) . '.pdf');

                return new ShellResult('', '', 0);
            });

        $this->pdfRendererMock
            ->shouldReceive('render')
            ->once()
            ->andReturn([
                'chapters/42/slides/slide_001.png',
                'chapters/42/slides/slide_002.png',
                'chapters/42/slides/slide_003.png',
            ]);

        $result = $this->makeConverter()->convert($file, 42);

        $this->assertSame('LibreOffice (fallback)', $result['conversion_method']);
        $this->assertSame(3, $result['slides_count']);
    }

    public function test_throws_generic_runtime_exception_when_libreoffice_binary_missing(): void
    {
        $file = UploadedFile::fake()->create('lesson.pptx', 100, self::PPTX_MIME);

        $this->convertApiMock
            ->shouldReceive('convertPowerPointToImages')
            ->once()
            ->andThrow(new RuntimeException('ConvertAPI failure'));

        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->andReturn(null);

        $this->shellMock->shouldNotReceive('run');
        $this->pdfRendererMock->shouldNotReceive('render');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LibreOffice non installé sur le serveur');

        $this->makeConverter()->convert($file, 42);
    }

    public function test_throws_generic_runtime_exception_when_pdf_renderer_fails(): void
    {
        $file = UploadedFile::fake()->create('lesson.pptx', 100, self::PPTX_MIME);

        $this->convertApiMock
            ->shouldReceive('convertPowerPointToImages')
            ->once()
            ->andThrow(new RuntimeException('ConvertAPI failure'));

        $this->shellMock
            ->shouldReceive('locate')
            ->once()
            ->andReturn('/usr/bin/soffice');

        $this->shellMock
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (array $command): ShellResult {
                $pptxPath = $command[count($command) - 1];
                $outdir   = $command[count($command) - 2];
                if (! is_dir($outdir)) {
                    mkdir($outdir, 0755, true);
                }
                touch("{$outdir}/" . pathinfo($pptxPath, PATHINFO_FILENAME) . '.pdf');

                return new ShellResult('', '', 0);
            });

        $this->pdfRendererMock
            ->shouldReceive('render')
            ->once()
            ->andThrow(new RuntimeException('No renderer available'));

        $this->expectException(RuntimeException::class);

        $this->makeConverter()->convert($file, 42);
    }

    public function test_rejects_unsupported_extension(): void
    {
        $file = UploadedFile::fake()->create('rogue.txt', 100, 'text/plain');

        $this->convertApiMock->shouldNotReceive('convertPowerPointToImages');
        $this->shellMock->shouldNotReceive('run');

        $this->expectException(InvalidArgumentException::class);

        $this->makeConverter()->convert($file, 42);
    }

    public function test_rejects_oversized_upload(): void
    {
        // 30 MB + 1 KB → over the 30 MB limit (size argument is in KB).
        $file = UploadedFile::fake()->create('huge.pptx', 30 * 1024 + 1, self::PPTX_MIME);

        $this->convertApiMock->shouldNotReceive('convertPowerPointToImages');
        $this->shellMock->shouldNotReceive('run');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max: 30 MB');

        $this->makeConverter()->convert($file, 42);
    }

    private function makeConverter(): PowerPointConverter
    {
        return new PowerPointConverter(
            new \Psr\Log\NullLogger(),
            $this->shellMock,
            $this->convertApiMock,
            $this->pdfRendererMock,
            $this->validator,
        );
    }
}

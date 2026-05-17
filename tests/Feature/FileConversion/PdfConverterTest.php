<?php

declare(strict_types=1);

namespace Tests\Feature\FileConversion;

use App\Services\ConvertApiService;
use App\Services\FileConversion\FileValidator;
use App\Services\FileConversion\PdfConverter;
use App\Services\FileConversion\PdfToPngRendererInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #79 Phase C — integration tests for {@see PdfConverter}.
 *
 * Four scenarios from the design's Testing Strategy :
 *   1. ConvertAPI success → returned with `conversion_method: 'ConvertAPI'`
 *      and the local fallback renderer is NOT touched.
 *   2. ConvertAPI failure → fall back to {@see PdfToPngRendererInterface}
 *      with `conversion_method: 'Imagick/GD (fallback)'` preserved verbatim.
 *   3. ConvertAPI failure + renderer failure → generic RuntimeException.
 *   4. Validation rejects non-`.pdf` uploads.
 */
#[CoversClass(PdfConverter::class)]
final class PdfConverterTest extends TestCase
{
    private ConvertApiService&MockInterface $convertApiMock;
    private PdfToPngRendererInterface&MockInterface $pdfRendererMock;
    private FileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

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
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->convertApiMock
            ->shouldReceive('convertPdfToImages')
            ->once()
            ->andReturn([
                'chapters/9/slides/page_1.png',
                'chapters/9/slides/page_2.png',
                'chapters/9/slides/page_3.png',
            ]);

        $this->pdfRendererMock->shouldNotReceive('render');

        $result = $this->makeConverter()->convert($file, 9);

        $this->assertSame('ConvertAPI', $result['conversion_method']);
        $this->assertSame(3, $result['slides_count']);
        $this->assertTrue($result['success']);
        // Original and converted paths are the same when ConvertAPI is used
        // (no intermediate PDF). Legacy invariant preserved.
        $this->assertSame($result['file_original_path'], $result['file_converted_path']);
    }

    public function test_falls_back_to_local_renderer_when_convertapi_fails(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->convertApiMock
            ->shouldReceive('convertPdfToImages')
            ->once()
            ->andThrow(new RuntimeException('ConvertAPI is down'));

        $this->pdfRendererMock
            ->shouldReceive('render')
            ->once()
            ->andReturn([
                'chapters/9/slides/slide_001.png',
                'chapters/9/slides/slide_002.png',
            ]);

        $result = $this->makeConverter()->convert($file, 9);

        // The exact 'Imagick/GD (fallback)' string is Invariant 1 of the
        // refactor — never edit it without controller coordination.
        $this->assertSame('Imagick/GD (fallback)', $result['conversion_method']);
        $this->assertSame(2, $result['slides_count']);
    }

    public function test_throws_generic_runtime_exception_when_renderer_also_fails(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->convertApiMock
            ->shouldReceive('convertPdfToImages')
            ->once()
            ->andThrow(new RuntimeException('ConvertAPI is down'));

        $this->pdfRendererMock
            ->shouldReceive('render')
            ->once()
            ->andThrow(new RuntimeException('Outil de conversion non installé sur le serveur'));

        $this->expectException(RuntimeException::class);

        $this->makeConverter()->convert($file, 9);
    }

    public function test_rejects_non_pdf_extension(): void
    {
        $file = UploadedFile::fake()->create(
            'rogue.pptx',
            100,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        );

        $this->convertApiMock->shouldNotReceive('convertPdfToImages');
        $this->pdfRendererMock->shouldNotReceive('render');

        $this->expectException(InvalidArgumentException::class);

        $this->makeConverter()->convert($file, 9);
    }

    private function makeConverter(): PdfConverter
    {
        return new PdfConverter(
            $this->convertApiMock,
            $this->pdfRendererMock,
            $this->validator,
        );
    }
}

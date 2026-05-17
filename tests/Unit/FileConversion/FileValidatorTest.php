<?php

declare(strict_types=1);

namespace Tests\Unit\FileConversion;

use App\Services\FileConversion\FileValidator;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #79 Phase C — unit tests for {@see FileValidator}.
 *
 * Although the converters' tests already exercise the validator
 * indirectly, these dedicated cases pin down the contract on the
 * boundaries (max size, allowed extensions, MIME whitelist) so a
 * future regression on these rules is caught by a tight, isolated test.
 */
#[CoversClass(FileValidator::class)]
final class FileValidatorTest extends TestCase
{
    private FileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FileValidator();
    }

    public function test_accepts_file_with_allowed_extension_and_mime(): void
    {
        $file = UploadedFile::fake()->create(
            'lesson.pptx',
            100,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        );

        $this->expectNotToPerformAssertions();
        $this->validator->validate($file, ['pptx', 'ppt']);
    }

    public function test_rejects_unsupported_extension(): void
    {
        $file = UploadedFile::fake()->create('lesson.txt', 100, 'text/plain');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Format non supporté');

        $this->validator->validate($file, ['pptx', 'ppt']);
    }

    public function test_accepts_file_at_exact_maximum_size_boundary(): void
    {
        // size kB → 30 720 KB = 30 * 1024 KB = exactly 30 MB → still OK
        // (the check is `> MAX_FILE_SIZE`, so equality must pass).
        $file = UploadedFile::fake()->create('lesson.pptx', 30 * 1024, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->expectNotToPerformAssertions();
        $this->validator->validate($file, ['pptx']);
    }

    public function test_rejects_file_strictly_above_maximum_size_boundary(): void
    {
        // 30 MB + 1 KB → over the limit, must throw.
        $file = UploadedFile::fake()->create('lesson.pptx', 30 * 1024 + 1, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max: 30 MB');

        $this->validator->validate($file, ['pptx']);
    }

    public function test_rejects_file_with_disallowed_mime_type(): void
    {
        // Extension is `.pptx` (in allow-list), but MIME doesn't match
        // the whitelist → must throw. Protects against extension spoofing.
        $file = UploadedFile::fake()->create('disguised.pptx', 100, 'application/octet-stream');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type MIME invalide');

        $this->validator->validate($file, ['pptx']);
    }

    public function test_accepts_pdf_with_pdf_mime(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->expectNotToPerformAssertions();
        $this->validator->validate($file, ['pdf']);
    }

    public function test_accepts_docx_with_docx_mime(): void
    {
        $file = UploadedFile::fake()->create(
            'doc.docx',
            100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $this->expectNotToPerformAssertions();
        $this->validator->validate($file, ['docx', 'doc']);
    }
}

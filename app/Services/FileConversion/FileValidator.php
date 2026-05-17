<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Shared upload validation for every converter (PowerPoint, Word, PDF).
 *
 * Centralises the size, extension and MIME-type checks previously duplicated
 * (as a `protected` method) inside `FileConversionService`. Extracting it lets
 * each converter inject the same validator and lets evolutions of the rules
 * (e.g. adding an antivirus scan, tightening MIME whitelist) happen in a
 * single place — Open/Closed friendly.
 *
 * Throws `\InvalidArgumentException` on rejection : the message is short,
 * generic and free of filesystem internals, so it can safely bubble up to
 * the controller and ultimately to a JSON error response (Req 5.1).
 */
final class FileValidator
{
    /**
     * Maximum upload size in bytes (30 MB).
     *
     * Preserved verbatim from the legacy `FileConversionService::MAX_FILE_SIZE`
     * to satisfy Invariant 2 (no behavioural drift across the refactor).
     */
    public const MAX_FILE_SIZE = 30 * 1024 * 1024;

    /**
     * Allow-list of MIME types accepted by any converter. Tighter than the
     * extension check because the browser-supplied extension can be spoofed,
     * whereas the MIME type comes from PHP's `finfo`-based detection.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation', // pptx
        'application/vnd.ms-powerpoint',                                              // ppt
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',    // docx
        'application/msword',                                                         // doc
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',          // xlsx
        'application/vnd.ms-excel',                                                   // xls
        'application/pdf',
    ];

    /**
     * @param array<int, string> $allowedExtensions Per-converter whitelist,
     *                                              e.g. `['pptx', 'ppt']`.
     *
     * @throws InvalidArgumentException If the file exceeds {@see MAX_FILE_SIZE},
     *                                  carries a disallowed extension or an
     *                                  unrecognised MIME type.
     */
    public function validate(UploadedFile $file, array $allowedExtensions): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Fichier trop volumineux. Max: 30 MB');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(
                'Format non supporté. Formats acceptés: ' . implode(', ', $allowedExtensions),
            );
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Type MIME invalide: {$mimeType}");
        }
    }
}

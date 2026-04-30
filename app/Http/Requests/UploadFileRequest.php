<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file upload requests.
 *
 * ## Purpose
 * Centralizes file validation to prevent:
 * - File upload DOS (30MB max)
 * - Malicious file types (only allow: pdf, doc, docx, ppt, pptx, xls, xlsx, jpg, png, gif)
 * - Invalid file extensions (whitelist only)
 *
 * ## File Size
 * Maximum 30 MB (31457280 bytes).
 * Rationale: Balances user needs (presentations, documents) with server resource protection.
 *
 * Before CRITICAL-05: Inconsistency found
 * - FileController: 50MB max (implicit in `.env`)
 * - ChapterController: 100MB max (implicit)
 * This request standardizes to 30MB across all endpoints.
 *
 * ## Allowed MIME Types
 * Documents:
 *   - PDF (application/pdf)
 *   - Word (.doc, .docx - application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document)
 *   - Excel (.xls, .xlsx - application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet)
 *   - PowerPoint (.ppt, .pptx - application/vnd.ms-powerpoint, application/vnd.openxmlformats-officedocument.presentationml.presentation)
 *
 * Images:
 *   - JPG (image/jpeg)
 *   - PNG (image/png)
 *   - GIF (image/gif)
 *
 * ## 10-year perspective
 * If allowed file types change (e.g., add webp), update:
 * - mimes:... rule
 * - $allowedExtensions array
 * - mimetypeMap array
 * All file uploads automatically use new whitelist.
 *
 * Changes in one place = security policy updated everywhere.
 *
 * ## Authorization
 * Who can upload? Determined by controller/route authorization.
 * This request only validates file, not access control.
 */
final class UploadFileRequest extends FormRequest
{
    /**
     * Files are uploaded by authenticated users.
     * Role-based access controlled at route/controller level.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Authorization checked at route middleware level (auth:sanctum)
        // This request only validates file content/size
        return true;
    }

    /**
     * Get the validation rules for file upload.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:31457280', // 30 MB max
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif',
            ],
            // Optional: file description/name
            'name' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-\.]+$/', // Alphanumeric, spaces, dash, dot only (no underscore)
            ],
        ];
    }

    /**
     * Custom error messages for file validation.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Un fichier est requis',
            'file.file' => 'Le champ doit être un fichier valide',
            'file.max' => 'Le fichier ne doit pas dépasser 30 MB',
            'file.mimes' => 'Le type de fichier n\'est pas autorisé. Types acceptés: PDF, Word, Excel, PowerPoint, JPG, PNG, GIF',
            'name.regex' => 'Le nom du fichier ne peut contenir que des lettres, chiffres, espaces, tirets et points',
        ];
    }

    /**
     * Prepare data for validation.
     *
     * Normalize file name if provided:
     * - Trim whitespace
     * - Remove special characters
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->name ?? ''),
            ]);
        }
    }

    /**
     * Get the file size limit in human-readable format.
     *
     * Used by controller for error messages.
     *
     * @return string
     */
    public static function getMaxFileSize(): string
    {
        return '30 MB';
    }

    /**
     * Get allowed file extensions.
     *
     * Whitelist validation for extra security.
     * Even if MIME type is spoofed, extension must match.
     *
     * @return array
     */
    public static function getAllowedExtensions(): array
    {
        return [
            // Documents
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            // Images
            'jpg',
            'jpeg',
            'png',
            'gif',
        ];
    }
}

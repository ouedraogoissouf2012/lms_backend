<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class ImportRecorder
{
    /**
     * @param  array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}  $report
     */
    public function persist(User $user, UploadedFile $file, array $report): Import
    {
        $name = uniqid('import_', true).'.csv';
        $path = $file->storeAs('imports', $name, 'local');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Le fichier d\'import n\'a pas pu être stocké.');
        }

        $import = Import::query()->create([
            'institution_id' => $user->institution_id,
            'user_id' => $user->id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'status' => Import::STATUS_PREVIEWED,
            'ok_count' => $report['counts']['ok'],
            'error_count' => $report['counts']['error'],
        ]);

        foreach ($report['rows'] as $row) {
            $import->rows()->create([
                'line' => $row['line'],
                'status' => $row['status'],
                'code' => $row['code'] ?? null,
                'message' => $row['message'] ?? null,
                'payload' => $row['payload'] ?? null,
            ]);
        }

        return $import;
    }
}

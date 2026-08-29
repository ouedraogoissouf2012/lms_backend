<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Jobs\ConvertChapterFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class AsyncChapterConversionDispatcher
{
    public function __construct(
        private readonly AsyncChapterConversionStore $store,
    ) {}

    /**
     * @return array{id: string, status: string, status_url: string}
     */
    public function dispatch(int $chapterId, UploadedFile $file, int $userId): array
    {
        $id = (string) Str::uuid();
        $sourcePath = $file->storeAs(
            'chapter-conversions/'.$id,
            $this->sourceName($file),
            'local'
        );

        $this->store->pending($id, $chapterId, $userId, [
            'source_path' => $sourcePath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
        ]);

        ConvertChapterFile::dispatch($id)->onQueue('low');

        return [
            'id' => $id,
            'status' => 'pending',
            'status_url' => route('chapters.uploads.status', ['id' => $id], false),
        ];
    }

    private function sourceName(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return 'source'.($extension !== '' ? '.'.$extension : '');
    }
}

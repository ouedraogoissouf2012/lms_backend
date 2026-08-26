<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * #620 — sert une diapositive PNG déjà autorisée, et signe les URLs.
 *
 * Les fichiers restent sur le disque public (pipeline de conversion) mais
 * Apache refuse `/slides/` : le seul accès est cette route signée.
 */
final class ChapterSlideService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly UrlGenerator $urls,
    ) {
    }

    /**
     * @return list<string>
     */
    public function signedUrls(Chapter $chapter): array
    {
        $paths = $chapter->slides_images;
        if (! is_array($paths)) {
            return [];
        }

        $urls = [];
        foreach (array_values($paths) as $index => $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $urls[] = $this->urls->temporarySignedRoute(
                'chapters.slides.show',
                now()->addMinutes(self::TTL_MINUTES),
                ['chapter' => $chapter->id, 'slide' => $index + 1],
            );
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function replaceInPayload(Chapter $chapter, array $payload): array
    {
        $payload['slides_images'] = $this->signedUrls($chapter);

        return $payload;
    }

    public function stream(Chapter $chapter, int $slide): ?StreamedResponse
    {
        $path = $this->pathFor($chapter, $slide);
        if ($path === null) {
            return null;
        }

        $disk = $this->filesystem->disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function pathFor(Chapter $chapter, int $slide): ?string
    {
        if ($slide < 1) {
            return null;
        }

        $paths = $chapter->slides_images;
        if (! is_array($paths)) {
            return null;
        }

        $path = $paths[$slide - 1] ?? null;
        if (! is_string($path) || str_contains($path, '..')) {
            return null;
        }

        $prefix = 'chapters/'.$chapter->id.'/slides/';
        if (! str_starts_with($path, $prefix) || ! str_ends_with(strtolower($path), '.png')) {
            return null;
        }

        return $path;
    }
}

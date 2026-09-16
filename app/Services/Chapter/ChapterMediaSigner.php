<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Services\Visio\Recording\RecordingVideoService;

/**
 * #824 — décide ce que le payload d'un chapitre expose de ses médias privés.
 *
 * ## Pourquoi cette classe existe
 *
 * Le parcours d'enveloppe (`data` = un chapitre, `data` = une liste) vivait
 * dans `ChapterSlideService`. Tant qu'un seul artefact se signait, le nom
 * tenait. Depuis #824, la vidéo d'un enregistrement se signe aussi — et faire
 * signer des vidéos à un service nommé « Slide » aurait rendu la revue
 * trompeuse.
 *
 * Le parcours d'enveloppe n'a jamais rien eu de spécifique aux diapositives :
 * il ne fait qu'appeler `replaceInPayload`. C'est donc lui qu'on déplace, et
 * chaque service d'artefact garde ce qui lui est propre — les diapositives
 * leur pagination et leur flux, la vidéo sa durée de signature et ses plages.
 *
 * @see ChapterSlideService
 * @see RecordingVideoService
 */
final class ChapterMediaSigner
{
    public function __construct(
        private readonly ChapterSlideService $slides,
        private readonly RecordingVideoService $video,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function replaceInPayload(Chapter $chapter, array $payload): array
    {
        $payload['slides_images'] = $this->slides->signedUrls($chapter);

        $video = $this->video->signedUrl($chapter);

        // `null` signifie « ce n'est pas un de nos fichiers » : un lien YouTube
        // saisi par l'enseignant doit traverser intact, pas être signé.
        if ($video !== null) {
            $payload['video_url'] = $video;
        }

        return $payload;
    }

    /**
     * Signe les médias d'une enveloppe de détail (`data` = un chapitre).
     *
     * Vivait dans `ChapterController` : fouiller `data`, tester le type et
     * appeler `toArray()` n'est pas du travail HTTP (#689).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function signSingleResponse(array $payload): array
    {
        $chapter = $payload['data'] ?? null;

        if (! $chapter instanceof Chapter) {
            return $payload;
        }

        $payload['data'] = $this->replaceInPayload($chapter, $chapter->toArray());

        return $payload;
    }

    /**
     * Signe les médias d'une enveloppe de liste (`data` = itérable).
     *
     * Les éléments non-`Chapter` traversent inchangés : une enveloppe d'erreur
     * ou une liste déjà sérialisée ne doit pas faire échouer la réponse.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function signListResponse(array $payload): array
    {
        $chapters = $payload['data'] ?? null;

        if (! is_iterable($chapters)) {
            return $payload;
        }

        $signed = [];
        foreach ($chapters as $chapter) {
            $signed[] = $chapter instanceof Chapter
                ? $this->replaceInPayload($chapter, $chapter->toArray())
                : $chapter;
        }
        $payload['data'] = $signed;

        return $payload;
    }
}

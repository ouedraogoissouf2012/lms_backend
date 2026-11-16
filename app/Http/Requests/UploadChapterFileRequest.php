<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksChapterOwnership;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Dépôt d'un fichier sur un chapitre (`POST /api/chapters/{chapterId}/upload`).
 *
 * Le fichier suit les règles de {@see UploadFileRequest} (taille, types), héritées. Le
 * dépôt suit la règle de modification du chapitre ({@see ChecksChapterOwnership}, celle de
 * la suppression et de la restauration) : l'auteur de la leçon, ou un administrateur de
 * l'établissement.
 *
 * Un chapitre absent, ou d'un autre établissement, rend 404 (« Chapitre introuvable »),
 * comme les lectures ; un chapitre de l'établissement que le compte ne peut pas modifier,
 * 403.
 */
final class UploadChapterFileRequest extends UploadFileRequest
{
    use ChecksChapterOwnership;

    private const PARAMETRE = 'chapterId';

    public function authorize(): bool
    {
        return $this->chapterOwnershipPasses(parametre: self::PARAMETRE);
    }

    protected function failedAuthorization(): void
    {
        $user = auth()->user();

        if ($user instanceof User && $this->chapitreDeLEtablissement($user, false, self::PARAMETRE) === null) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Chapitre introuvable',
            ], 404));
        }

        parent::failedAuthorization();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksChapterOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorisation de sortir un chapitre de la corbeille (#689).
 *
 * Règle STRICTEMENT identique à la suppression — d'où le trait partagé plutôt
 * qu'une recopie : restaurer remet du contenu en ligne, ce geste ne peut pas
 * être moins gardé que celui qui l'a retiré.
 *
 * Seule différence : la cible est cherchée `withTrashed()`, sans quoi elle
 * serait par définition introuvable et le refus ressemblerait à un défaut de
 * droits.
 *
 * @see DeleteChapterRequest
 */
final class RestoreChapterRequest extends FormRequest
{
    use ChecksChapterOwnership;

    public function authorize(): bool
    {
        return $this->chapterOwnershipPasses(includeTrashed: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

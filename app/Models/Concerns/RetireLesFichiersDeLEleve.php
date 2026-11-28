<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Eleve\PhotoDeLEleve;
use Illuminate\Support\Facades\Storage;

/**
 * #1657, PR 2 : la purge d'un compte (suppression DÉFINITIVE) emporte ses fichiers d'élève (la photo) : tout son
 * répertoire privé, `eleves/{école}/{élève}`. Le dossier lui-même part par la clé en cascade, sans événement de modèle :
 * le fichier ne partirait jamais sans ce crochet. La mise en corbeille ne touche à rien.
 *
 * Convention `bootXxx`, comme `Auditable`.
 */
trait RetireLesFichiersDeLEleve
{
    public static function bootRetireLesFichiersDeLEleve(): void
    {
        static::forceDeleted(static function (User $compte): void {
            if (is_int($compte->institution_id)) {
                Storage::disk(PhotoDeLEleve::DISQUE)->deleteDirectory(PhotoDeLEleve::repertoire($compte->institution_id, $compte->id));
            }
        });
    }
}

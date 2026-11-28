<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * L'archive d'une classe (#1652) : `classes.archivee_le`, une date, `null` pour une classe active.
 *
 * Une classe archivée ne reçoit plus d'inscription (`StudentEnrolmentService`,
 * `ClasseOuverteParCode`) ; ses élèves, ses notes et ses présences restent lisibles. Sorti du
 * modèle `Classe`, déjà à sa limite de taille (§5) ; la conversion en date est posée ici, à la
 * construction (`initialize{Trait}`), avec le prédicat qui la lit.
 *
 * @property Carbon|null $archivee_le
 */
trait ClasseArchivable
{
    public function initializeClasseArchivable(): void
    {
        $this->mergeCasts(['archivee_le' => 'datetime']);
    }

    public function estArchivee(): bool
    {
        return $this->archivee_le !== null;
    }
}

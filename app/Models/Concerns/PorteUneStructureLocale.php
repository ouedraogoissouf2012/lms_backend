<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Filiere;
use App\Models\NiveauEtude;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La filière et le niveau d'études LOCAUX d'une classe ou d'une matière (#1648, PR B) : `filiere_locale_id`,
 * `niveau_locale_id`. Distincts de `filiere_id` / `niveau_id`, qui portent des ids KLASSCI. Sorti des modèles,
 * `Classe` étant déjà à sa limite de taille (§5).
 *
 * @property int|null $filiere_locale_id
 * @property int|null $niveau_locale_id
 */
trait PorteUneStructureLocale
{
    /**
     * Assignables : les écrivains locaux les posent avec le reste (`Classe::create`, que la garde d'architecture
     * reconnaît comme l'écrivain de la table). Ils ne viennent jamais du client tels quels : l'API parle
     * `filiere_id` / `niveau_id`, que `RattachementALaStructure` traduit après avoir vérifié l'école.
     */
    public function initializePorteUneStructureLocale(): void
    {
        $this->mergeFillable(['filiere_locale_id', 'niveau_locale_id']);
    }

    /** @return BelongsTo<Filiere, $this> */
    public function filiereLocale(): BelongsTo
    {
        return $this->belongsTo(Filiere::class, 'filiere_locale_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<NiveauEtude, $this> */
    public function niveauLocale(): BelongsTo
    {
        return $this->belongsTo(NiveauEtude::class, 'niveau_locale_id')->withoutGlobalScopes();
    }
}

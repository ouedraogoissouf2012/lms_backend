<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Fenêtre de throttle de l'écriture isolée `last_used_at` (minutes).
     * Spec redis-runtime (#374, Requirement 5).
     */
    private const LAST_USED_AT_THROTTLE_MINUTES = 5;

    /**
     * Override tokenable pour bypasser le Global Scope institution.
     * Sans ça, Sanctum ne trouve pas le supradmin (institution_id = NULL)
     * quand le header X-Institution résout une institution spécifique.
     *
     * @return MorphTo<Model, $this>
     */
    public function tokenable()
    {
        return $this->morphTo('tokenable')->withoutGlobalScope('institution');
    }

    /**
     * Throttle de l'UPDATE `last_used_at` émis par Sanctum à CHAQUE requête
     * authentifiée (Guard::updateLastUsedAt → forceFill + save).
     *
     * Ne court-circuite QUE l'écriture isolée de ce champ, quand la valeur
     * précédente date de moins de 5 minutes : création de token, écriture
     * multi-champs et valeur originale null passent toujours par le save()
     * standard. Retourne true (contrat du Guard inchangé) sans requête SQL.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->shouldThrottleLastUsedAtWrite()) {
            return true;
        }

        return parent::save($options);
    }

    private function shouldThrottleLastUsedAtWrite(): bool
    {
        if (! $this->exists || array_keys($this->getDirty()) !== ['last_used_at']) {
            return false;
        }

        $original = $this->getOriginal('last_used_at');

        return $original instanceof \DateTimeInterface
            && $original > now()->subMinutes(self::LAST_USED_AT_THROTTLE_MINUTES);
    }
}

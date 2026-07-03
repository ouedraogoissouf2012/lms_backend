<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Override tokenable pour bypasser le Global Scope institution.
     * Sans ça, Sanctum ne trouve pas le supradmin (institution_id = NULL)
     * quand le header X-Institution résout une institution spécifique.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function tokenable()
    {
        return $this->morphTo('tokenable')->withoutGlobalScope('institution');
    }
}

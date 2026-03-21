<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToInstitution;

class UserClass extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'user_id',
        'klassci_classe_id',
        'classe_nom',
        'classe_libelle',
        'classe_data',
        'synced_at',
        'institution_id',
    ];

    protected $casts = [
        'classe_data' => 'array',
        'synced_at' => 'datetime',
    ];

    // Relation avec User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

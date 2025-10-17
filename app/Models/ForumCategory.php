<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model ForumCategory
 *
 * Catégorie de forum
 */
class ForumCategory extends Model
{
    use HasFactory;

    protected $table = 'forum_categories';

    protected $fillable = [
        'name',
        'description',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Relation: Topics dans cette catégorie
     */
    public function topics(): HasMany
    {
        return $this->hasMany(ForumTopic::class, 'category_id');
    }

    /**
     * Scope: Trier par ordre
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model ForumCategory
 *
 * Catégorie de forum
 */
class ForumCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ForumCategoryFactory> */
    use HasFactory, BelongsToInstitution;

    protected $table = 'forum_categories';

    protected $fillable = [
        'name',
        'description',
        'order',
        'institution_id',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Relation: Topics dans cette catégorie
     *
     * @return HasMany<ForumTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(ForumTopic::class, 'category_id');
    }

    /**
     * Scope: Trier par ordre
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumCategory>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumCategory>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}

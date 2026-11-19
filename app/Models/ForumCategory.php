<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\ForumCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
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
    /** @use HasFactory<ForumCategoryFactory> */
    use BelongsToInstitution, HasFactory;

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
     * @param  Builder<ForumCategory>  $query
     * @return Builder<ForumCategory>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}

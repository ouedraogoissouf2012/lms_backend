<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Import extends Model
{
    use BelongsToInstitution;

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'institution_id',
        'user_id',
        'path',
        'original_name',
        'status',
        'ok_count',
        'error_count',
    ];

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}

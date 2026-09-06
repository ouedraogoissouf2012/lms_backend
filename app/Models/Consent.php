<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentPurpose;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class Consent extends Model
{
    use BelongsToInstitution;

    protected $fillable = [
        'institution_id',
        'user_id',
        'seance_id',
        'purpose',
        'granted',
        'granted_at',
        'revoked_at',
        'actor_user_id',
        'evidence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('consents are append-only');
    }

    public function delete(): ?bool
    {
        throw new LogicException('consents are append-only');
    }
}

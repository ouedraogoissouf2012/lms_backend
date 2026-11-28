<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DecisionDePassage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `PUT /api/classes/{classe}/passages/{eleve}` (#1663) : la décision du personnel et son motif, requis.
 */
final class CorrigerPassageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(DecisionDePassage::DECISIONS)],
            'motif' => ['required', 'string', 'max:500'],
        ];
    }

    public function decision(): string
    {
        return $this->string('decision')->toString();
    }

    public function motif(): string
    {
        return $this->string('motif')->trim()->toString();
    }
}

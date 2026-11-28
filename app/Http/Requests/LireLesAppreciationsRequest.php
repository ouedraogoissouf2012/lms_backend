<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LitLaPeriodeDuBulletin;
use App\Services\Bulletin\PeriodeDuBulletin;
use Illuminate\Foundation\Http\FormRequest;

/** #1677 (B1) : `GET /api/lms/classes/{classe}/appreciations?libelle=&du=&au=`. */
final class LireLesAppreciationsRequest extends FormRequest
{
    use LitLaPeriodeDuBulletin;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return $this->reglesDeLaPeriode('');
    }

    public function periode(): PeriodeDuBulletin
    {
        return $this->laPeriode('');
    }
}

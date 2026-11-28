<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `GET /lms/calendrier?du=&au=` (#1671) : la plage lue, exigée et BORNÉE (au plus PLAGE_MAX_JOURS jours, une année
 * scolaire et sa marge) : une lecture n'est jamais tout l'historique de l'école.
 */
final class LectureDuCalendrierRequest extends FormRequest
{
    public const PLAGE_MAX_JOURS = 400;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if (Carbon::parse($this->du())->diffInDays(Carbon::parse($this->au())) > self::PLAGE_MAX_JOURS) {
                $v->errors()->add('au', sprintf('La plage lue ne dépasse pas %d jours.', self::PLAGE_MAX_JOURS));
            }
        });
    }

    public function du(): string
    {
        return $this->string('du')->toString();
    }

    public function au(): string
    {
        return $this->string('au')->toString();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rapport période (coordo/admin) ou export d'une séance (#726) par seances.id.
 */
class GenerateAttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($this->filled('seance_id')) {
            return $this->canExportSeance($user);
        }

        return $user->isCoordinator() || $user->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_start' => 'nullable|date|date_format:Y-m-d',
            'date_end' => 'nullable|date|date_format:Y-m-d|after_or_equal:date_start',
            'classe_id' => 'nullable|integer|exists:classes,id',
            'seance_id' => 'nullable|integer|exists:seances,id',
            'format' => 'nullable|string|in:pdf,excel,xlsx',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('seance_id')) {
            return;
        }

        if (! $this->has('date_start') && ! $this->has('date_end')) {
            $this->merge([
                'date_start' => now()->subMonth()->format('Y-m-d'),
                'date_end' => now()->format('Y-m-d'),
            ]);
        }
    }

    private function canExportSeance(User $user): bool
    {
        $seance = Seance::query()->find($this->integer('seance_id'));
        if ($seance === null || $seance->institution_id !== $user->institution_id) {
            return false;
        }

        if ($user->isCoordinator() || $user->isAdmin()) {
            return true;
        }

        return $this->container->make(VisioActorAuthorization::class)->teacherOwns($seance, $user);
    }
}

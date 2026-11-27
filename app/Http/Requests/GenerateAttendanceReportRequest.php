<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
            'classe_id' => ['nullable', 'integer', $this->classeDeLEtablissement()],
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

    /**
     * La classe est cherchée dans l'établissement de l'acteur (#1709) : une règle
     * `exists` interroge la table sans le scope de l'école.
     *
     * `where('institution_id', null)` deviendrait un `whereNull` : un acteur sans
     * établissement — que la porte de l'API refuse déjà — n'envoie donc aucune classe.
     */
    private function classeDeLEtablissement(): Exists|string
    {
        $institution = $this->user()?->institution_id;

        return is_int($institution)
            ? Rule::exists('classes', 'id')
            : 'prohibited';
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

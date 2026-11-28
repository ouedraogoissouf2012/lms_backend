<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AttendanceMark;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /lms/seances/{seanceId}/appel` (#1649) : les marques, une par élève.
 *
 * Aucune règle `exists` sur `user_id` : elle validerait l'existence GLOBALE. L'appartenance à la classe de la séance
 * est vérifiée par la source (`LocalSaisieDeLAppel`), qui rend 422 `student_not_in_seance`. Le rôle est vérifié par
 * la route, le droit sur la séance par la source.
 */
final class SaisirLAppelRequest extends FormRequest
{
    /** Une classe tient dans un appel ; au-delà, la requête est fautive (anti-DOS, #548). */
    public const MARQUES_MAX = 500;

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
            'marques' => ['required', 'array', 'min:1', 'max:'.self::MARQUES_MAX],
            'marques.*.user_id' => ['required', 'integer', 'min:1', 'distinct'],
            'marques.*.mark' => ['required', Rule::enum(AttendanceMark::class)],
        ];
    }

    /**
     * @return list<array{user_id: int, mark: AttendanceMark}>
     */
    public function marques(): array
    {
        /** @var list<array{user_id: int|string, mark: string}> $marques */
        $marques = $this->validated('marques');

        return array_map(static fn (array $m): array => [
            'user_id' => (int) $m['user_id'],
            'mark' => AttendanceMark::from($m['mark']),
        ], $marques);
    }
}

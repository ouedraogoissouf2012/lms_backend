<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Validates that a value is a positive integer (> 0).
 *
 * ## Usage
 * Used in FormRequests for IDs that must exist and be positive:
 * - classe_id, matiere_id, enseignant_id, etc.
 *
 * ## Why centralized rule (10-year perspective)
 * - If we need to change validation logic (e.g., "allow 0?"), change ONE place
 * - All 20+ FormRequests automatically use new rule
 * - Testable independently (no need to test in each FormRequest)
 * - Prevents code duplication
 * - New devs see pattern: "use PositiveInteger for IDs"
 *
 * ## Equivalent inline validation would be:
 * 'classe_id' => 'required|integer|min:1'
 *
 * BUT if 50 FormRequests use this, that's 50 duplicates.
 * If rule changes: update 50 places OR 1 custom rule.
 *
 * ## Example in FormRequest
 * 'classe_id' => ['required', new PositiveInteger()],
 *
 * ## Example error message
 * "The classe_id must be a positive integer"
 */
final class PositiveInteger implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     * @param mixed $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (!is_int($value) && !ctype_digit((string) $value)) {
            return false;
        }

        $intValue = (int) $value;

        return $intValue > 0;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute must be a positive integer (> 0).';
    }
}

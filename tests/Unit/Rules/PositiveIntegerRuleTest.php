<?php

namespace Tests\Unit\Rules;

use App\Rules\PositiveInteger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PositiveInteger validation rule
 *
 * Validates that:
 * - Positive integers pass (1, 999, "123")
 * - Zero and negative fail (0, -1, -999)
 * - Non-numeric fail ("abc", "12x", null)
 * - String digits pass ("123") but non-digit strings fail
 *
 * ## 10-year perspective
 * This rule is used in 20+ FormRequests for ID validation.
 * If validation logic changes (e.g., "allow 0?"), this test
 * ensures regression is caught immediately.
 * One place to test = all FormRequests automatically validated.
 */
class PositiveIntegerRuleTest extends TestCase
{
    private PositiveInteger $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new PositiveInteger();
    }

    /**
     * ✅ VALID: Integer 1 passes
     */
    public function test_positive_integer_one_passes(): void
    {
        $this->assertTrue($this->rule->passes('classe_id', 1));
    }

    /**
     * ✅ VALID: Large positive integer passes
     */
    public function test_large_positive_integer_passes(): void
    {
        $this->assertTrue($this->rule->passes('matiere_id', 999999));
    }

    /**
     * ✅ VALID: String digits pass (ctype_digit)
     */
    public function test_string_digits_pass(): void
    {
        $this->assertTrue($this->rule->passes('enseignant_id', '123'));
    }

    /**
     * ❌ INVALID: Zero fails
     */
    public function test_zero_fails(): void
    {
        $this->assertFalse($this->rule->passes('classe_id', 0));
    }

    /**
     * ❌ INVALID: Negative integer fails
     */
    public function test_negative_integer_fails(): void
    {
        $this->assertFalse($this->rule->passes('matiere_id', -5));
    }

    /**
     * ❌ INVALID: Non-numeric string fails
     */
    public function test_non_numeric_string_fails(): void
    {
        $this->assertFalse($this->rule->passes('classe_id', 'abc'));
    }

    /**
     * ❌ INVALID: String with digits and letters fails
     */
    public function test_string_with_mixed_content_fails(): void
    {
        $this->assertFalse($this->rule->passes('matiere_id', '12x'));
    }

    /**
     * ❌ INVALID: Null fails
     */
    public function test_null_fails(): void
    {
        $this->assertFalse($this->rule->passes('enseignant_id', null));
    }

    /**
     * ✅ ERROR MESSAGE: Verify message is human-friendly
     */
    public function test_error_message_is_user_friendly(): void
    {
        $message = $this->rule->message();
        $this->assertStringContainsString('positive integer', $message);
        $this->assertStringContainsString('> 0', $message);
    }
}

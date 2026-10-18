<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Present, and not just whitespace.
 *
 * Laravel's `required` already rejects an empty string, but " " and a line of
 * non-breaking spaces both satisfy it — which is how a hosted event could be
 * published with a blank description that looked filled in.
 */
class NotBlank implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::isBlank($value)) {
            $fail('The :attribute field cannot be empty.');
        }
    }

    public static function isBlank(string $value): bool
    {
        // \s misses the non-breaking and zero-width spaces that paste in from
        // rich text, so they are stripped explicitly before the emptiness test.
        $stripped = preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);

        return ($stripped ?? '') === '';
    }
}

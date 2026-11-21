<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Enforces a minimum password strength.
 *
 * The hash itself uses Argon2id; this rule only makes sure the secret is
 * long and varied enough to be worth hashing.
 */
class PasswordStrength implements ValidationRule
{
    /**
     * Minimum accepted length.
     */
    public const MIN_LENGTH = 10;

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La contraseña debe ser una cadena de texto.');

            return;
        }

        if (mb_strlen($value) < self::MIN_LENGTH) {
            $fail('La contraseña debe tener al menos '.self::MIN_LENGTH.' caracteres.');

            return;
        }

        if (! preg_match('/[a-z]/', $value)) {
            $fail('La contraseña debe incluir al menos una letra minúscula.');

            return;
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $fail('La contraseña debe incluir al menos una letra mayúscula.');

            return;
        }

        if (! preg_match('/\d/', $value)) {
            $fail('La contraseña debe incluir al menos un número.');

            return;
        }
    }
}

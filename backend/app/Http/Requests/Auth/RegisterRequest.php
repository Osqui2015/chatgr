<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordStrength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the first step of registration: the account details.
 */
class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:190',
                Rule::unique('users', 'email'),
            ],
            'nickname' => [
                'required',
                'string',
                'min:3',
                'max:32',
                'regex:/^[\p{L}\p{N}._\- ]+$/u',
                Rule::unique('users', 'nickname'),
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                new PasswordStrength,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta registrada con ese correo.',
            'nickname.unique' => 'Ese nombre de usuario ya está en uso.',
            'nickname.regex' => 'El nombre solo puede contener letras, números, espacios, punto, guion y guion bajo.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo electrónico',
            'nickname' => 'nombre de usuario',
            'password' => 'contraseña',
        ];
    }

    /**
     * Whether the request should be authorised. Registration is public.
     */
    public function authorize(): bool
    {
        return true;
    }
}

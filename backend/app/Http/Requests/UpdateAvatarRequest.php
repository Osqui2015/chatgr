<?php

namespace App\Http\Requests;

use App\Services\ImageService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an avatar upload.
 */
class UpdateAvatarRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimes:'.implode(',', ImageService::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', ImageService::ALLOWED_MIME_TYPES),
                'max:'.ImageService::MAX_UPLOAD_BYTES,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Selecciona una imagen para tu avatar.',
            'avatar.max' => 'La imagen no puede superar los 20 MB.',
            'avatar.mimes' => 'Formato de imagen no permitido.',
            'avatar.mimetypes' => 'Formato de imagen no permitido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'avatar',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

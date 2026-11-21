<?php

namespace App\Http\Requests;

use App\Services\ImageService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a view-once image upload.
 */
class StoreEphemeralImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimes:'.implode(',', ImageService::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', ImageService::ALLOWED_MIME_TYPES),
                'max:'.ImageService::MAX_UPLOAD_BYTES,
            ],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Selecciona una imagen.',
            'image.max' => 'La imagen no puede superar los 20 MB.',
            'image.mimes' => 'Formato de imagen no permitido.',
            'image.mimetypes' => 'Formato de imagen no permitido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => 'imagen',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

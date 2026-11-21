<?php

namespace App\Http\Requests;

use App\Enums\MessageType;
use App\Services\ImageService;
use App\Services\MessageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new chat message.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.MessageService::MAX_BODY_LENGTH],
            'type' => ['sometimes', Rule::enum(MessageType::class)],
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
            'media_uuid' => ['nullable', 'uuid'],
            'image' => [
                'nullable',
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
            'body.max' => 'El mensaje es demasiado largo.',
            'image.max' => 'La imagen no puede superar los 20 MB.',
            'image.mimes' => 'Formato de imagen no permitido.',
            'image.mimetypes' => 'Formato de imagen no permitido.',
            'reply_to_id.exists' => 'El mensaje al que respondes ya no existe.',
            'media_uuid.uuid' => 'La imagen de un solo uso no es válida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'mensaje',
            'image' => 'imagen',
            'media_uuid' => 'imagen de un solo uso',
            'reply_to_id' => 'mensaje original',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * A message must carry either text or an image.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasBody = trim((string) $this->input('body')) !== '';
            $hasImage = $this->hasFile('image');
            $hasMediaUuid = trim((string) $this->input('media_uuid')) !== '';
            $isViewOnce = $this->input('type') === MessageType::ViewOnce->value;

            if ($isViewOnce) {
                if (! $hasMediaUuid) {
                    $validator->errors()->add('media_uuid', 'Adjunta una imagen de un solo uso.');
                }

                if ($hasImage) {
                    $validator->errors()->add('image', 'La imagen de un solo uso se sube por separado.');
                }

                if ($hasBody) {
                    $validator->errors()->add('body', 'La imagen de un solo uso no lleva texto.');
                }

                return;
            }

            if ($hasMediaUuid) {
                $validator->errors()->add('media_uuid', 'La imagen de un solo uso requiere el tipo correspondiente.');
            }

            if (! $hasBody && ! $hasImage) {
                $validator->errors()->add('body', 'Escribe un mensaje o adjunta una imagen.');
            }
        });
    }
}

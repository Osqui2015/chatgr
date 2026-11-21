<?php

namespace App\Services;

use RuntimeException;

/**
 * Raised when an image cannot be decoded, oriented or encoded.
 */
class ImageProcessingException extends RuntimeException
{
    public function __construct(string $message = 'No se pudo procesar la imagen.')
    {
        parent::__construct($message);
    }
}

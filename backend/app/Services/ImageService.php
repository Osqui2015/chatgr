<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Decodes, sanitises and re-encodes uploaded images.
 *
 * Every uploaded image is fully re-encoded, which strips EXIF metadata and any
 * embedded payload by construction. Orientation is corrected before resizing so
 * avatars are never stored sideways.
 */
class ImageService
{
    /**
     * Mime types accepted for avatars and chat images.
     *
     * @var array<int, string>
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Extensions accepted for avatars, including HEIC which is transcoded.
     *
     * @var array<int, string>
     */
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif',
    ];

    /**
     * The edge length of each generated avatar variant.
     *
     * @var array<int, int>
     */
    public const AVATAR_SIZES = [32, 64, 128, 512];

    /**
     * Quality used when encoding WebP avatars.
     */
    public const AVATAR_QUALITY = 82;

    /**
     * Maximum accepted upload size in bytes (20 MB).
     */
    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    /**
     * Sanitise an uploaded file: correct orientation and strip metadata.
     *
     * The result is a clean, fully re-encoded PNG. Re-encoding from the decoded
     * pixel buffer is what removes EXIF: the output image simply has no
     * metadata segments.
     */
    public function sanitize(UploadedFile $file): string
    {
        $source = $this->decode($file->getRealPath());

        try {
            $oriented = $this->orient($source, $this->readOrientation($file->getRealPath()));

            try {
                return $this->encodePng($oriented);
            } finally {
                if ($oriented !== $source) {
                    imagedestroy($oriented);
                }
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Convert an arbitrary image payload to a WebP binary.
     */
    public function toWebp(string $binary, int $quality = self::AVATAR_QUALITY): string
    {
        if (! function_exists('imagewebp')) {
            throw new ImageProcessingException('La extensión GD no soporta WebP en este servidor.');
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            throw new ImageProcessingException('El archivo no es una imagen válida.');
        }

        try {
            $oriented = $this->orient($source, $this->readOrientationFromBinary($binary));

            try {
                ob_start();
                $ok = imagewebp($oriented, null, $quality);
                $webp = ob_get_clean();

                if ($ok === false || $webp === false) {
                    throw new ImageProcessingException('No se pudo convertir la imagen a WebP.');
                }

                return $webp;
            } finally {
                if ($oriented !== $source) {
                    imagedestroy($oriented);
                }
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Generate the avatar size variants from a square source image.
     *
     * @return array<int, string> A map of size => WebP binary.
     */
    public function generateAvatarVariants(string $sourceBinary): array
    {
        $source = @imagecreatefromstring($sourceBinary);

        if ($source === false) {
            throw new ImageProcessingException('El archivo no es una imagen válida.');
        }

        try {
            $oriented = $this->orient($source, $this->readOrientationFromBinary($sourceBinary));

            try {
                return $this->renderVariants($oriented);
            } finally {
                if ($oriented !== $source) {
                    imagedestroy($oriented);
                }
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Read the pixel dimensions of an encoded image.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $binary): array
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            return [0, 0];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Store a set of avatar variants on disk.
     *
     * @param  array<int, string>  $variants
     * @return string The path of the 128px variant, used as the canonical file.
     */
    public function storeAvatarVariants(array $variants, int $userId): string
    {
        $disk = Storage::disk('public');
        $directory = "avatars/{$userId}";
        $canonicalPath = null;

        foreach ($variants as $size => $binary) {
            $filename = "{$size}.webp";
            $disk->put("{$directory}/{$filename}", $binary);

            if ($size === 128) {
                $canonicalPath = "{$directory}/{$filename}";
            }
        }

        if ($canonicalPath === null) {
            throw new ImageProcessingException('No se pudo guardar el avatar.');
        }

        return $canonicalPath;
    }

    /**
     * Delete previously stored avatar variants.
     */
    public function deleteAvatarVariants(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $disk = Storage::disk('public');
        $directory = Str::beforeLast($path, '/');

        foreach ($disk->files($directory) as $file) {
            $disk->delete($file);
        }

        $disk->deleteDirectory($directory);
    }

    /**
     * Decode a file into a GD image, transparently handling formats GD cannot
     * read natively (HEIC/HEIF) by delegating to Imagick when available.
     */
    private function decode(string $path): \GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image !== false) {
            return $image;
        }

        if (extension_loaded('imagick')) {
            $imagick = new \Imagick($path);

            if ($imagick->getNumberImages() > 1) {
                $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            }

            $imagick->setImageFormat('png');
            $blob = $imagick->getImageBlob();
            $imagick->clear();

            $decoded = @imagecreatefromstring($blob);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        throw new ImageProcessingException('El archivo no es una imagen válida o el formato no es compatible.');
    }

    /**
     * Apply an EXIF orientation value to a decoded image.
     */
    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            2 => $this->flipHorizontal($image),
            3 => $this->rotate($image, 180),
            4 => $this->flipVertical($image),
            5 => $this->rotate($this->flipHorizontal($image), 270),
            6 => $this->rotate($image, 270),
            7 => $this->rotate($this->flipHorizontal($image), 90),
            8 => $this->rotate($image, 90),
            default => $image,
        };

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }

    private function rotate(\GdImage $image, int $degrees): \GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        return $rotated === false ? $image : $rotated;
    }

    private function flipHorizontal(\GdImage $image): \GdImage
    {
        imageflip($image, IMG_FLIP_HORIZONTAL);

        return $image;
    }

    private function flipVertical(\GdImage $image): \GdImage
    {
        imageflip($image, IMG_FLIP_VERTICAL);

        return $image;
    }

    /**
     * Render the square avatar variants from an already oriented image.
     *
     * @return array<int, string>
     */
    private function renderVariants(\GdImage $source): array
    {
        $side = min(imagesx($source), imagesy($source));
        $offsetX = (int) ((imagesx($source) - $side) / 2);
        $offsetY = (int) ((imagesy($source) - $side) / 2);

        $variants = [];

        foreach (self::AVATAR_SIZES as $size) {
            $canvas = imagecreatetruecolor($size, $size);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);

            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                $offsetX,
                $offsetY,
                $size,
                $size,
                $side,
                $side,
            );

            ob_start();
            $ok = imagewebp($canvas, null, self::AVATAR_QUALITY);
            $webp = ob_get_clean();

            if ($ok === false || $webp === false) {
                imagedestroy($canvas);

                throw new ImageProcessingException('No se pudo generar la variante del avatar.');
            }

            $variants[$size] = $webp;

            imagedestroy($canvas);
        }

        return $variants;
    }

    /**
     * Encode a GD image as a metadata-free PNG.
     */
    private function encodePng(\GdImage $image): string
    {
        ob_start();
        $ok = imagepng($image, null, 6);
        $png = ob_get_clean();

        if ($ok === false || $png === false) {
            throw new ImageProcessingException('No se pudo procesar la imagen.');
        }

        return $png;
    }

    /**
     * Read the EXIF orientation of a file on disk.
     */
    private function readOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);

        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return 1;
        }

        return (int) $exif['Orientation'];
    }

    /**
     * Read the EXIF orientation from an in-memory image payload.
     */
    private function readOrientationFromBinary(string $binary): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'chat-exif');

        if ($temporary === false) {
            return 1;
        }

        try {
            file_put_contents($temporary, $binary);

            return $this->readOrientation($temporary);
        } finally {
            @unlink($temporary);
        }
    }
}

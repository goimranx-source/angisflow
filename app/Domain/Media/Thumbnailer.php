<?php

declare(strict_types=1);

namespace App\Domain\Media;

use GdImage;

/**
 * Makes the small copy the grid actually shows.
 *
 * ── Why this exists at all ───────────────────────────────────────────────────
 *
 * A picker showing forty tiles of four-megabyte phone photographs downloads a
 * hundred and sixty megabytes to draw forty 160-pixel squares. Lazy loading
 * delays that; it does not shrink it. One derivative per file, made once on
 * upload, is the only fix that scales.
 *
 * ── WebP where the browser will take it ──────────────────────────────────────
 *
 * WebP is roughly a third smaller than JPEG at the same visual quality and
 * every browser this tool supports has read it for years. It is used when the
 * server's GD was built with it and JPEG is the fallback, so an unusual host
 * degrades to something slower rather than to something broken.
 *
 * ── And why not Imagick ──────────────────────────────────────────────────────
 *
 * GD is compiled into virtually every PHP install; Imagick is an extension a
 * shared host may refuse to add. Choosing the one that is always there means a
 * subscriber's library works on whatever they deploy to. Imagick would give
 * better resampling and PDF previews — worth revisiting the day PDFs need
 * thumbnails.
 */
final class Thumbnailer
{
    /** The longest edge a preview is allowed. Twice 200px, for dense screens. */
    public const MAX_EDGE = 400;

    /** Types worth shrinking. SVG scales for free; a PDF needs Imagick. */
    private const RESIZABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public static function canHandle(string $mime): bool
    {
        return extension_loaded('gd') && in_array($mime, self::RESIZABLE, true);
    }

    /**
     * Produce a thumbnail's bytes from a source file.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}|null
     *         [binary, extension, width, height], or null when there is nothing
     *         useful to make
     */
    public static function make(string $sourcePath, string $mime): ?array
    {
        if (! self::canHandle($mime)) {
            return null;
        }

        $source = self::read($sourcePath, $mime);

        if ($source === null) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            // Already small enough. Re-encoding it would spend CPU to produce a
            // file the same size, and would lose a little quality doing it.
            if ($width <= self::MAX_EDGE && $height <= self::MAX_EDGE) {
                return null;
            }

            $scale = self::MAX_EDGE / max($width, $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $thumb = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($thumb === false) {
                return null;
            }

            try {
                // Transparency has to be set up *before* anything is drawn, or
                // a PNG with a transparent background comes out on black.
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));

                imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                [$binary, $extension] = self::encode($thumb);

                return $binary === null ? null : [$binary, $extension, $targetWidth, $targetHeight];
            } finally {
                imagedestroy($thumb);
            }
        } finally {
            imagedestroy($source);
        }
    }

    private static function read(string $path, string $mime): ?GdImage
    {
        // Suppressed rather than trusted: a file whose bytes do not match its
        // declared type makes these emit a warning and return false, and a bad
        // upload should be "no thumbnail" rather than a 500.
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function encode(GdImage $image): array
    {
        $buffer = fopen('php://memory', 'r+');

        if ($buffer === false) {
            return [null, 'webp'];
        }

        try {
            $supportsWebp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);

            if ($supportsWebp) {
                // 82 is the knee of the quality curve — visually indistinguishable
                // from 100 at thumbnail size, and roughly half the bytes.
                $ok = imagewebp($image, $buffer, 82);
                $extension = 'webp';
            } else {
                // PNG rather than JPEG: the alpha channel set up above would be
                // flattened onto black by JPEG, and a logo is the commonest
                // thing in this library.
                $ok = imagepng($image, $buffer, 6);
                $extension = 'png';
            }

            if (! $ok) {
                return [null, $extension];
            }

            rewind($buffer);

            return [stream_get_contents($buffer) ?: null, $extension];
        } finally {
            fclose($buffer);
        }
    }
}

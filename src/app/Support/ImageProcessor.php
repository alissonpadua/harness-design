<?php

declare(strict_types=1);

namespace App\Support;

/**
 * GD re-encode boundary (spec 006): bytes in, normalized WEBP out. Decoding
 * via GD strips polyglot/EXIF payloads; dimension cap prevents decompression
 * bombs at render time. Throws RuntimeException on anything undecodable.
 */
final readonly class ImageProcessor
{
    public function __construct(private int $maxDimension = 1024, private int $webpQuality = 82) {}

    public function toNormalizedWebp(string $bytes): string
    {
        $im = @imagecreatefromstring($bytes);

        if ($im === false) {
            throw new \RuntimeException('The uploaded image could not be decoded.');
        }

        $w = imagesx($im);
        $h = imagesy($im);

        $scale = min(1, $this->maxDimension / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($tw, $th);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        imagecopyresampled($canvas, $im, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagewebp($canvas, null, $this->webpQuality);
        $out = (string) ob_get_clean();
        imagedestroy($im);
        imagedestroy($canvas);

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Screenshots;

final class Image
{
    public const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    /** Converts a rendered PNG to $format, scaled down to $thumbWidth if given. */
    public static function convert(string $source, string $target, string $format, ?int $thumbWidth): void
    {
        $image = @imagecreatefrompng($source);
        if ($image === false) {
            throw new \RuntimeException("Cannot read $source");
        }
        imagepalettetotruecolor($image); // imagewebp() refuses palette images

        $width = imagesx($image);
        if ($thumbWidth !== null && $thumbWidth < $width) {
            $height = max(1, (int) round(imagesy($image) * $thumbWidth / $width));
            $thumb = imagecreatetruecolor($thumbWidth, $height);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $height, $width, imagesy($image));
            $image = $thumb;
        }

        $ok = match ($format) {
            'png' => imagepng($image, $target),
            'jpg' => imagejpeg($image, $target, 85),
            'webp' => imagewebp($image, $target, 82),
        };
        if (!$ok) {
            throw new \RuntimeException("Cannot write $target");
        }
    }
}

<?php
// image_convert.php - GD WebP conversion
declare(strict_types=1);

function gd_supports_webp_convert(): bool {
    return extension_loaded('gd') && function_exists('imagewebp');
}

function gd_image_from_file(string $path, string $format): ?\GdImage {
    if (!extension_loaded('gd') || !is_file($path)) return null;
    $im = match ($format) {
        'jpg'  => @imagecreatefromjpeg($path),
        'png'  => @imagecreatefrompng($path),
        'gif'  => @imagecreatefromgif($path),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'bmp'  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
        'avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
        default => false,
    };
    return $im instanceof \GdImage ? $im : null;
}

function convert_image_to_webp(string $src, string $dest, string $format, int $quality = 85): bool {
    if (!gd_supports_webp_convert()) return false;
    $im = gd_image_from_file($src, $format);
    if ($im === null) return false;
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($im);
    }
    imagealphablending($im, true);
    imagesavealpha($im, true);
    $ok = imagewebp($im, $dest, max(0, min(100, $quality)));
    imagedestroy($im);
    return $ok && is_file($dest) && filesize($dest) > 0;
}

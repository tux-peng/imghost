<?php
// image_convert.php - GD WebP and ImageMagick JPEG XL conversion
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

function imagemagick_binary(): ?string {
    static $bin = false;
    if ($bin !== false) return $bin;
    foreach (['magick', 'convert'] as $name) {
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out[0])) {
            $bin = $out[0];
            return $bin;
        }
    }
    $bin = null;
    return $bin;
}

function imagemagick_supports_jxl_convert(): bool {
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $fmts = array_map('strtoupper', \Imagick::queryFormats());
            if (in_array('JXL', $fmts, true)) return true;
        } catch (Throwable $e) {
            // fall through to CLI
        }
    }
    $bin = imagemagick_binary();
    if ($bin === null) return false;
    $out = [];
    $code = 1;
    @exec(escapeshellarg($bin) . ' -list format 2>/dev/null', $out, $code);
    $blob = strtoupper(implode("\n", $out));
    return str_contains($blob, ' JXL ');
}

function convert_image_to_jxl(string $src, string $dest, string $format, int $quality = 85): bool {
    if (!is_file($src) || $format === '') return false;
    $quality = max(1, min(100, $quality));

    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $im = new \Imagick();
            $im->readImage($src);
            $im->setImageFormat('jxl');
            if (method_exists($im, 'setImageCompressionQuality')) {
                $im->setImageCompressionQuality($quality);
            }
            $ok = $im->writeImage($dest);
            $im->clear();
            $im->destroy();
            if ($ok && is_file($dest) && filesize($dest) > 0) return true;
        } catch (Throwable $e) {
            // try CLI
        }
    }

    $bin = imagemagick_binary();
    if ($bin === null) return false;
    $cmd = escapeshellarg($bin);
    if (basename($bin) === 'magick') {
        $cmd .= ' convert';
    }
    $cmd .= ' ' . escapeshellarg($src) . ' -quality ' . (int) $quality . ' ' . escapeshellarg($dest) . ' 2>/dev/null';
    $code = 1;
    @exec($cmd, $unused, $code);
    return $code === 0 && is_file($dest) && filesize($dest) > 0;
}

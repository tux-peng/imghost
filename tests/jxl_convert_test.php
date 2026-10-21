<?php
// tests/jxl_convert_test.php - ImageMagick conversion to JPEG XL
declare(strict_types=1);

require_once dirname(__DIR__) . '/image_convert.php';

$failed = 0;
$passed = 0;

function expect_true(bool $cond, string $msg): void {
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK  $msg\n";
    } else {
        $failed++;
        echo "FAIL $msg\n";
    }
}

expect_true(function_exists('convert_image_to_jxl'), 'convert helper is defined');
expect_true(function_exists('imagemagick_supports_jxl_convert'), 'imagemagick support helper is defined');

if (!imagemagick_supports_jxl_convert()) {
    echo "SKIP conversion: ImageMagick JXL not available\n";
    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

$dir = sys_get_temp_dir() . '/imghost_jxl_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);

$png = $dir . '/src.png';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(8, 8);
    $c = imagecolorallocate($im, 10, 20, 30);
    imagefilledrectangle($im, 0, 0, 7, 7, $c);
    imagepng($im, $png);
    imagedestroy($im);
} else {
    // 1x1 PNG
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}

$out = $dir . '/out.jxl';
expect_true(convert_image_to_jxl($png, $out, 'png'), 'png converts to jxl');
expect_true(is_file($out) && filesize($out) > 0, 'jxl file written');

$head = bin2hex((string) file_get_contents($out, false, null, 0, 12));
expect_true(str_starts_with($head, 'ff0a') || str_starts_with($head, '0000000c4a584c20'), 'jxl magic bytes');

expect_true(!convert_image_to_jxl($dir . '/missing.png', $dir . '/no.jxl', 'png'), 'missing file fails');

@unlink($png);
@unlink($out);
@rmdir($dir);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

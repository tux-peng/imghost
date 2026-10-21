<?php
// tests/webp_convert_test.php - GD conversion to WebP
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

expect_true(function_exists('convert_image_to_webp'), 'convert helper is defined');
expect_true(function_exists('gd_supports_webp_convert'), 'gd support helper is defined');

if (!gd_supports_webp_convert()) {
    echo "SKIP conversion: GD WebP not available\n";
    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

$dir = sys_get_temp_dir() . '/imghost_webp_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);

$png = $dir . '/src.png';
$im = imagecreatetruecolor(8, 8);
$c = imagecolorallocate($im, 10, 20, 30);
imagefilledrectangle($im, 0, 0, 7, 7, $c);
imagepng($im, $png);
imagedestroy($im);

$out = $dir . '/out.webp';
expect_true(convert_image_to_webp($png, $out, 'png'), 'png converts to webp');
expect_true(is_file($out) && filesize($out) > 0, 'webp file written');

$head = bin2hex((string) file_get_contents($out, false, null, 0, 12));
expect_true(str_starts_with($head, '52494646') && substr($head, 16, 8) === '57454250', 'webp magic bytes');

expect_true(!convert_image_to_webp($png, $dir . '/bad.webp', 'jxl'), 'unsupported format fails');
expect_true(!convert_image_to_webp($dir . '/missing.png', $dir . '/no.webp', 'png'), 'missing file fails');

@unlink($png);
@unlink($out);
@rmdir($dir);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

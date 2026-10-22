<?php
// tests/image_url_test.php - wget-friendly direct URLs
declare(strict_types=1);

require_once dirname(__DIR__) . '/image_urls.php';

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

function expect_eq(mixed $got, mixed $want, string $msg): void {
    expect_true($got === $want, $msg . ' (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')');
}

expect_eq(parse_image_slug('Ab12Cd34'), 'Ab12Cd34', 'query slug');
expect_eq(parse_image_slug('no'), null, 'short query slug rejected');
expect_eq(parse_image_slug('not a slug!'), null, 'invalid query slug rejected');
expect_eq(parse_image_slug(null, '/Ab12Cd34.webp'), 'Ab12Cd34', 'PATH_INFO with extension');
expect_eq(parse_image_slug(null, '/Ab12Cd34'), 'Ab12Cd34', 'PATH_INFO without extension');
expect_eq(parse_image_slug(null, null, '/img/i.php/Ab12Cd34.webp'), 'Ab12Cd34', 'REQUEST_URI i.php/file');
expect_eq(parse_image_slug(null, null, '/i/Ab12Cd34.webp'), 'Ab12Cd34', 'REQUEST_URI /i/file');
expect_eq(parse_image_slug(null, null, '/img/i.php/Ab12Cd34.webp?d=1'), 'Ab12Cd34', 'REQUEST_URI ignores query');
expect_eq(parse_image_slug(null, null, '/index.php'), null, 'unrelated script not parsed as slug');
expect_eq(parse_image_slug(null, null, '/view.php?s=Ab12Cd34'), null, 'view.php URI is not a direct image URL');
expect_eq(parse_image_slug('Ab12Cd34', '/other.webp'), 'Ab12Cd34', 'query wins over PATH_INFO');

expect_eq(image_direct_path('Ab12Cd34.webp'), '/i.php/Ab12Cd34.webp', 'direct path uses filename');
expect_eq(image_direct_path('../Ab12Cd34.webp'), '/i.php/Ab12Cd34.webp', 'basename only');
expect_eq(image_direct_path(''), '/i.php', 'empty filename');

$embed = image_embed_tag('https://ex.tld/i.php/Ab12Cd34.webp', 'photo "x"');
expect_eq(
    $embed,
    '<img src="https://ex.tld/i.php/Ab12Cd34.webp" alt="photo &quot;x&quot;">',
    'html embed escapes alt'
);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

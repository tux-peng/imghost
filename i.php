<?php
// i.php - direct image delivery with proper mime + caching
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$slug = parse_image_slug(
    isset($_GET['s']) ? (string) $_GET['s'] : null,
    isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : null,
    isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null
);
if ($slug === null) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare('SELECT * FROM images WHERE slug = ?');
$stmt->execute([$slug]);
$img = $stmt->fetch();
if (!$img) {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/config.php';
$path = $config['upload_path'] . '/' . $img['filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$etag = '"' . sha1($img['slug'] . filemtime($path) . filesize($path)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Type: ' . $img['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
$safe_name = basename((string) $img['filename']);
if (!empty($_GET['d'])) {
    $dl = basename((string) $img['original_name']);
    if ($dl === '' || $dl === '.' || $dl === '..') {
        $dl = $safe_name;
    }
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $dl) . '"');
} else {
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $safe_name) . '"');
}

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

readfile($path);

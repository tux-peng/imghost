<?php
// image_urls.php - wget-friendly direct image URLs
declare(strict_types=1);

/**
 * Resolve an image slug from query string, PATH_INFO, or /i/{slug}.ext URLs.
 */
function parse_image_slug(?string $query_s = null, ?string $path_info = null, ?string $request_uri = null): ?string {
    if (is_string($query_s) && preg_match('/^[A-Za-z0-9]{4,16}$/', $query_s)) {
        return $query_s;
    }

    if (is_string($path_info) && $path_info !== '') {
        if (preg_match('~/?([A-Za-z0-9]{4,16})(?:\.[A-Za-z0-9]+)?/?$~', $path_info, $m)) {
            return $m[1];
        }
    }

    if (is_string($request_uri) && $request_uri !== '') {
        $path = parse_url($request_uri, PHP_URL_PATH);
        if (is_string($path) && preg_match('~/i(?:\.php)?/([A-Za-z0-9]{4,16})(?:\.[A-Za-z0-9]+)?/?$~', $path, $m)) {
            return $m[1];
        }
    }

    return null;
}

/** Path wget can save as slug.ext (no query string). */
function image_direct_path(string $filename): string {
    $filename = basename($filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '/i.php';
    }
    return '/i.php/' . $filename;
}

function image_embed_tag(string $direct_url, string $alt = ''): string {
    return '<img src="' . $direct_url . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
}

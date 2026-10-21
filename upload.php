<?php
// upload.php - receive images, validate, store
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    die('Log in first.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(400);
    die('Bad request.');
}

// Normalize $_FILES['image'] to a flat array of individual file entries.
$raw = $_FILES['image'] ?? null;
if (!$raw || !isset($raw['name'])) {
    http_response_code(400);
    die('No file received.');
}

if (is_array($raw['name'])) {
    $files = [];
    foreach ($raw['name'] as $i => $name) {
        $files[] = [
            'name'     => $name,
            'tmp_name' => $raw['tmp_name'][$i],
            'error'    => (int) $raw['error'][$i],
            'size'     => (int) $raw['size'][$i],
        ];
    }
} else {
    $files = [[
        'name'     => $raw['name'],
        'tmp_name' => $raw['tmp_name'],
        'error'    => (int) $raw['error'],
        'size'     => (int) $raw['size'],
    ]];
}

$files = array_filter($files, fn($f) => $f['error'] !== UPLOAD_ERR_NO_FILE);
if (empty($files)) {
    http_response_code(400);
    die('No file received.');
}

// Resolve gallery: create new or use existing.
$gallery_id   = null;
$gallery_slug = null;

$gallery_sel  = trim($_POST['gallery_id'] ?? '0');
$new_gal_name = trim($_POST['new_gallery_name'] ?? '');

if ($new_gal_name !== '') {
    $g_slug = unique_gallery_slug();
    try {
        $stmt = db()->prepare(
            'INSERT INTO galleries (user_id, slug, name, description, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user['id'], $g_slug, substr($new_gal_name, 0, 100), '', time()]);
        $gallery_id   = (int) db()->lastInsertId();
        $gallery_slug = $g_slug;
    } catch (Throwable $e) {
        http_response_code(500);
        die('Could not create gallery.');
    }
} elseif ($gallery_sel !== '0' && $gallery_sel !== 'new' && ctype_digit($gallery_sel)) {
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $gallery_sel, $user['id']]);
    $gallery = $stmt->fetch();
    if (!$gallery) {
        http_response_code(400);
        die('Gallery not found.');
    }
    $gallery_id   = (int) $gallery['id'];
    $gallery_slug = $gallery['slug'];
}

// Process each file.
$config = require __DIR__ . '/config.php';
$dir    = $config['upload_path'];
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$max     = get_max_size_mb() * 1024 * 1024;
$allowed = get_allowed_formats();
$slugs   = [];
$errors  = [];

foreach ($files as $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large for the server.',
            UPLOAD_ERR_PARTIAL   => 'Upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the file.',
            default => 'Upload failed.',
        };
        $errors[] = h($file['name']) . ': ' . $msg;
        continue;
    }

    $tmp  = $file['tmp_name'];
    $orig = $file['name'];
    $size = $file['size'];

    if (!is_uploaded_file($tmp)) {
        $errors[] = h($orig) . ': Bad upload.';
        continue;
    }

    if ($size > $max) {
        $errors[] = h($orig) . ': Exceeds the ' . get_max_size_mb() . ' MB limit.';
        continue;
    }

    $format = detect_format($tmp, $orig);
    if (!$format) {
        $errors[] = h($orig) . ': Could not identify image format.';
        continue;
    }

    if (!in_array($format, $allowed, true)) {
        $errors[] = h($orig) . ': Format (' . h($format) . ') not allowed.';
        continue;
    }

    $slug          = unique_slug();
    $convert_jxl   = in_array($format, get_convert_to_jxl_formats(), true) && $format !== 'jxl';
    $convert_webp  = !$convert_jxl && in_array($format, get_convert_to_webp_formats(), true) && $format !== 'webp';

    if ($convert_jxl) {
        $ext      = 'jxl';
        $filename = $slug . '.' . $ext;
        $dest     = $dir . '/' . $filename;
        if (!convert_image_to_jxl($tmp, $dest, $format)) {
            $errors[] = h($orig) . ': Could not convert to JPEG XL (ImageMagick).';
            continue;
        }
        $format = 'jxl';
        $size   = (int) filesize($dest);
    } elseif ($convert_webp) {
        $ext      = 'webp';
        $filename = $slug . '.' . $ext;
        $dest     = $dir . '/' . $filename;
        if (!convert_image_to_webp($tmp, $dest, $format)) {
            $errors[] = h($orig) . ': Could not convert to WebP (GD).';
            continue;
        }
        $format = 'webp';
        $size   = (int) filesize($dest);
    } else {
        $ext      = format_extension($format);
        $filename = $slug . '.' . $ext;
        $dest     = $dir . '/' . $filename;

        if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = h($orig) . ': Could not save file.';
            continue;
        }
    }
    @chmod($dest, 0644);

    try {
        $stmt = db()->prepare(
            'INSERT INTO images (user_id, gallery_id, slug, filename, original_name, format, mime, size, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'], $gallery_id, $slug, $filename, substr($orig, 0, 255),
            $format, format_mime($format), $size, time(),
        ]);
        $slugs[] = $slug;
    } catch (Throwable $e) {
        @unlink($dest);
        $errors[] = h($orig) . ': Could not save record.';
    }
}

if (empty($slugs)) {
    http_response_code(400);
    die($errors ? implode("\n", $errors) : 'No files were uploaded.');
}

if ($gallery_slug) {
    header('Location: gallery.php?s=' . urlencode($gallery_slug));
} elseif (count($slugs) === 1) {
    header('Location: view.php?s=' . urlencode($slugs[0]));
} else {
    header('Location: index.php?mine=1');
}

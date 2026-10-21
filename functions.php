<?php
// functions.php - shared helpers
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gallery_actions.php';
require_once __DIR__ . '/password_actions.php';
require_once __DIR__ . '/image_urls.php';
require_once __DIR__ . '/image_convert.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

// All formats this script can serve. Keys are slugs we use internally.
// 'mimes' lists every mime that legitimately maps to that format.
// 'magic' lists hex byte signatures (offset => hex string) used to verify files.
function all_formats(): array {
    return [
        'webp' => ['ext' => ['webp'], 'mimes' => ['image/webp'], 'label' => 'WebP'],
        'avif' => ['ext' => ['avif'], 'mimes' => ['image/avif', 'image/avif-sequence'], 'label' => 'AVIF'],
        'png'  => ['ext' => ['png'],  'mimes' => ['image/png', 'image/apng'], 'label' => 'PNG'],
        'jpg'  => ['ext' => ['jpg', 'jpeg'], 'mimes' => ['image/jpeg', 'image/pjpeg'], 'label' => 'JPEG'],
        'gif'  => ['ext' => ['gif'],  'mimes' => ['image/gif'], 'label' => 'GIF'],
        'jp2'  => ['ext' => ['jp2', 'j2k', 'jpf', 'jpx'], 'mimes' => ['image/jp2', 'image/jpx', 'image/jpeg2000'], 'label' => 'JPEG 2000'],
        'jxl'  => ['ext' => ['jxl'],  'mimes' => ['image/jxl'], 'label' => 'JPEG XL'],
        'bmp'  => ['ext' => ['bmp'],  'mimes' => ['image/bmp', 'image/x-ms-bmp'], 'label' => 'BMP'],
        'tiff' => ['ext' => ['tif', 'tiff'], 'mimes' => ['image/tiff'], 'label' => 'TIFF'],
        'heic' => ['ext' => ['heic', 'heif'], 'mimes' => ['image/heic', 'image/heif'], 'label' => 'HEIC/HEIF'],
        'ico'  => ['ext' => ['ico'],  'mimes' => ['image/x-icon', 'image/vnd.microsoft.icon'], 'label' => 'ICO'],
    ];
}

function default_allowed_formats(): array {
    return ['webp', 'avif'];
}

function get_setting(string $key, ?string $default = null): ?string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE ' . settings_key_sql() . ' = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function set_setting(string $key, string $value): void {
    $stmt = db()->prepare(upsert_setting_sql());
    $stmt->execute([$key, $value]);
}

function get_allowed_formats(): array {
    $raw = get_setting('allowed_formats');
    if ($raw === null) return default_allowed_formats();
    $list = array_filter(array_map('trim', explode(',', $raw)));
    $valid = array_keys(all_formats());
    return array_values(array_intersect($list, $valid));
}

function get_registration_mode(): string {
    $m = get_setting('registration_mode', 'invite');
    return in_array($m, ['open', 'invite', 'closed'], true) ? $m : 'invite';
}

function get_default_theme(): string {
    $t = get_setting('default_theme', 'auto');
    return in_array($t, ['light', 'dark', 'auto'], true) ? $t : 'auto';
}

function get_max_size_mb(): int {
    return max(1, (int) get_setting('max_size_mb', '15'));
}

function get_convert_to_webp_formats(): array {
    $raw = get_setting('convert_to_webp', '');
    if ($raw === null || $raw === '') return [];
    $list = array_filter(array_map('trim', explode(',', $raw)));
    $valid = array_keys(all_formats());
    $list = array_values(array_intersect($list, $valid));
    return array_values(array_diff($list, ['webp']));
}

function get_convert_to_jxl_formats(): array {
    $raw = get_setting('convert_to_jxl', '');
    if ($raw === null || $raw === '') return [];
    $list = array_filter(array_map('trim', explode(',', $raw)));
    $valid = array_keys(all_formats());
    $list = array_values(array_intersect($list, $valid));
    return array_values(array_diff($list, ['jxl']));
}

function get_site_name(): string {
    return get_setting('site_name', 'Image Host') ?? 'Image Host';
}

// CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

// Auth
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function require_admin(): void {
    $u = current_user();
    if (!$u || !$u['is_admin']) {
        http_response_code(403);
        die('Admin only.');
    }
}

function login_user(int $id): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Image validation: extension is just a hint; we require either a matching
// finfo mime *or* a matching magic-byte signature before accepting a file.
function detect_format(string $path, string $original_name): ?string {
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path) ?: '';

    // Read first 32 bytes for magic-byte verification of formats finfo can miss.
    $head = '';
    $fh = fopen($path, 'rb');
    if ($fh) { $head = fread($fh, 32) ?: ''; fclose($fh); }

    // 1) Magic bytes are the strongest signal — try those across every format.
    foreach (all_formats() as $slug => $info) {
        if (verify_magic($slug, $head)) return $slug;
    }

    // 2) Fall back to finfo's mime if it gave us a recognised image type.
    if ($mime !== '') {
        foreach (all_formats() as $slug => $info) {
            if (in_array($mime, $info['mimes'], true)) {
                // Sanity check: extension should at least be plausible if given.
                if ($ext === '' || in_array($ext, $info['ext'], true)) return $slug;
            }
        }
    }

    // Extension alone is never enough.
    return null;
}

function verify_magic(string $slug, string $head): bool {
    if (strlen($head) < 4) return false;
    $h = bin2hex($head);
    switch ($slug) {
        case 'png':  return str_starts_with($h, '89504e470d0a1a0a');
        case 'jpg':  return str_starts_with($h, 'ffd8ff');
        case 'gif':  return str_starts_with($h, '474946383761') || str_starts_with($h, '474946383961');
        case 'webp': return str_starts_with($h, '52494646') && substr($h, 16, 8) === '57454250'; // RIFF....WEBP
        case 'avif':
        case 'heic':
            // ftypbox at offset 4
            if (substr($h, 8, 8) !== '66747970') return false;
            $brand = strtolower(substr($head, 8, 4) . substr($head, 12, 4) . substr($head, 16, 4) . substr($head, 20, 4));
            if ($slug === 'avif') return str_contains($brand, 'avif') || str_contains($brand, 'avis');
            return str_contains($brand, 'heic') || str_contains($brand, 'heix') || str_contains($brand, 'heif') || str_contains($brand, 'mif1') || str_contains($brand, 'msf1');
        case 'jp2':  return str_starts_with($h, '0000000c6a5020200d0a870a');
        case 'jxl':  return str_starts_with($h, 'ff0a') || str_starts_with($h, '0000000c4a584c200d0a870a');
        case 'bmp':  return str_starts_with($h, '424d');
        case 'tiff': return str_starts_with($h, '49492a00') || str_starts_with($h, '4d4d002a');
        case 'ico':  return str_starts_with($h, '00000100');
    }
    return false;
}

function format_mime(string $slug): string {
    $f = all_formats();
    return $f[$slug]['mimes'][0] ?? 'application/octet-stream';
}

function format_extension(string $slug): string {
    $f = all_formats();
    return $f[$slug]['ext'][0] ?? 'bin';
}

function gen_slug(int $len = 8): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function unique_slug(): string {
    while (true) {
        $s = gen_slug(8);
        $stmt = db()->prepare('SELECT 1 FROM images WHERE slug = ?');
        $stmt->execute([$s]);
        if (!$stmt->fetch()) return $s;
    }
}

function unique_gallery_slug(): string {
    while (true) {
        $s = gen_slug(8);
        $stmt = db()->prepare('SELECT 1 FROM galleries WHERE slug = ?');
        $stmt->execute([$s]);
        if (!$stmt->fetch()) return $s;
    }
}

function get_user_galleries(int $user_id): array {
    $stmt = db()->prepare('SELECT * FROM galleries WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function get_gallery_by_slug(string $slug): ?array {
    $stmt = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function human_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return number_format($v, $v < 10 && $i > 0 ? 1 : 0) . ' ' . $units[$i];
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    return rtrim($scheme . '://' . $host . $script, '/');
}

function image_direct_url(array $img): string {
    $file = (string) ($img['filename'] ?? '');
    if ($file === '' && !empty($img['slug'])) {
        $ext = format_extension((string) ($img['format'] ?? ''));
        $file = $img['slug'] . ($ext !== '' && $ext !== 'bin' ? '.' . $ext : '');
    }
    return rtrim(base_url(), '/') . image_direct_path($file);
}

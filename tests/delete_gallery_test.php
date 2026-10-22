<?php
// tests/delete_gallery_test.php - delete gallery helper
declare(strict_types=1);

require_once dirname(__DIR__) . '/gallery_actions.php';

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

function test_pdo(): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE galleries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            gallery_id INTEGER,
            slug TEXT UNIQUE NOT NULL,
            filename TEXT NOT NULL,
            original_name TEXT NOT NULL,
            format TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            uploaded_at INTEGER NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(gallery_id) REFERENCES galleries(id) ON DELETE SET NULL
        );
    ");
    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 0, ?)')
        ->execute(['alice', 'x', time()]);
    $alice = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 1, ?)')
        ->execute(['admin', 'x', time()]);
    $admin = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 0, ?)')
        ->execute(['bob', 'x', time()]);
    $bob = (int) $pdo->lastInsertId();

    $dir = sys_get_temp_dir() . '/imghost_galdel_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    $ins_g = $pdo->prepare('INSERT INTO galleries (user_id, slug, name, description, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins_g->execute([$alice, 'emptyGal', 'Empty', '', time()]);
    $empty_id = (int) $pdo->lastInsertId();
    $ins_g->execute([$alice, 'fullGal', 'Full', '', time()]);
    $full_id = (int) $pdo->lastInsertId();
    $ins_g->execute([$alice, 'pathGal', 'Path', '', time()]);
    $path_id = (int) $pdo->lastInsertId();
    $ins_g->execute([$alice, 'missGal', 'Missing file', '', time()]);
    $miss_id = (int) $pdo->lastInsertId();
    $ins_g->execute([$bob, 'bobGal', 'Bob', '', time()]);
    $bob_id = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        'INSERT INTO images (user_id, gallery_id, slug, filename, original_name, format, mime, size, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    file_put_contents($dir . '/img1.webp', 'a');
    $ins->execute([$alice, $full_id, 'img1', 'img1.webp', 'a.webp', 'webp', 'image/webp', 10, time()]);
    file_put_contents($dir . '/img2.webp', 'b');
    $ins->execute([$alice, $full_id, 'img2', 'img2.webp', 'b.webp', 'webp', 'image/webp', 10, time()]);
    file_put_contents($dir . '/safe.webp', 'c');
    $ins->execute([$alice, $path_id, 'img3', '../safe.webp', 'c.webp', 'webp', 'image/webp', 10, time()]);
    $ins->execute([$alice, $miss_id, 'img4', 'gone.webp', 'd.webp', 'webp', 'image/webp', 10, time()]);
    file_put_contents($dir . '/bob.webp', 'e');
    $ins->execute([$bob, $bob_id, 'img5', 'bob.webp', 'e.webp', 'webp', 'image/webp', 10, time()]);

    return [$pdo, $alice, $admin, $bob, $empty_id, $full_id, $path_id, $miss_id, $bob_id, $dir];
}

[$pdo, $alice, $admin, $bob, $empty_id, $full_id, $path_id, $miss_id, $bob_id, $dir] = test_pdo();

$r = delete_user_gallery($pdo, $alice, $empty_id, $dir);
expect_true(!empty($r['ok']), 'owner can delete an empty gallery');
expect_eq($r['count'] ?? -1, 0, 'empty gallery count is 0');
$gone = $pdo->prepare('SELECT 1 FROM galleries WHERE id = ?');
$gone->execute([$empty_id]);
expect_true($gone->fetch() === false, 'empty gallery row is gone');

$r = delete_user_gallery($pdo, $alice, $full_id, $dir);
expect_true(!empty($r['ok']), 'owner can delete a gallery with images');
expect_eq($r['count'] ?? 0, 2, 'count is images removed');
expect_true(!is_file($dir . '/img1.webp'), 'unlinks first gallery file');
expect_true(!is_file($dir . '/img2.webp'), 'unlinks second gallery file');
$imgs = $pdo->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
$imgs->execute([$full_id]);
expect_eq((int) $imgs->fetchColumn(), 0, 'image rows for gallery are gone');
$gone->execute([$full_id]);
expect_true($gone->fetch() === false, 'gallery row is gone');

$r = delete_user_gallery($pdo, $alice, $bob_id, $dir);
expect_true($r['ok'] === false, 'other user cannot delete');
expect_eq($r['error'] ?? '', 'Gallery not found.', 'foreign gallery error message');
$gone->execute([$bob_id]);
expect_true($gone->fetch() !== false, 'foreign gallery remains');
expect_true(is_file($dir . '/bob.webp'), 'foreign file remains');

$r = delete_user_gallery($pdo, $admin, $bob_id, $dir, true);
expect_true(!empty($r['ok']), 'admin can delete another user\'s gallery');
expect_eq($r['count'] ?? 0, 1, 'admin delete removes images');
expect_true(!is_file($dir . '/bob.webp'), 'admin delete unlinks file');
$gone->execute([$bob_id]);
expect_true($gone->fetch() === false, 'admin delete removes gallery row');

$r = delete_user_gallery($pdo, $alice, 99999, $dir);
expect_true($r['ok'] === false, 'unknown id fails');
expect_eq($r['error'] ?? '', 'Gallery not found.', 'unknown id error message');

$r = delete_user_gallery($pdo, $alice, $path_id, $dir);
expect_true(!empty($r['ok']), 'path-traversal filename still deletes');
expect_true(!is_file($dir . '/safe.webp'), 'unlinks via basename only');
expect_true(!is_file($dir . '/../safe.webp') || is_file($dir . '/safe.webp') === false, 'does not rely on raw path');

$r = delete_user_gallery($pdo, $alice, $miss_id, $dir);
expect_true(!empty($r['ok']), 'missing file on disk does not fail delete');
$gone->execute([$miss_id]);
expect_true($gone->fetch() === false, 'gallery with missing file is removed');
$left = $pdo->query('SELECT COUNT(*) FROM images WHERE slug = \'img4\'')->fetchColumn();
expect_eq((int) $left, 0, 'image row removed even if file was missing');

foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

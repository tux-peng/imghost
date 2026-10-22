<?php
// tests/delete_images_test.php - bulk delete selected images
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
    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 0, ?)')
        ->execute(['bob', 'x', time()]);
    $bob = (int) $pdo->lastInsertId();

    $dir = sys_get_temp_dir() . '/imghost_del_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    $ins = $pdo->prepare(
        'INSERT INTO images (user_id, gallery_id, slug, filename, original_name, format, mime, size, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    file_put_contents($dir . '/img1.webp', 'a');
    $ins->execute([$alice, null, 'img1', 'img1.webp', 'a.webp', 'webp', 'image/webp', 10, time()]);
    $a1 = (int) $pdo->lastInsertId();
    file_put_contents($dir . '/img2.webp', 'b');
    $ins->execute([$alice, null, 'img2', 'img2.webp', 'b.webp', 'webp', 'image/webp', 10, time()]);
    $a2 = (int) $pdo->lastInsertId();
    file_put_contents($dir . '/img3.webp', 'c');
    $ins->execute([$bob, null, 'img3', 'img3.webp', 'c.webp', 'webp', 'image/webp', 10, time()]);
    $b1 = (int) $pdo->lastInsertId();

    return [$pdo, $alice, $bob, $a1, $a2, $b1, $dir];
}

[$pdo, $alice, $bob, $a1, $a2, $b1, $dir] = test_pdo();

$r = delete_user_images($pdo, $alice, [], $dir);
expect_true($r['ok'] === false, 'empty selection fails');
expect_eq($r['error'] ?? '', 'Select at least one image.', 'empty selection message');

$r = delete_user_images($pdo, $alice, ['nope', 0, -3], $dir);
expect_true($r['ok'] === false, 'non-numeric ids fail as empty');

$r = delete_user_images($pdo, $alice, [$b1], $dir);
expect_true($r['ok'] === false, 'only foreign images fails');
expect_eq($r['error'] ?? '', 'No matching images to delete.', 'foreign-only error message');
expect_true(is_file($dir . '/img3.webp'), 'does not unlink another user\'s file');

$r = delete_user_images($pdo, $alice, [$a1, $b1], $dir);
expect_true(!empty($r['ok']), 'mixed selection still succeeds for owned subset');
expect_eq($r['count'] ?? 0, 1, 'deletes only the owned image');
expect_true(!is_file($dir . '/img1.webp'), 'unlinks owned file');
expect_true(is_file($dir . '/img3.webp'), 'leaves another user\'s file');
$left = $pdo->query('SELECT id FROM images ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
expect_eq(array_map('intval', $left), [$a2, $b1], 'owned row gone, foreign row kept');

$r = delete_user_images($pdo, $alice, [$a2], $dir);
expect_true(!empty($r['ok']), 'owner can delete remaining image');
expect_eq($r['count'] ?? 0, 1, 'deletes last owned image');
expect_true(!is_file($dir . '/img2.webp'), 'unlinks remaining owned file');

$alice_left = $pdo->query('SELECT COUNT(*) FROM images WHERE user_id = ' . $alice)->fetchColumn();
expect_eq((int) $alice_left, 0, 'no alice images remain');

foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

<?php
// tests/add_to_gallery_test.php - bulk add-to-gallery helpers
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

    $pdo->prepare('INSERT INTO galleries (user_id, slug, name, description, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$alice, 'galAlice', 'Alice gallery', '', time()]);
    $alice_gal = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO galleries (user_id, slug, name, description, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$bob, 'galBob', 'Bob gallery', '', time()]);
    $bob_gal = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        'INSERT INTO images (user_id, gallery_id, slug, filename, original_name, format, mime, size, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$alice, null, 'img1', 'img1.webp', 'a.webp', 'webp', 'image/webp', 10, time()]);
    $a1 = (int) $pdo->lastInsertId();
    $ins->execute([$alice, null, 'img2', 'img2.webp', 'b.webp', 'webp', 'image/webp', 10, time()]);
    $a2 = (int) $pdo->lastInsertId();
    $ins->execute([$bob, null, 'img3', 'img3.webp', 'c.webp', 'webp', 'image/webp', 10, time()]);
    $b1 = (int) $pdo->lastInsertId();

    return [$pdo, $alice, $bob, $alice_gal, $bob_gal, $a1, $a2, $b1];
}

[$pdo, $alice, $bob, $alice_gal, $bob_gal, $a1, $a2, $b1] = test_pdo();

$r = add_user_images_to_gallery($pdo, $alice, $alice_gal, [$a1, $a2]);
expect_true($r['ok'] === true, 'owner can add own images');
expect_eq($r['count'] ?? 0, 2, 'adds both selected owned images');
$gids = $pdo->query('SELECT gallery_id FROM images WHERE user_id = ' . $alice . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
expect_eq(array_map('intval', $gids), [$alice_gal, $alice_gal], 'gallery_id set on both images');

$r = add_user_images_to_gallery($pdo, $alice, $alice_gal, [$a1, $b1]);
expect_true(!empty($r['ok']), 'mixed selection still succeeds for owned subset');
expect_eq($r['count'] ?? 0, 1, 'ignores another user\'s image');
$bob_gid = $pdo->query('SELECT gallery_id FROM images WHERE id = ' . $b1)->fetchColumn();
expect_true($bob_gid === null || $bob_gid === '', 'does not move another user\'s image');

$r = add_user_images_to_gallery($pdo, $alice, $bob_gal, [$a1]);
expect_true($r['ok'] === false, 'rejects gallery owned by someone else');
expect_eq($r['error'] ?? '', 'Gallery not found.', 'foreign gallery error message');

$r = add_user_images_to_gallery($pdo, $alice, $alice_gal, []);
expect_true($r['ok'] === false, 'empty selection fails');
expect_eq($r['error'] ?? '', 'Select at least one image.', 'empty selection message');

$r = add_user_images_to_gallery($pdo, $alice, $alice_gal, ['nope', 0, -3]);
expect_true($r['ok'] === false, 'non-numeric ids fail as empty');

$r = add_user_images_to_gallery($pdo, $alice, $alice_gal, [$b1]);
expect_true($r['ok'] === false, 'only foreign images fails');
expect_eq($r['error'] ?? '', 'No matching images to add.', 'no matching images message');

$created = create_user_gallery($pdo, $alice, '  New shots  ');
expect_true($created['ok'] === true, 'creates gallery with trimmed name');
expect_true(($created['id'] ?? 0) > 0, 'new gallery has id');
expect_eq($created['name'] ?? '', 'New shots', 'stores trimmed gallery name');

$r = add_user_images_to_gallery($pdo, $alice, (int) $created['id'], [$a2]);
expect_true(!empty($r['ok']), 'can add images to newly created gallery');
expect_eq($r['count'] ?? 0, 1, 'added one image to new gallery');

$bad = create_user_gallery($pdo, $alice, '   ');
expect_true($bad['ok'] === false, 'blank gallery name fails');
expect_eq($bad['error'] ?? '', 'Gallery name is required.', 'blank name message');

$long = str_repeat('a', 101);
$bad = create_user_gallery($pdo, $alice, $long);
expect_true($bad['ok'] === false, 'overlong gallery name fails');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

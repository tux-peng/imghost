<?php
// tests/password_test.php - admin reset and user change-password helpers
declare(strict_types=1);

require_once dirname(__DIR__) . '/password_actions.php';

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
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
    ");
    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 0, ?)')
        ->execute(['alice', password_hash('oldpass12', PASSWORD_DEFAULT), time()]);
    $alice = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 1, ?)')
        ->execute(['admin', password_hash('adminpass', PASSWORD_DEFAULT), time()]);
    $admin = (int) $pdo->lastInsertId();
    return [$pdo, $alice, $admin];
}

[$pdo, $alice, $admin] = test_pdo();

$hash_of = static function (PDO $pdo, int $id): string {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
};

$r = set_user_password($pdo, $alice, 'short');
expect_true($r['ok'] === false, 'admin reset rejects short password');
expect_eq($r['error'] ?? '', 'Password must be at least 8 characters.', 'admin reset short message');
expect_true(password_verify('oldpass12', $hash_of($pdo, $alice)), 'short reset leaves hash unchanged');

$r = set_user_password($pdo, 99999, 'newpass99');
expect_true($r['ok'] === false, 'admin reset unknown user fails');
expect_eq($r['error'] ?? '', 'User not found.', 'admin reset unknown message');

$r = set_user_password($pdo, $alice, 'resetpass');
expect_true(!empty($r['ok']), 'admin can reset any user password');
expect_true(password_verify('resetpass', $hash_of($pdo, $alice)), 'admin reset stores new hash');
expect_true(!password_verify('oldpass12', $hash_of($pdo, $alice)), 'admin reset replaces old hash');

$r = change_user_password($pdo, $alice, 'wrongpass', 'newerpass', 'newerpass');
expect_true($r['ok'] === false, 'own change rejects wrong current password');
expect_eq($r['error'] ?? '', 'Current password is incorrect.', 'wrong current message');
expect_true(password_verify('resetpass', $hash_of($pdo, $alice)), 'failed change leaves hash');

$r = change_user_password($pdo, $alice, 'resetpass', 'short', 'short');
expect_true($r['ok'] === false, 'own change rejects short new password');
expect_eq($r['error'] ?? '', 'Password must be at least 8 characters.', 'own change short message');

$r = change_user_password($pdo, $alice, 'resetpass', 'newerpass', 'mismatch1');
expect_true($r['ok'] === false, 'own change rejects mismatched confirm');
expect_eq($r['error'] ?? '', 'New passwords do not match.', 'mismatch message');

$r = change_user_password($pdo, $alice, 'resetpass', 'resetpass', 'resetpass');
expect_true($r['ok'] === false, 'own change rejects same password');
expect_eq($r['error'] ?? '', 'New password must be different.', 'same password message');

$r = change_user_password($pdo, $alice, 'resetpass', 'newerpass', 'newerpass');
expect_true(!empty($r['ok']), 'user can change own password');
expect_true(password_verify('newerpass', $hash_of($pdo, $alice)), 'own change stores new hash');
expect_true(!password_verify('resetpass', $hash_of($pdo, $alice)), 'own change replaces old hash');

$r = change_user_password($pdo, 99999, 'resetpass', 'newerpass', 'newerpass');
expect_true($r['ok'] === false, 'own change unknown user fails');
expect_eq($r['error'] ?? '', 'User not found.', 'own change unknown message');

$r = set_user_password($pdo, $admin, 'adminnew1');
expect_true(!empty($r['ok']), 'admin can reset their own password');
expect_true(password_verify('adminnew1', $hash_of($pdo, $admin)), 'admin self-reset stores hash');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

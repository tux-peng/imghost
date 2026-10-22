<?php
// password_actions.php - admin reset and user change-password helpers
declare(strict_types=1);

/**
 * @return array{ok:true}|array{ok:false,error:string}
 */
function set_user_password(PDO $pdo, int $user_id, string $new_password): array {
    if (strlen($new_password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id]);

    return ['ok' => true];
}

/**
 * @return array{ok:true}|array{ok:false,error:string}
 */
function change_user_password(
    PDO $pdo,
    int $user_id,
    string $current_password,
    string $new_password,
    string $confirm_password
): array {
    if ($new_password !== $confirm_password) {
        return ['ok' => false, 'error' => 'New passwords do not match.'];
    }
    if (strlen($new_password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u) {
        return ['ok' => false, 'error' => 'User not found.'];
    }
    if (!password_verify($current_password, $u['password_hash'])) {
        return ['ok' => false, 'error' => 'Current password is incorrect.'];
    }
    if (password_verify($new_password, $u['password_hash'])) {
        return ['ok' => false, 'error' => 'New password must be different.'];
    }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id]);

    return ['ok' => true];
}

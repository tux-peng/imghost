<?php
// account.php - change own password
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$msg = null;
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $err = 'Session expired, please try again.';
    } else {
        $result = change_user_password(
            db(),
            (int) $user['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if (!empty($result['ok'])) {
            session_regenerate_id(true);
            $msg = 'Password updated.';
        } else {
            $err = $result['error'] ?? 'Could not update password.';
        }
    }
}

$page_title = 'Account';
include __DIR__ . '/header.php';
?>
<section class="card">
    <h1>Account</h1>
    <p class="muted">Signed in as @<?= h($user['username']) ?>.</p>
    <?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Current password<input name="current_password" type="password" required autocomplete="current-password"></label>
        <label>New password<input name="new_password" type="password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirm new password<input name="confirm_password" type="password" required minlength="8" autocomplete="new-password"></label>
        <button class="primary" type="submit">Change password</button>
    </form>
</section>
<?php include __DIR__ . '/footer.php';

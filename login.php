<?php
// login.php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = null;
$installed = isset($_GET['installed']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Session expired, please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if ($u && password_verify($password, $u['password_hash'])) {
            login_user((int) $u['id']);
            header('Location: index.php');
            exit;
        }
        // Avoid disclosing whether the username exists.
        usleep(300000);
        $error = 'Invalid username or password.';
    }
}

$page_title = 'Log in';
include __DIR__ . '/header.php';
?>
<section class="card">
    <h1>Log in</h1>
    <?php if ($installed): ?><p class="ok">Installation complete. Log in with your admin account.</p><?php endif; ?>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <label>Username<input name="username" required autofocus autocomplete="username"></label>
        <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
        <button class="primary" type="submit">Log in</button>
        <?php if (get_registration_mode() === 'open'): ?>
            <p class="muted">No account? <a href="register.php">Sign up</a>.</p>
        <?php elseif (get_registration_mode() === 'invite'): ?>
            <p class="muted">Registration is invite-only. Use your invite link.</p>
        <?php else: ?>
            <p class="muted">Registration is closed.</p>
        <?php endif; ?>
    </form>
</section>
<?php include __DIR__ . '/footer.php';

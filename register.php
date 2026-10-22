<?php
// register.php - handles both open registration and invite-only signups
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$mode = get_registration_mode();
$invite_code = trim((string) ($_GET['invite'] ?? $_POST['invite'] ?? ''));
$invite_row = null;

if ($mode === 'invite') {
    if ($invite_code === '') {
        http_response_code(403);
        $page_title = 'Registration';
        include __DIR__ . '/header.php';
        echo '<section class="card"><h1>Invite required</h1><p class="muted">Registration is invite-only. Open the invite link you were given.</p></section>';
        include __DIR__ . '/footer.php';
        exit;
    }
    $stmt = db()->prepare('SELECT * FROM invites WHERE code = ? AND used_by IS NULL');
    $stmt->execute([$invite_code]);
    $invite_row = $stmt->fetch();
    if (!$invite_row) {
        http_response_code(403);
        $page_title = 'Invalid invite';
        include __DIR__ . '/header.php';
        echo '<section class="card"><h1>Invalid invite</h1><p class="muted">This invite is invalid or has already been used.</p></section>';
        include __DIR__ . '/footer.php';
        exit;
    }
} elseif ($mode === 'closed') {
    http_response_code(403);
    $page_title = 'Closed';
    include __DIR__ . '/header.php';
    echo '<section class="card"><h1>Registration closed</h1><p class="muted">Public sign-ups are disabled. Ask the admin to create an account for you.</p></section>';
    include __DIR__ . '/footer.php';
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Session expired, please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $username)) {
            $error = 'Username must be 3-32 chars (letters, digits, _ . -).';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                $db = db();
                $db->beginTransaction();
                $stmt = $db->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 0, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
                $uid = (int) $db->lastInsertId();
                if ($invite_row) {
                    $upd = $db->prepare('UPDATE invites SET used_by = ?, used_at = ? WHERE id = ? AND used_by IS NULL');
                    $upd->execute([$uid, time(), $invite_row['id']]);
                    if ($upd->rowCount() === 0) {
                        throw new RuntimeException('Invite was just used.');
                    }
                }
                $db->commit();
                login_user($uid);
                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'That username is taken.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Could not register. Try again.';
            }
        }
    }
}

$page_title = 'Sign up';
include __DIR__ . '/header.php';
?>
<section class="card">
    <h1>Create an account</h1>
    <?php if ($mode === 'invite'): ?>
        <p class="muted">You're using an invite from <?= h($invite_row['created_by'] ? '@admin' : 'someone') ?>.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <?php if ($mode === 'invite'): ?>
            <input type="hidden" name="invite" value="<?= h($invite_code) ?>">
        <?php endif; ?>
        <label>Username<input name="username" required pattern="[A-Za-z0-9_.\-]{3,32}" autocomplete="username"></label>
        <label>Password<input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
        <button class="primary" type="submit">Sign up</button>
    </form>
</section>
<?php include __DIR__ . '/footer.php';

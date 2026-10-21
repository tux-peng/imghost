<?php
// admin.php - settings, users, invites
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

require_admin();
$user = current_user();
$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $err = 'Session expired.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            switch ($action) {
                case 'settings':
                    $reg = $_POST['registration_mode'] ?? 'invite';
                    if (!in_array($reg, ['open', 'invite', 'closed'], true)) throw new RuntimeException('Bad mode.');

                    $fmts = $_POST['formats'] ?? [];
                    if (!is_array($fmts)) $fmts = [];
                    $valid_fmts = array_keys(all_formats());
                    $fmts = array_values(array_intersect($fmts, $valid_fmts));
                    if (!$fmts) throw new RuntimeException('Pick at least one image format.');

                    $theme = $_POST['default_theme'] ?? 'auto';
                    if (!in_array($theme, ['light', 'dark', 'auto'], true)) $theme = 'auto';

                    $max = (int) ($_POST['max_size_mb'] ?? 15);
                    if ($max < 1 || $max > 1024) throw new RuntimeException('Max size must be 1-1024 MB.');

                    $name = trim((string) ($_POST['site_name'] ?? 'Image Host'));
                    if ($name === '') $name = 'Image Host';

                    $conv = $_POST['convert_webp'] ?? [];
                    if (!is_array($conv)) $conv = [];
                    $conv = array_values(array_intersect($conv, $fmts, $valid_fmts));
                    $conv = array_values(array_diff($conv, ['webp']));

                    set_setting('registration_mode', $reg);
                    set_setting('allowed_formats', implode(',', $fmts));
                    set_setting('convert_to_webp', implode(',', $conv));
                    set_setting('default_theme', $theme);
                    set_setting('max_size_mb', (string) $max);
                    set_setting('site_name', substr($name, 0, 80));
                    $msg = 'Settings saved.';
                    break;

                case 'create_invite':
                    $code = bin2hex(random_bytes(12));
                    $stmt = db()->prepare('INSERT INTO invites (code, created_by, created_at) VALUES (?, ?, ?)');
                    $stmt->execute([$code, $user['id'], time()]);
                    $msg = 'Invite created.';
                    break;

                case 'delete_invite':
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = db()->prepare('DELETE FROM invites WHERE id = ? AND used_by IS NULL');
                    $stmt->execute([$id]);
                    $msg = 'Invite deleted.';
                    break;

                case 'create_user':
                    $u = trim((string) ($_POST['new_user'] ?? ''));
                    $p = (string) ($_POST['new_pass'] ?? '');
                    $is_admin = !empty($_POST['new_admin']) ? 1 : 0;
                    if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $u)) throw new RuntimeException('Bad username.');
                    if (strlen($p) < 8) throw new RuntimeException('Password too short.');
                    $stmt = db()->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$u, password_hash($p, PASSWORD_DEFAULT), $is_admin, time()]);
                    $msg = 'User created.';
                    break;

                case 'delete_user':
                    $id = (int) ($_POST['id'] ?? 0);
                    if ($id === (int) $user['id']) throw new RuntimeException("You can't delete yourself.");
                    // Wipe their files first.
                    $config = require __DIR__ . '/config.php';
                    $stmt = db()->prepare('SELECT filename FROM images WHERE user_id = ?');
                    $stmt->execute([$id]);
                    foreach ($stmt->fetchAll() as $row) {
                        @unlink($config['upload_path'] . '/' . $row['filename']);
                    }
                    db()->prepare('DELETE FROM images WHERE user_id = ?')->execute([$id]);
                    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                    $msg = 'User and their images deleted.';
                    break;

                case 'toggle_admin':
                    $id = (int) ($_POST['id'] ?? 0);
                    if ($id === (int) $user['id']) throw new RuntimeException("You can't change your own admin flag.");
                    db()->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$id]);
                    $msg = 'Updated.';
                    break;

                case 'reset_password':
                    $id = (int) ($_POST['id'] ?? 0);
                    $p = (string) ($_POST['new_pass'] ?? '');
                    $result = set_user_password(db(), $id, $p);
                    if (empty($result['ok'])) {
                        throw new RuntimeException($result['error'] ?? 'Could not reset password.');
                    }
                    $msg = 'Password reset.';
                    break;
            }
        } catch (PDOException $e) {
            $err = 'That username is taken.';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$users = db()->query('SELECT id, username, is_admin, created_at FROM users ORDER BY id ASC')->fetchAll();
$invites = db()->query('
    SELECT i.*, c.username AS creator, u.username AS used_by_name
    FROM invites i
    LEFT JOIN users c ON c.id = i.created_by
    LEFT JOIN users u ON u.id = i.used_by
    ORDER BY i.created_at DESC
    LIMIT 50
')->fetchAll();

$total_imgs = (int) db()->query('SELECT COUNT(*) FROM images')->fetchColumn();
$total_bytes = (int) db()->query('SELECT COALESCE(SUM(size), 0) FROM images')->fetchColumn();

$current_formats = get_allowed_formats();

$page_title = 'Admin';
include __DIR__ . '/header.php';
?>
<h1>Admin</h1>
<p class="muted">
    <?= (int) count($users) ?> users &middot;
    <?= h((string) $total_imgs) ?> images &middot;
    <?= h(human_size($total_bytes)) ?> stored
</p>
<?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>

<section class="card wide" style="margin:1rem 0">
    <h2>Settings</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings">

        <label>Site name<input name="site_name" value="<?= h(get_site_name()) ?>" required></label>

        <label>Registration mode
            <?php $mode = get_registration_mode(); ?>
            <select name="registration_mode">
                <option value="invite" <?= $mode === 'invite' ? 'selected' : '' ?>>Invite only</option>
                <option value="closed" <?= $mode === 'closed' ? 'selected' : '' ?>>No registration</option>
                <option value="open"   <?= $mode === 'open'   ? 'selected' : '' ?>>Open registration</option>
            </select>
        </label>

        <label>Default theme
            <?php $th = get_default_theme(); ?>
            <select name="default_theme">
                <option value="auto"  <?= $th === 'auto'  ? 'selected' : '' ?>>Auto (follow OS)</option>
                <option value="light" <?= $th === 'light' ? 'selected' : '' ?>>Solarized Light</option>
                <option value="dark"  <?= $th === 'dark'  ? 'selected' : '' ?>>Solarized Dark</option>
            </select>
        </label>

        <label>Max upload size (MB)<input name="max_size_mb" type="number" min="1" max="1024" value="<?= h((string) get_max_size_mb()) ?>"></label>

        <fieldset style="border:0; padding:0; margin-top:1rem">
            <legend style="font-size:0.92rem; color:var(--fg-strong)">Allowed image formats</legend>
            <p class="muted" style="margin:0.2rem 0 0.6rem">Defaults: WebP, AVIF. Pick whatever you want to accept.</p>
            <div class="fmtgrid">
                <?php foreach (all_formats() as $slug => $info): ?>
                    <label>
                        <input type="checkbox" name="formats[]" value="<?= h($slug) ?>"
                               <?= in_array($slug, $current_formats, true) ? 'checked' : '' ?>>
                        <?= h($info['label']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset style="border:0; padding:0; margin-top:1rem">
            <legend style="font-size:0.92rem; color:var(--fg-strong)">Convert to WebP</legend>
            <p class="muted" style="margin:0.2rem 0 0.6rem">
                Require these allowed types to be converted with PHP GD on upload.
                <?= gd_supports_webp_convert() ? 'GD WebP support is available.' : 'GD WebP support is missing — conversion will fail until it is enabled.' ?>
            </p>
            <div class="fmtgrid">
                <?php
                $current_convert = get_convert_to_webp_formats();
                foreach (all_formats() as $slug => $info):
                    if ($slug === 'webp') continue;
                ?>
                    <label>
                        <input type="checkbox" name="convert_webp[]" value="<?= h($slug) ?>"
                               <?= in_array($slug, $current_convert, true) ? 'checked' : '' ?>>
                        <?= h($info['label']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <button class="primary" type="submit">Save settings</button>
    </form>
</section>

<section class="card wide" style="margin:1rem 0">
    <h2>Users</h2>
    <form method="post" style="display:grid; grid-template-columns: 1fr 1fr auto auto; gap:0.6rem; align-items:end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">
        <label>New username<input name="new_user" required pattern="[A-Za-z0-9_.\-]{3,32}"></label>
        <label>Password<input name="new_pass" type="password" required minlength="8"></label>
        <label style="display:flex; align-items:center; gap:0.3rem"><input type="checkbox" name="new_admin"> Admin</label>
        <button type="submit">Create</button>
    </form>

    <table>
        <thead><tr><th>#</th><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= (int) $u['id'] ?></td>
                <td>@<?= h($u['username']) ?></td>
                <td><?= $u['is_admin'] ? 'admin' : 'user' ?></td>
                <td><?= h(date('Y-m-d', (int) $u['created_at'])) ?></td>
                <td>
                    <form method="post" class="reset-pass" onsubmit="return confirm(<?= json_encode('Reset password for @' . $u['username'] . '?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input name="new_pass" type="password" required minlength="8" placeholder="New password" autocomplete="new-password">
                        <button type="submit">Reset</button>
                    </form>
                    <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_admin">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit"><?= $u['is_admin'] ? 'Demote' : 'Promote' ?></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete @<?= h($u['username']) ?> and ALL their images?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="danger" type="submit">Delete</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">(you)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card wide" style="margin:1rem 0">
    <h2>Invite links</h2>
    <p class="muted">Useful regardless of mode, but required when registration is invite-only.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_invite">
        <button type="submit">Create new invite</button>
    </form>

    <?php if ($invites): ?>
    <table>
        <thead><tr><th>Link</th><th>Created by</th><th>Used by</th><th>When</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($invites as $iv):
            $url = base_url() . '/register.php?invite=' . urlencode($iv['code']);
        ?>
            <tr>
                <td>
                    <?php if ($iv['used_by']): ?>
                        <span class="muted"><?= h(substr($iv['code'], 0, 10)) ?>...</span>
                    <?php else: ?>
                        <input type="text" readonly value="<?= h($url) ?>" onclick="this.select()" style="font-size:0.8rem">
                    <?php endif; ?>
                </td>
                <td><?= $iv['creator'] ? '@' . h($iv['creator']) : '<span class="muted">deleted</span>' ?></td>
                <td><?= $iv['used_by_name'] ? '@' . h($iv['used_by_name']) : '<span class="muted">unused</span>' ?></td>
                <td><?= h(date('Y-m-d', (int) $iv['created_at'])) ?></td>
                <td>
                    <?php if (!$iv['used_by']): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_invite">
                            <input type="hidden" name="id" value="<?= (int) $iv['id'] ?>">
                            <button class="danger" type="submit">Revoke</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php';

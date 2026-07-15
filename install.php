<?php
// install.php - one-shot installer
declare(strict_types=1);

if (file_exists(__DIR__ . '/config.php')) {
    die('Already installed. Delete <code>config.php</code> and <code>database/site.db</code> to reinstall.');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = trim((string) ($_POST['admin_user'] ?? ''));
    $admin_pass = (string) ($_POST['admin_pass'] ?? '');
    $site_name  = trim((string) ($_POST['site_name'] ?? 'Image Host'));
    $reg_mode   = $_POST['reg_mode'] ?? 'invite';
    $theme      = $_POST['theme'] ?? 'auto';

    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $admin_user)) {
        $error = 'Username must be 3-32 chars (letters, digits, _ . -).';
    } elseif (strlen($admin_pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        @mkdir(__DIR__ . '/database', 0755, true);
        @mkdir(__DIR__ . '/uploads', 0755, true);

        // Lock down uploads (Apache).
        file_put_contents(
            __DIR__ . '/uploads/.htaccess',
            "php_flag engine off\nOptions -ExecCGI -Indexes\nAddType text/plain .php .phtml .phar .pl .py .jsp .asp .sh .cgi\n<FilesMatch \"\\.(php|phar|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\n"
        );
        file_put_contents(__DIR__ . '/database/.htaccess', "Require all denied\n");

        $db_path = __DIR__ . '/database/site.db';
        $db = new PDO('sqlite:' . $db_path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("
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
            CREATE TABLE invites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                created_by INTEGER,
                used_by INTEGER,
                created_at INTEGER NOT NULL,
                used_at INTEGER
            );
            CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        ");

        $stmt = $db->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 1, ?)');
        $stmt->execute([$admin_user, password_hash($admin_pass, PASSWORD_DEFAULT), time()]);

        $defaults = [
            'site_name'         => $site_name !== '' ? $site_name : 'Image Host',
            'registration_mode' => in_array($reg_mode, ['open', 'invite', 'closed'], true) ? $reg_mode : 'invite',
            'allowed_formats'   => 'webp,avif',
            'default_theme'     => in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto',
            'max_size_mb'       => '15',
        ];
        $ins = $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
        foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

        $config = "<?php\nreturn [\n"
                . "    'db_path' => __DIR__ . '/database/site.db',\n"
                . "    'upload_path' => __DIR__ . '/uploads',\n"
                . "];\n";
        file_put_contents(__DIR__ . '/config.php', $config);

        // Try to remove the installer so it can't be re-run.
        @unlink(__FILE__);

        header('Location: login.php?installed=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="en" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Set up your image host</h1>
    <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <label>Site name<input name="site_name" value="Image Host" required></label>
        <label>Admin username<input name="admin_user" required pattern="[A-Za-z0-9_.\-]{3,32}"></label>
        <label>Admin password<input name="admin_pass" type="password" required minlength="8"></label>
        <label>Registration mode
            <select name="reg_mode">
                <option value="invite" selected>Invite only</option>
                <option value="closed">No registration (admin creates accounts)</option>
                <option value="open">Open registration</option>
            </select>
        </label>
        <label>Default theme
            <select name="theme">
                <option value="auto" selected>Auto (follow OS)</option>
                <option value="light">Solarized Light</option>
                <option value="dark">Solarized Dark</option>
            </select>
        </label>
        <button class="primary" type="submit">Install</button>
    </form>
</main>
</body>
</html>

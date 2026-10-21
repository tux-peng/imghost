<?php
// install.php - one-shot installer
declare(strict_types=1);

define('IMGHOST_INSTALLING', true);

if (file_exists(__DIR__ . '/config.php')) {
    die('Already installed. Delete <code>config.php</code> (and the SQLite file if you used one) to reinstall.');
}

require_once __DIR__ . '/db.php';

$error = null;
$posted_driver = 'sqlite';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = trim((string) ($_POST['admin_user'] ?? ''));
    $admin_pass = (string) ($_POST['admin_pass'] ?? '');
    $site_name  = trim((string) ($_POST['site_name'] ?? 'Image Host'));
    $reg_mode   = $_POST['reg_mode'] ?? 'invite';
    $theme      = $_POST['theme'] ?? 'auto';
    $driver     = (string) ($_POST['db_driver'] ?? 'sqlite');
    $posted_driver = $driver;

    if (!in_array($driver, ['sqlite', 'mysql', 'libsql'], true)) {
        $error = 'Pick SQLite, MySQL/MariaDB, or LibSQL/Turso.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $admin_user)) {
        $error = 'Username must be 3-32 chars (letters, digits, _ . -).';
    } elseif (strlen($admin_pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            @mkdir(__DIR__ . '/database', 0755, true);
            @mkdir(__DIR__ . '/uploads', 0755, true);

            file_put_contents(
                __DIR__ . '/uploads/.htaccess',
                "php_flag engine off\nOptions -ExecCGI -Indexes\nAddType text/plain .php .phtml .phar .pl .py .jsp .asp .sh .cgi\n<FilesMatch \"\\.(php|phar|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\n"
            );
            file_put_contents(__DIR__ . '/database/.htaccess', "Require all denied\n");

            $app = [
                'upload_path' => __DIR__ . '/uploads',
                'db' => ['driver' => $driver],
            ];

            if ($driver === 'mysql') {
                $host = trim((string) ($_POST['mysql_host'] ?? '127.0.0.1'));
                $port = (int) ($_POST['mysql_port'] ?? 3306);
                $name = trim((string) ($_POST['mysql_name'] ?? ''));
                $user = trim((string) ($_POST['mysql_user'] ?? ''));
                $pass = (string) ($_POST['mysql_pass'] ?? '');
                if ($host === '' || $name === '' || $user === '') {
                    throw new RuntimeException('MySQL/MariaDB needs host, database name, and user.');
                }
                if ($port < 1 || $port > 65535) $port = 3306;
                $app['db'] += [
                    'host' => $host,
                    'port' => $port,
                    'name' => $name,
                    'user' => $user,
                    'pass' => $pass,
                    'charset' => 'utf8mb4',
                ];
            } elseif ($driver === 'libsql') {
                $url = trim((string) ($_POST['libsql_url'] ?? ''));
                $token = trim((string) ($_POST['libsql_token'] ?? ''));
                if ($url === '') {
                    throw new RuntimeException('LibSQL/Turso needs a database URL.');
                }
                $app['db']['url'] = $url;
                $app['db']['token'] = $token;
            } else {
                $db_path = __DIR__ . '/database/site.db';
                $app['db']['path'] = $db_path;
                $app['db_path'] = $db_path;
            }

            $GLOBALS['config'] = $app;
            $db = db_connect($app);
            init_schema($db);

            $stmt = $db->prepare('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 1, ?)');
            $stmt->execute([$admin_user, password_hash($admin_pass, PASSWORD_DEFAULT), time()]);

            $defaults = [
                'site_name'         => $site_name !== '' ? $site_name : 'Image Host',
                'registration_mode' => in_array($reg_mode, ['open', 'invite', 'closed'], true) ? $reg_mode : 'invite',
                'allowed_formats'   => 'webp,avif',
                'default_theme'     => in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto',
                'max_size_mb'       => '15',
            ];
            $keycol = $driver === 'mysql' ? '`key`' : 'key';
            $ins = $db->prepare("INSERT INTO settings ($keycol, value) VALUES (?, ?)");
            foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

            file_put_contents(__DIR__ . '/config.php', export_config_php($app));

            @unlink(__FILE__);

            header('Location: login.php?installed=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Install failed: ' . $e->getMessage();
        }
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
<main class="card wide">
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

        <fieldset style="border:0; padding:0; margin-top:1rem">
            <legend style="font-size:0.92rem; color:var(--fg-strong)">Database</legend>
            <p class="muted" style="margin:0.2rem 0 0.6rem">SQLite is the default (a file in <code>database/</code>). You can also use MySQL/MariaDB or LibSQL/Turso.</p>
            <label>Engine
                <select name="db_driver" id="db_driver">
                    <option value="sqlite" <?= $posted_driver === 'sqlite' ? 'selected' : '' ?>>SQLite (file)</option>
                    <option value="mysql" <?= $posted_driver === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                    <option value="libsql" <?= $posted_driver === 'libsql' ? 'selected' : '' ?>>LibSQL / Turso</option>
                </select>
            </label>
            <div id="db_sqlite" class="db-fields">
                <p class="muted">Creates <code>database/site.db</code> on this server. Needs the <code>pdo_sqlite</code> extension.</p>
            </div>
            <div id="db_mysql" class="db-fields">
                <label>Host<input name="mysql_host" value="<?= htmlspecialchars((string) ($_POST['mysql_host'] ?? '127.0.0.1')) ?>"></label>
                <label>Port<input name="mysql_port" type="number" min="1" max="65535" value="<?= htmlspecialchars((string) ($_POST['mysql_port'] ?? '3306')) ?>"></label>
                <label>Database name<input name="mysql_name" value="<?= htmlspecialchars((string) ($_POST['mysql_name'] ?? '')) ?>"></label>
                <label>User<input name="mysql_user" value="<?= htmlspecialchars((string) ($_POST['mysql_user'] ?? '')) ?>"></label>
                <label>Password<input name="mysql_pass" type="password" value=""></label>
            </div>
            <div id="db_libsql" class="db-fields">
                <label>URL<input name="libsql_url" placeholder="libsql://your-db.turso.io" value="<?= htmlspecialchars((string) ($_POST['libsql_url'] ?? '')) ?>"></label>
                <label>Auth token<input name="libsql_token" type="password" value=""></label>
                <p class="muted">Uses the Turso HTTP API (<code>/v2/pipeline</code>). Paste the <code>libsql://</code> URL and token from the Turso dashboard.</p>
            </div>
        </fieldset>

        <button class="primary" type="submit">Install</button>
    </form>
</main>
<script>
(function () {
    var sel = document.getElementById('db_driver');
    function show() {
        var v = sel.value;
        document.getElementById('db_sqlite').style.display = v === 'sqlite' ? 'block' : 'none';
        document.getElementById('db_mysql').style.display = v === 'mysql' ? 'block' : 'none';
        document.getElementById('db_libsql').style.display = v === 'libsql' ? 'block' : 'none';
    }
    sel.addEventListener('change', show);
    show();
})();
</script>
</body>
</html>

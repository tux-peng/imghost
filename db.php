<?php
// db.php - database connection and schema
declare(strict_types=1);

if (!defined('IMGHOST_INSTALLING')) {
    if (!file_exists(__DIR__ . '/config.php')) {
        header('Location: install.php');
        exit;
    }
    $config = require __DIR__ . '/config.php';
}

function db_driver(?array $cfg = null): string {
    $cfg = $cfg ?? $GLOBALS['config'] ?? [];
    $d = $cfg['db']['driver'] ?? null;
    if (is_string($d) && $d !== '') return $d;
    if (!empty($cfg['db_path'])) return 'sqlite';
    return 'sqlite';
}

function db_is_mysql(?array $cfg = null): bool {
    return db_driver($cfg) === 'mysql';
}

function db(): PDO|LibsqlConnection {
    static $pdo = null;
    if ($pdo === null) {
        global $config;
        $pdo = db_connect($config);
        init_schema($pdo);
    }
    return $pdo;
}

function db_connect(array $config): PDO|LibsqlConnection {
    $driver = db_driver($config);
    if ($driver === 'mysql') {
        $db = $config['db'] ?? [];
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (int) ($db['port'] ?? 3306);
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['user'] ?? '');
        $pass = (string) ($db['pass'] ?? '');
        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        if ($charset !== 'utf8mb4' && $charset !== 'utf8') $charset = 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('SET NAMES ' . $charset);
        return $pdo;
    }
    if ($driver === 'libsql') {
        $db = $config['db'] ?? [];
        $url = libsql_http_url((string) ($db['url'] ?? ''));
        $token = (string) ($db['token'] ?? '');
        $conn = new LibsqlConnection($url, $token);
        return $conn;
    }
    $path = $config['db']['path'] ?? ($config['db_path'] ?? (__DIR__ . '/database/site.db'));
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function schema_sql(string $driver): string {
    if ($driver === 'mysql') {
        return "
        CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT NOT NULL DEFAULT 0,
            created_at INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS galleries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            slug VARCHAR(32) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(2000) NOT NULL DEFAULT '',
            created_at INT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS images (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            gallery_id INT NULL,
            slug VARCHAR(32) NOT NULL UNIQUE,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            format VARCHAR(16) NOT NULL,
            mime VARCHAR(64) NOT NULL,
            size INT NOT NULL,
            uploaded_at INT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(gallery_id) REFERENCES galleries(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS invites (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
            created_by INT NULL,
            used_by INT NULL,
            created_at INT NOT NULL,
            used_at INT NULL,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(used_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(64) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
    }
    return "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS galleries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS images (
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
        CREATE TABLE IF NOT EXISTS invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            created_by INTEGER,
            used_by INTEGER,
            created_at INTEGER NOT NULL,
            used_at INTEGER,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(used_by) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ";
}

function init_schema(PDO|LibsqlConnection $db): void {
    $driver = db_driver();
    $sql = schema_sql($driver);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') continue;
        $db->exec($stmt);
    }

    if ($driver !== 'mysql') {
        try {
            $db->exec('ALTER TABLE images ADD COLUMN gallery_id INTEGER REFERENCES galleries(id) ON DELETE SET NULL');
        } catch (Throwable $e) {
            // Column already exists — ignore.
        }
    }
}

function settings_key_sql(): string {
    return db_is_mysql() ? '`key`' : 'key';
}

function export_config_php(array $config): string {
    $export = var_export($config, true);
    return "<?php\nreturn " . $export . ";\n";
}

function upsert_setting_sql(): string {
    if (db_is_mysql()) {
        return 'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)';
    }
    return 'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value';
}

class LibsqlConnection {
    private string $url;
    private string $token;
    private mixed $lastInsertId = '0';

    public function __construct(string $url, string $token) {
        $this->url = rtrim($url, '/');
        $this->token = $token;
    }

    public function setAttribute(int $k, mixed $v): bool {
        return true;
    }

    public function exec(string $sql): int {
        $this->pipeline([['sql' => $sql, 'args' => []]]);
        return 0;
    }

    public function query(string $sql): LibsqlStatement {
        $st = $this->prepare($sql);
        $st->execute([]);
        return $st;
    }

    public function prepare(string $sql): LibsqlStatement {
        return new LibsqlStatement($this, $sql);
    }

    public function lastInsertId(?string $name = null): string {
        return (string) $this->lastInsertId;
    }

    public function run(string $sql, array $args = []): array {
        $res = $this->pipeline([['sql' => $sql, 'args' => $args]]);
        $first = $res[0]['result'] ?? [];
        if (isset($first['last_insert_rowid'])) {
            $this->lastInsertId = (string) $first['last_insert_rowid'];
        }
        return $first;
    }

    private function pipeline(array $stmts): array {
        $endpoint = $this->url;
        if (!str_contains($endpoint, '/v2/pipeline')) {
            $endpoint .= '/v2/pipeline';
        }
        $requests = [];
        foreach ($stmts as $s) {
            $args = [];
            foreach ($s['args'] as $a) {
                $args[] = libsql_arg($a);
            }
            $requests[] = [
                'type' => 'execute',
                'stmt' => [
                    'sql' => $s['sql'],
                    'args' => $args,
                ],
            ];
        }
        $requests[] = ['type' => 'close'];
        $payload = json_encode(['requests' => $requests], JSON_UNESCAPED_SLASHES);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new PDOException('Could not reach LibSQL.');
        }
        $headers = ['Content-Type: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new PDOException('LibSQL request failed: ' . $err);
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new PDOException('LibSQL returned invalid JSON (HTTP ' . $code . ').');
        }
        if (!empty($json['error'])) {
            throw new PDOException((string) $json['error']);
        }
        $results = [];
        foreach ($json['results'] ?? [] as $r) {
            if (($r['type'] ?? '') === 'error') {
                $msg = $r['error']['message'] ?? 'LibSQL error';
                throw new PDOException((string) $msg);
            }
            if (($r['type'] ?? '') === 'ok' && isset($r['response']['result'])) {
                $results[] = ['result' => $r['response']['result']];
            }
        }
        return $results;
    }
}

class LibsqlStatement {
    private LibsqlConnection $conn;
    private string $sql;
    private array $rows = [];
    private int $i = 0;

    public function __construct(LibsqlConnection $conn, string $sql) {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        $result = $this->conn->run($this->sql, array_values($params));
        $cols = [];
        foreach ($result['cols'] ?? [] as $c) {
            $cols[] = $c['name'] ?? '';
        }
        $this->rows = [];
        foreach ($result['rows'] ?? [] as $row) {
            $assoc = [];
            foreach ($row as $idx => $cell) {
                $name = $cols[$idx] ?? (string) $idx;
                $assoc[$name] = libsql_cell_value($cell);
            }
            $this->rows[] = $assoc;
        }
        $this->i = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_ASSOC): mixed {
        if (!isset($this->rows[$this->i])) return false;
        return $this->rows[$this->i++];
    }

    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array {
        $out = array_slice($this->rows, $this->i);
        $this->i = count($this->rows);
        return $out;
    }

    public function fetchColumn(int $col = 0): mixed {
        $row = $this->fetch();
        if ($row === false) return false;
        $vals = array_values($row);
        return $vals[$col] ?? false;
    }
}

function libsql_http_url(string $url): string {
    $url = trim($url);
    if (str_starts_with($url, 'libsql://')) {
        $url = 'https://' . substr($url, strlen('libsql://'));
    }
    return rtrim($url, '/');
}

function libsql_arg(mixed $v): array {
    if ($v === null) return ['type' => 'null', 'value' => null];
    if (is_int($v)) return ['type' => 'integer', 'value' => (string) $v];
    if (is_float($v)) return ['type' => 'float', 'value' => $v];
    if (is_bool($v)) return ['type' => 'integer', 'value' => $v ? '1' : '0'];
    return ['type' => 'text', 'value' => (string) $v];
}

function libsql_cell_value(mixed $cell): mixed {
    if (!is_array($cell)) return $cell;
    if (array_key_exists('value', $cell)) return $cell['value'];
    return $cell[1] ?? ($cell[0] ?? null);
}

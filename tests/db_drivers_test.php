<?php
// tests/db_drivers_test.php - schema + config helpers for install drivers
declare(strict_types=1);

define('IMGHOST_INSTALLING', true);
require_once dirname(__DIR__) . '/db.php';

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

expect_eq(db_driver(['db' => ['driver' => 'mysql']]), 'mysql', 'mysql driver');
expect_eq(db_driver(['db' => ['driver' => 'libsql']]), 'libsql', 'libsql driver');
expect_eq(db_driver(['db_path' => '/tmp/x.db']), 'sqlite', 'legacy sqlite path');
expect_eq(db_driver(['db' => ['driver' => 'sqlite', 'path' => '/tmp/x.db']]), 'sqlite', 'sqlite driver');

$sqlite = schema_sql('sqlite');
expect_true(str_contains($sqlite, 'INTEGER PRIMARY KEY AUTOINCREMENT'), 'sqlite autoincrement');
expect_true(str_contains($sqlite, 'key TEXT PRIMARY KEY'), 'sqlite settings key');

$mysql = schema_sql('mysql');
expect_true(str_contains($mysql, 'AUTO_INCREMENT'), 'mysql autoincrement');
expect_true(str_contains($mysql, '`key`'), 'mysql quotes key');
expect_true(str_contains($mysql, 'ENGINE=InnoDB'), 'mysql innodb');

$jxl = schema_sql('libsql');
expect_true(str_contains($jxl, 'INTEGER PRIMARY KEY AUTOINCREMENT'), 'libsql uses sqlite schema');

expect_eq(libsql_http_url('libsql://demo.turso.io'), 'https://demo.turso.io', 'libsql url to https');
expect_eq(libsql_http_url('https://demo.turso.io/'), 'https://demo.turso.io', 'https url trimmed');

$cfg = export_config_php([
    'upload_path' => '/tmp/up',
    'db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'img', 'user' => 'u', 'pass' => 'p'],
]);
expect_true(str_starts_with($cfg, '<?php'), 'config is php');
expect_true(str_contains($cfg, "'driver' => 'mysql'"), 'config has mysql driver');

$arg = libsql_arg(12);
expect_eq($arg['type'], 'integer', 'libsql int arg type');
expect_eq(libsql_cell_value(['value' => 'x']), 'x', 'libsql cell value');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

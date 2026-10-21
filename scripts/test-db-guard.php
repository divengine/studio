<?php

/**
 * @file
 * Refuses a test database that could belong to a real site.
 *
 * Usage:
 *   php scripts/test-db-guard.php <pgsql-url>            # a test database
 *   php scripts/test-db-guard.php --server <pgsql-url>   # a server only
 *
 * A test database is refused when:
 * - it is the database of a site configured in web/sites/<site>/settings.php
 *   (same host, port and database name), or
 * - it already holds a Drupal installation: Drupal's own tables without a
 *   test prefix (PHPUnit only creates prefixed tables, "test12345config").
 *
 * In both modes the server must run PostgreSQL 16 or later.
 *
 * Exits 0 when the target is safe to use and prints why otherwise (exit 1).
 * Nothing is written to the database. The URL is never printed.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$server_only = FALSE;
if (($args[0] ?? '') === '--server') {
  $server_only = TRUE;
  array_shift($args);
}
$url = $args[0] ?? '';

/**
 * Prints a refusal and exits.
 */
function refuse(string $reason): never {
  fwrite(STDERR, "✗ Refusing the test database: $reason\n");
  exit(1);
}

/**
 * Host, port and database name of a connection, comparable across spellings.
 */
function target(string $host, int|string|null $port, string $database): string {
  $host = strtolower(trim($host, '[]'));
  if ($host === '' || $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.') || str_starts_with($host, '/')) {
    $host = 'local';
  }
  return $host . ':' . ((int) ($port ?: 5432)) . '/' . $database;
}

$parts = parse_url($url);
if (($parts['scheme'] ?? '') !== 'pgsql' || empty($parts['host'])) {
  refuse('give a pgsql://user:password@host:port' . ($server_only ? '' : '/database') . ' URL (PostgreSQL only).');
}
$database = ltrim(rawurldecode($parts['path'] ?? ''), '/');
if (!$server_only && $database === '') {
  refuse('the URL names no database.');
}

if (!$server_only) {
  // Databases of the sites of this checkout. Sites created by the check
  // scripts themselves (installcheck-*) are throw-away and are skipped.
  $root = dirname(__DIR__);
  $mine = target($parts['host'], $parts['port'] ?? NULL, $database);
  foreach (glob("$root/web/sites/*/settings.php") ?: [] as $settings_file) {
    $site_path = 'sites/' . basename(dirname($settings_file));
    if (str_starts_with(basename($site_path), 'installcheck-')) {
      continue;
    }
    $databases = (static function (string $settings_file, string $site_path): array {
      $app_root = dirname($settings_file, 3);
      $settings = [];
      $config = [];
      $databases = [];
      // Settings files may include others relative to the site directory.
      $cwd = getcwd();
      chdir($app_root);
      try {
        ob_start();
        include $settings_file;
      }
      catch (\Throwable) {
        // A settings file that does not load outside Drupal names nothing.
      }
      finally {
        ob_end_clean();
        chdir($cwd);
      }
      return $databases;
    })($settings_file, $site_path);
    foreach ($databases as $targets) {
      foreach ((array) $targets as $info) {
        if (is_array($info) && isset($info['database']) && target((string) ($info['host'] ?? ''), $info['port'] ?? NULL, (string) $info['database']) === $mine) {
          refuse("it is the database of $site_path.");
        }
      }
    }
  }
}

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $server_only ? 'postgres' : $database);
try {
  $pdo = new \PDO($dsn, rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
}
catch (\PDOException $e) {
  refuse('cannot connect (' . preg_replace('/password=\S+/', 'password=…', $e->getMessage()) . ').');
}

$version = (int) $pdo->query('SHOW server_version_num')->fetchColumn();
if ($version < 160000) {
  refuse(sprintf('PostgreSQL %d.%d is too old; Divengine Studio needs 16 or later.', intdiv($version, 10000), $version % 10000));
}

if (!$server_only) {
  $found = $pdo->query("SELECT schemaname || '.' || tablename FROM pg_catalog.pg_tables WHERE tablename IN ('config', 'key_value', 'router', 'users_field_data') ORDER BY 1 LIMIT 1")->fetchColumn();
  if ($found !== FALSE) {
    refuse("it already holds a Drupal installation (table $found). Use an empty database created for the tests.");
  }
}

exit(0);

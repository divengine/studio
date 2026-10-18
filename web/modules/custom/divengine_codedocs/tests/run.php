<?php

/**
 * Real Drupal database API integration, isolated in an in-memory SQLite DB.
 * Run from Studio: php web/modules/custom/divengine_codedocs/tests/run.php
 * Requires composer install --no-dev and pdo_sqlite; never touches site data.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\divengine_codedocs\CodeDocIndex;
use Drupal\divengine_codedocs\Contract\Adapter\ContractAdapter;
use Drupal\divengine_codedocs\Contract\Adapter\JavadocAdapter;
use Drupal\divengine_codedocs\Contract\Adapter\TypeScriptAdapter;
use Drupal\divengine_codedocs\Contract\AdapterManager;
use Drupal\divengine_codedocs\Contract\Canonical;
use Drupal\divengine_codedocs\Contract\ContractException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 5);
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Drupal\\sqlite\\', $root . '/web/core/modules/sqlite/src');
$loader->addPsr4('Drupal\\divengine_codedocs\\', dirname(__DIR__) . '/src');
Drupal::setContainer(new ContainerBuilder());
require_once dirname(__DIR__) . '/divengine_codedocs.install';
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
  if ($level & (E_WARNING | E_NOTICE | E_USER_WARNING)) {
    throw new ErrorException($message, 0, $level, $file, $line);
  }
  return FALSE;
});
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) { throw new RuntimeException($message); }
  $checks++;
};
$reject = static function (callable $callback, string $message) use ($check): void {
  try { $callback(); }
  catch (ContractException) { $check(TRUE, $message); return; }
  throw new RuntimeException('Expected rejection: ' . $message);
};
Database::addConnectionInfo('codedocs_test', 'default', ['driver' => 'sqlite', 'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite', 'database' => ':memory:', 'prefix' => '']);
$db = Database::getConnection('default', 'codedocs_test');
foreach (divengine_codedocs_schema() as $table => $spec) { $db->schema()->createTable($table, $spec); }
$index = new CodeDocIndex($db);
$manager = new AdapterManager();
foreach ([new ContractAdapter(), new JavadocAdapter(), new TypeScriptAdapter()] as $adapter) { $manager->addAdapter($adapter); }
foreach (['contract', 'javadoc', 'tsdoc'] as $n => $format) {
  $document = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $format . '.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  $adapter = $manager->resolve($document);
  $check($adapter->id() === $format, 'Auto-detect ' . $format);
  $check($adapter->meta($document)['project'] === $document['project'], 'Preserve project');
  $sid = $n + 1;
  $units = Canonical::units($adapter->units($document, $document));
  // Separate writes simulate cross-chunk references and parents.
  foreach ($units as $unit) { $index->write($sid, [$unit]); }
  $counts = $index->finalize($sid);
  $check($counts['unit_count'] === count($units), 'Count units');
  $check($counts['symbol_count'] > 0, 'Count symbols');
  $check($counts['documented_count'] <= $counts['documentable_count'], 'Coverage bounds');
  $check(count($index->search($sid, 'find')) === 1, 'Search symbols');
  $reject(fn () => $index->write($sid, [$units[0]]), 'Reject duplicate unit');
  if ($format === 'contract') {
    $method = $index->search($sid, 'Shop::find')[0];
    $details = $index->symbol($sid, (int) $method['id']);
    $check(!empty($details['parent_id']), 'Resolve member parent');
    $check(!empty($details['refs'][0]['target_symbol_id']), 'Resolve symbol-owned reference without losing its source');
    $check($details['params'][0]['name'] === 'id', 'Keep params');
    $moved = $document;
    $moved['units'][0]['symbols'][0]['members'][0]['line'] = 999;
    $index->write(10, Canonical::units($adapter->units($moved, $moved)));
    $index->finalize(10);
    $check($index->fingerprints(1) === $index->fingerprints(10), 'Line changes do not change content');
    $moved['units'][0]['symbols'][0]['members'][0]['doc']['summary'] = 'Changed documentation.';
    $index->write(11, Canonical::units($adapter->units($moved, $moved)));
    $index->finalize(11);
    $diff = $index->diff(1, 11);
    $check($diff['symbol']['changed'] === ['Shop::find(string)'], 'Diff identifies the changed member only');
  }
  if ($format === 'javadoc') {
    $field = $index->search($sid, 'LIMIT')[0];
    $check($field['value'] === '0', 'Preserve zero constant');
    $ref = $index->rows('ref', $sid, ['target_key' => 'shop.Catalog'])[0];
    $check(!empty($ref['target_symbol_id']), 'Resolve Java type');
  }
  if ($format === 'tsdoc') {
    $check($counts['endpoint_count'] === 2, 'Keep routes and HTTP calls');
    $check($counts['ref_count'] === 3, 'Keep props, imports and edges');
    $call = $index->rows('endpoint', $sid, ['kind' => 'http_call'])[0];
    $check(!empty($call['symbol_id']), 'Resolve calling symbol');
    $refs = $index->rows('ref', $sid, ['kind' => 'imports']);
    $check(!empty($refs[0]['target_unit_id']), 'Resolve module import');
  }
}
$reject(fn () => $manager->resolve(['language' => 'unsupported']), 'Reject unknown format');
$reject(fn () => (new ContractAdapter())->meta(['contract_version' => '2.0']), 'Reject unsupported version');
$reject(fn () => Canonical::units([['key' => 'a'], ['key' => 'a']]), 'Reject duplicate keys');
$reject(fn () => Canonical::units([['key' => 'a', 'symbols' => [['key' => 'b', 'refs' => [['kind' => 'type']]]]]]), 'Reject missing reference target');
$index->write(20, Canonical::units([['key' => 'a', 'symbols' => [['key' => 'a', 'parent' => 'b'], ['key' => 'b', 'parent' => 'a']]]]));
$reject(fn () => $index->finalize(20), 'Reject cyclic parents');
$index->write(21, Canonical::units([['key' => 'a', 'symbols' => [['key' => 'a', 'parent' => 'absent']]]]));
$reject(fn () => $index->finalize(21), 'Reject missing parent');
$index->write(22, Canonical::units([['key' => 'a', 'symbols' => [['key' => 'a', 'refs' => [['kind' => 'type', 'target' => 'a', 'external' => TRUE]]]]]]));
$index->finalize(22);
$check($index->rows('ref', 22)[0]['target_symbol_id'] === NULL, 'External references remain external');
$before = $index->fingerprints(1);
$transaction = $db->startTransaction();
try {
  $index->write(23, Canonical::units([['key' => 'valid'], ['key' => 'bad', 'symbols' => [['key' => 'x', 'name' => str_repeat('x', 256)]]]]));
  throw new RuntimeException('Expected failed batch');
}
catch (ContractException) { $transaction->rollBack(); }
unset($transaction);
$check(!$index->rows('unit', 23), 'A failed batch rolls back all its rows');
$index->forget([2]);
$check(!$index->rows('symbol', 2) && $index->fingerprints(1) === $before, 'Deletion is isolated to the requested snapshot');
foreach (glob(dirname(__DIR__) . '/*.yml') as $file) { Yaml::parseFile($file); }
Yaml::parseFile($root . '/recipes/divengine_studio/recipe.yml');
echo "Code docs: $checks checks passed (adapters, real SQLite schema/queries, resolution, coverage, diff, rollback, isolation, YAML).\n";

<?php

/** Run with Drush, through refresh.sh. Creates only explicitly named demo data. */
declare(strict_types=1);

$root = realpath(getenv('CODEDOCS_DEMO_ROOT') ?: '') ?: throw new RuntimeException('Set CODEDOCS_DEMO_ROOT.');
$entities = \Drupal::entityTypeManager();
$admin = $entities->getStorage('user')->load(1);
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($admin);
try {
  $config = \Drupal::configFactory()->getEditable('mcp.settings');
  $config->set('plugins.codedocs', ['enabled' => TRUE, 'config' => []])->save();
  $role = $entities->getStorage('user_role')->load('mcp_agent');
  if ($role) { $role->grantPermission('use code docs')->save(); }
  $bot = $entities->getStorage('user')->load((int) $config->get('auth_settings.token_user'));
  $developments = $entities->getStorage('development');
  $found = $developments->loadByProperties(['name' => 'Code Docs Demo - Shop']);
  $development = reset($found) ?: $developments->create(['name' => 'Code Docs Demo - Shop', 'uid' => 1, 'responsible' => 1, 'objective' => 'Neutral PHP and TypeScript examples: source code, extracted documentation and agent access.']);
  if ($bot && !$development->isVisibleTo((int) $bot->id())) {
    $development->get('members')->appendItem(['target_id' => $bot->id()]);
  }
  $development->save();
  // Add exactly the demo workspace, preserving all existing allowed roots.
  $source = \Drupal::configFactory()->getEditable('divengine_source.settings');
  $roots = preg_split('/\R/', trim((string) $source->get('allowed_roots')));
  if (!in_array($root, $roots, TRUE)) {
    $roots[] = $root;
    $source->set('allowed_roots', implode("\n", array_filter($roots)))->save();
  }
  $manifest = ['development' => (int) $development->id(), 'projects' => []];
  // Folder of the extractor's file paths inside each repository.
  $roots = ['php' => '', 'ts' => '', 'java' => 'src/main/java'];
  foreach (['php' => ['php-shop', 'Shop backend', 'php'], 'ts' => ['ts-shop', 'Shop frontend', 'typescript'], 'java' => ['java-shop', 'Shop services', 'java']] as $file => [$folder, $label, $language]) {
    $identifier = 'demo-shop-' . $file;
    $repositories = $entities->getStorage('source_repository');
    $found = $repositories->loadByProperties(['path' => $root . '/' . $folder]);
    $repository = reset($found) ?: $repositories->create(['development' => $development->id(), 'name' => $label, 'path' => $root . '/' . $folder, 'uid' => 1, 'description' => 'Local, invented Code Docs example.']);
    $repository->save();
    $refs = $entities->getStorage('source_ref');
    $found = $refs->loadByProperties(['repository' => $repository->id(), 'name' => 'main']);
    $ref = reset($found) ?: $refs->create(['repository' => $repository->id(), 'development' => $development->id(), 'name' => 'main', 'is_default' => TRUE, 'status' => TRUE]);
    $ref->save();
    \Drupal::service('divengine_source.index')->index($ref);
    $sets = $entities->getStorage('codedoc_set');
    $found = $sets->loadByProperties(['identifier' => $identifier]);
    $set = reset($found) ?: $sets->create(['development' => $development->id(), 'uid' => 1, 'name' => $label, 'identifier' => $identifier, 'language' => $language, 'repository' => $repository->id(), 'source_root' => $roots[$file], 'description' => 'Generated from the runnable shop example. Refresh with scripts/codedocs-demo/refresh.sh.']);
    if ((string) $set->get('development')->target_id !== (string) $development->id()) {
      throw new RuntimeException('Demo identifier is already registered in another Development: ' . $identifier);
    }
    $set->save();
    $document = json_decode(file_get_contents($root . '/output/' . $file . '.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $result = \Drupal::service('divengine_codedocs.importer')->import($document);
    $snapshot = $result['snapshot'];
    $symbol = \Drupal::service('divengine_codedocs.index')->search((int) $snapshot->id(), ['php' => '::total', 'ts' => 'cartTotal', 'java' => 'place'][$file])[0] ?? NULL;
    $manifest['projects'][$identifier] = ['set' => (int) $set->id(), 'snapshot' => (int) $snapshot->id(), 'symbol' => $symbol ? (int) $symbol['id'] : NULL, 'repository' => (int) $repository->id(), 'status' => $result['status']];
    echo $identifier . ': ' . $result['status'] . ', ' . ($snapshot->count('symbol_count') + $snapshot->count('member_count')) . " symbols\n";
  }
  file_put_contents($root . '/output/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
  echo 'Development: /development/' . $development->id() . "/docs\n";
}
finally { $switcher->switchBack(); }

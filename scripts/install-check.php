<?php

/**
 * @file
 * Read-only health checks of a freshly installed Divengine Studio site.
 *
 * Run with: drush php:script scripts/install-check.php -- <expect-mcp:0|1> <site-name>
 * Prints one line per check and FAILED_CHECKS=<n> at the end. Requirement
 * errors are checked separately with "drush core:requirements".
 */

declare(strict_types=1);

$expect_mcp = ($extra[0] ?? '0') === '1';
$site_name = $extra[1] ?? NULL;

$checks = [];
$change_list = \Drupal::entityDefinitionUpdateManager()->getChangeList();
$checks['no pending entity or field definition updates' . ($change_list ? ' (' . implode(', ', array_keys($change_list)) . ')' : '')] = $change_list === [];

$theme = \Drupal::config('system.theme');
$checks['default theme is divengine_studio_theme'] = $theme->get('default') === 'divengine_studio_theme';
$checks['administration theme is gin'] = $theme->get('admin') === 'gin';
$checks['front page is /studio'] = \Drupal::config('system.site')->get('page.front') === '/studio';
$checks['/studio is routed'] = \Drupal::service('path.validator')->getUrlIfValidWithoutAccessCheck('/studio')?->getRouteName() === 'divengine_core.home';
if ($site_name !== NULL) {
  $checks['the site name given at installation is kept'] = \Drupal::config('system.site')->get('name') === $site_name;
}

foreach ([
  'block.block.gin_breadcrumbs',
  'block.block.gin_content',
  'block.block.gin_help',
  'block.block.gin_local_actions',
  'block.block.gin_messages',
  'block.block.gin_page_title',
  'block.block.gin_primary_local_tasks',
  'block.block.gin_secondary_local_tasks',
  'views.view.files',
  'views.view.moderated_content',
  'core.entity_form_display.project_task.project_task.default',
] as $name) {
  $checks["configuration $name"] = !\Drupal::config($name)->isNew();
}

$modules = \Drupal::moduleHandler();
foreach (['divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa', 'divengine_project', 'divengine_timesheet', 'divengine_thread', 'divengine_articles', 'divengine_source', 'divengine_database', 'divengine_codedocs', 'divengine_external'] as $module) {
  $checks["module $module"] = $modules->moduleExists($module);
}
$checks[$expect_mcp ? 'MCP integration installed' : 'MCP integration not installed'] = $modules->moduleExists('divengine_mcp') === $expect_mcp;
if ($expect_mcp) {
  // OAuth for MCP clients (ADR-0017): the scope and token types are config
  // entities the recipe has to import.
  $checks['MCP OAuth installed'] = $modules->moduleExists('divengine_mcp_oauth');
  $checks['MCP OAuth scope'] = (bool) \Drupal::entityTypeManager()->getStorage('oauth2_scope')->load('mcp');
  $checks['OAuth token types'] = (bool) \Drupal::entityTypeManager()->getStorage('oauth2_token_type')->load('access_token');
  $checks['role mcp_user'] = (bool) \Drupal\user\Entity\Role::load('mcp_user');
  // Engineering writes (ADR-0019) are installed but granted to nobody: no
  // account has the MCP writer role, and the read-only roles cannot write.
  $checks['MCP engineering writes installed'] = $modules->moduleExists('divengine_mcp_write');
  $checks['MCP write scope on mcp_editor'] = ($scope = \Drupal::entityTypeManager()->getStorage('oauth2_scope')->load('mcp_write')) && $scope->getGranularity()->getConfiguration()['role'] === 'mcp_editor';
  $checks['nobody is an MCP writer'] = !\Drupal::entityTypeManager()->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('roles', 'mcp_writer')->count()->execute();
  $checks['read-only MCP roles cannot write'] = !array_filter(['mcp_agent', 'mcp_user'], fn ($role) => \Drupal\user\Entity\Role::load($role)?->hasPermission('write engineering records through mcp'));
}
// Analyzers push database snapshots with HTTP Basic.
$checks['module basic_auth'] = $modules->moduleExists('basic_auth');

foreach (['content_editor', 'studio_manager', 'project_manager', 'qa_tester', 'qa_lead', 'qa_manager', 'database_analyzer', 'code_docs_publisher'] as $role) {
  $checks["role $role"] = (bool) \Drupal\user\Entity\Role::load($role);
}
$checks['every record type has a discussion'] = count(\Drupal::service('divengine_thread.manager')->types()) >= 17
  && (bool) \Drupal\field\Entity\FieldConfig::loadByName('node', 'knowledge_article', 'thread');
$checks['[[CODE]] filter on Basic HTML'] = (bool) \Drupal\filter\Entity\FilterFormat::load('basic_html')?->filters('divengine_record_references')->status;
$checks['articles are edited in the Studio theme'] = \Drupal::config('node.settings')->get('use_admin_theme') === FALSE;
$standard_case = \Drupal::entityTypeManager()->getStorage('development_case')->load('standard');
$checks['Development cases recommend verification'] = $standard_case && in_array('requirements_verified', array_column($standard_case->getLines(), 'target'), TRUE);

$schema = \Drupal::database()->schema();
$checks['database facts tables'] = $schema->tableExists('divengine_db_fk_column');
$checks['code docs facts tables'] = $schema->tableExists('divengine_codedoc_ref') && $schema->tableExists('divengine_codedoc_batch');
$adapters = \Drupal::service('divengine_codedocs.adapters');
$checks['code docs adapters: contract, javadoc, tsdoc'] = $adapters->resolve(['contract' => 'divengine.codedocs'])->id() === 'contract'
  && $adapters->resolve([], 'javadoc')->id() === 'javadoc'
  && $adapters->resolve([], 'tsdoc')->id() === 'tsdoc';
try {
  $demo = file_get_contents($modules->getModule('divengine_database')->getPath() . '/tests/fixtures/demo_shop_v1.json');
  \Drupal::service('divengine_database.importer')->validate(\Drupal::service('divengine_database.adapters')->toContract($demo));
  $checks['the demo snapshot passes the contract checks'] = TRUE;
}
catch (\Throwable $e) {
  $checks['the demo snapshot passes the contract checks (' . $e->getMessage() . ')'] = FALSE;
}

$failed = 0;
foreach ($checks as $label => $ok) {
  echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
  $failed += $ok ? 0 : 1;
}
echo 'FAILED_CHECKS=' . $failed . PHP_EOL;

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
foreach (['divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa', 'divengine_project', 'divengine_timesheet', 'divengine_thread', 'divengine_articles', 'divengine_source', 'divengine_database', 'divengine_codedocs'] as $module) {
  $checks["module $module"] = $modules->moduleExists($module);
}
$checks[$expect_mcp ? 'MCP integration installed' : 'MCP integration not installed'] = $modules->moduleExists('divengine_mcp') === $expect_mcp;

$failed = 0;
foreach ($checks as $label => $ok) {
  echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
  $failed += $ok ? 0 : 1;
}
echo 'FAILED_CHECKS=' . $failed . PHP_EOL;

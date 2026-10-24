<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Cron runs without the MCP module's broken cleanup hook.
 *
 * @see \Drupal\divengine_mcp\Hook\McpCronHooks
 */
#[RunTestsInSeparateProcesses]
class McpCronTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'serialization',
    'jsonrpc',
    'key',
    'mcp',
    'divengine_core',
    'divengine_mcp',
  ];

  /**
   * The mcp module has no schema for its plugins' settings.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The removed service is still missing, so mcp_cron() would fail.
   */
  public function testServiceIsGone(): void {
    $this->assertFalse($this->container->has('mcp.client_message.storage'));
  }

  /**
   * mcp_cron() is no longer an implementation of hook_cron().
   */
  public function testMcpCronIsNotInvoked(): void {
    $modules = [];
    $this->container->get('module_handler')->invokeAllWith('cron', function (callable $hook, string $module) use (&$modules): void {
      $modules[] = $module;
    });
    $this->assertNotContains('mcp', $modules);
  }

}

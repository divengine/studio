<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\divengine_mcp\Drush\Commands\DivengineMcpCommands;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Re-applying the MCP recipe keeps what the site set up (ADR-0016).
 *
 * Studio sites are updated by re-applying their recipes (ADR-0015), so the
 * recipe must not reset what the setup command stored per installation.
 */
#[RunTestsInSeparateProcesses]
class McpRecipeReapplyTest extends KernelTestBase {

  protected static $modules = ['system', 'user'];

  /**
   * The mcp module has no schema for its plugins' settings.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Applies recipes/divengine_studio_mcp.
   */
  protected function applyRecipe(): void {
    RecipeRunner::processRecipe(Recipe::createFromDirectory(dirname($this->root) . '/recipes/divengine_studio_mcp'));
  }

  public function testFreshInstallGetsTheDefaults(): void {
    $this->applyRecipe();
    $settings = $this->config('mcp.settings');
    $this->assertTrue($settings->get('enable_auth'), 'Authentication is on before the setup command runs.');
    $this->assertEmpty($settings->get('auth_settings.enable_token_auth'), 'Token access stays off until the setup command runs.');
    foreach (['general', 'content', 'jsonapi', 'code', 'database', 'codedocs'] as $plugin) {
      $this->assertTrue($settings->get("plugins.$plugin.enabled"), "The $plugin plugin is on.");
    }
    $this->assertSame(['article' => 'article', 'page' => 'page'], $settings->get('plugins.content.config.content_types'));
  }

  public function testReapplyingKeepsAdministratorSettings(): void {
    $this->applyRecipe();

    // What "drush divengine:mcp-setup" stores.
    $access = [
      'enable_token_auth' => TRUE,
      'token_key' => DivengineMcpCommands::KEY,
      'token_user' => 7,
      'enable_basic_auth' => FALSE,
    ];
    // What an administrator may change on the settings form.
    $content = ['enabled' => TRUE, 'config' => ['content_types' => ['page' => 'page']]];
    $this->config('mcp.settings')
      ->set('auth_settings', $access)
      ->set('plugins.jsonapi.enabled', FALSE)
      ->set('plugins.content', $content)
      ->save();

    $this->applyRecipe();
    $settings = $this->config('mcp.settings');
    $this->assertEquals($access, $settings->get('auth_settings'), 'Re-applying the recipe keeps token access.');
    $this->assertFalse($settings->get('plugins.jsonapi.enabled'), 'A plugin turned off stays off.');
    $this->assertEquals($content, $settings->get('plugins.content'), 'Plugin settings are kept as they are.');
  }

  public function testReapplyingTurnsAuthenticationBackOn(): void {
    $this->applyRecipe();
    $this->config('mcp.settings')->set('enable_auth', FALSE)->save();
    $this->applyRecipe();
    $this->assertTrue($this->config('mcp.settings')->get('enable_auth'));
  }

  public function testReapplyingAddsMissingPlugins(): void {
    $this->applyRecipe();
    // A site from before the recipe listed codedocs.
    $this->config('mcp.settings')->clear('plugins.codedocs')->set('plugins.code.enabled', FALSE)->save();
    $this->applyRecipe();
    $settings = $this->config('mcp.settings');
    $this->assertSame(['enabled' => TRUE, 'config' => []], $settings->get('plugins.codedocs'));
    $this->assertFalse($settings->get('plugins.code.enabled'));
  }

}

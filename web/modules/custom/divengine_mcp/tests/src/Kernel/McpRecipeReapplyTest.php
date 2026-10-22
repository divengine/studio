<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\divengine_mcp\Drush\Commands\DivengineMcpCommands;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Re-applying the MCP recipe keeps the access set up by divengine:mcp-setup.
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

  public function testTokenAccessSurvivesReapplying(): void {
    $this->applyRecipe();
    $settings = $this->config('mcp.settings');
    $this->assertTrue($settings->get('enable_auth'), 'Authentication is on before the setup command runs.');
    $this->assertEmpty($settings->get('auth_settings.enable_token_auth'), 'Token access stays off until the setup command runs.');

    // What "drush divengine:mcp-setup" stores.
    $access = [
      'enable_token_auth' => TRUE,
      'token_key' => DivengineMcpCommands::KEY,
      'token_user' => 7,
      'enable_basic_auth' => FALSE,
    ];
    $this->config('mcp.settings')->set('auth_settings', $access)->save();

    $this->applyRecipe();
    $settings = $this->config('mcp.settings');
    $this->assertTrue($settings->get('enable_auth'));
    $this->assertEquals($access, $settings->get('auth_settings'), 'Re-applying the recipe keeps token access.');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_studio_installer\Functional;

use Drupal\FunctionalTests\Installer\InstallerTestBase;

/**
 * Installs Divengine Studio through the interactive web installer.
 *
 * The installer is opened at /core/install.php without parameters: the
 * distribution is selected automatically, its screens are branded, the
 * integrations step comes before the recipe batches and the site
 * configuration form follows them.
 */
abstract class StudioInstallerTestBase extends InstallerTestBase {

  protected $profile = 'divengine_studio_installer';

  /**
   * The default theme set by the recipe, kept by the test harness.
   *
   * @var string
   */
  protected $defaultTheme = 'divengine_studio_theme';

  /**
   * Whether the MCP integration is selected in the installer.
   */
  protected bool $selectMcp = FALSE;

  protected function setUpLanguage(): void {
    $this->assertSession()->titleEquals('Choose language | Divengine Studio');
    $this->assertSession()->elementExists('css', 'img.dv-installer__logo');
    $this->assertSession()->elementExists('css', 'a.skip-link[href="#main-content"]');
    $this->assertSession()->responseContains('divengine_studio_installer_theme/css/installer.css');
    // The distribution is chosen without a profile selection step.
    $this->assertSession()->pageTextNotContains('Select an installation profile');
    parent::setUpLanguage();
  }

  protected function setUpProfile(): void {
    // Skipped: the distribution profile is selected automatically.
  }

  protected function setUpSite(): void {
    $this->assertSession()->titleEquals('Choose integrations | Divengine Studio');
    $this->assertSession()->checkboxNotChecked('mcp');
    $this->assertSession()->pageTextContains('drush divengine:mcp-setup');
    $this->submitForm($this->selectMcp ? ['mcp' => TRUE] : [], 'Save and continue');

    // The recipe batches ran; the site configuration form follows them.
    $this->assertSession()->titleEquals('Configure site | Divengine Studio');
    $this->assertSession()->fieldValueEquals('site_name', 'Divengine Studio');
    // No e-mail address or credential is suggested by the distribution.
    $this->assertSession()->fieldValueEquals('account[name]', '');
    // Without JavaScript, BigPipe renders status messages in the response
    // only when it knows the client has no JavaScript.
    $this->getSession()->setCookie('big_pipe_nojs', '1');
    parent::setUpSite();
  }

  /**
   * The installed site is the complete, healthy distribution.
   */
  public function testInstalled(): void {
    // The installer finishes at /studio, the Studio home, which sends the
    // signed-in administrator to the first workspace page they can use.
    $this->assertSession()->addressEquals('my-work');
    $this->assertSession()->statusCodeEquals(200);
    // The first page after installing tells how to finish the MCP setup.
    if ($this->selectMcp) {
      $this->assertSession()->pageTextContains('drush divengine:mcp-setup');
    }
    else {
      $this->assertSession()->pageTextNotContains('divengine:mcp-setup');
    }
    $this->drupalGet('<front>');
    $this->assertSession()->addressEquals('my-work');

    $this->assertSame('divengine_studio_installer', \Drupal::installProfile());
    $theme = $this->config('system.theme');
    $this->assertSame('divengine_studio_theme', $theme->get('default'));
    $this->assertSame('gin', $theme->get('admin'));
    $this->assertSame('/studio', $this->config('system.site')->get('page.front'));
    // The name typed in the configuration form is kept.
    $this->assertSame($this->parameters['forms']['install_configure_form']['site_name'], $this->config('system.site')->get('name'));

    $this->assertSame([], \Drupal::entityDefinitionUpdateManager()->getChangeList(), 'No entity or field definition update is pending.');
    foreach (static::expectedConfiguration() as $name) {
      $this->assertFalse($this->config($name)->isNew(), "$name exists.");
    }
    $this->assertNull(\Drupal::state()->get('divengine_studio_installer.mcp'), 'The installer choice is not kept after installation.');
    $this->assertSame($this->selectMcp, \Drupal::moduleHandler()->moduleExists('divengine_mcp'));
  }

  /**
   * Configuration a fresh installation must contain.
   */
  public static function expectedConfiguration(): array {
    return [
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
    ];
  }

}

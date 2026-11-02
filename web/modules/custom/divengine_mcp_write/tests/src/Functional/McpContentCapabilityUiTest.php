<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp_write\Functional;

use Drupal\divengine_mcp_write\Entity\McpContentCapability;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Administrators configure MCP content capabilities in the UI (#86).
 */
#[RunTestsInSeparateProcesses]
class McpContentCapabilityUiTest extends BrowserTestBase {

  protected static $modules = ['node', 'field', 'text', 'divengine_mcp_write'];

  protected $defaultTheme = 'stark';

  public function testConfigureABundle(): void {
    // jsonrpc 2.1.x prints this PHP 8.4 deprecation during installation, as
    // in TraceAccessHttpTest; accept only that known output.
    $notice = preg_quote('Deprecated: Drupal\\jsonrpc\\Annotation\\JsonRpcMethod::access(): Implicitly marking parameter $account as nullable is deprecated, the explicit nullable type must be used instead in ', '/');
    $this->expectOutputRegex('/\A(?:\s*' . $notice . '[^\r\n]+JsonRpcMethod\.php on line \d+\s*)?\z/');
    NodeType::create(['type' => 'brief', 'name' => 'Brief'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_notes', 'type' => 'string_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'brief', 'field_name' => 'field_notes', 'label' => 'Notes'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_attachment', 'type' => 'file'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'brief', 'field_name' => 'field_attachment', 'label' => 'Attachment'])->save();

    // Without the permission, the page is closed.
    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet('/admin/config/services/mcp-content');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer mcp content capabilities', 'access administration pages']));
    $this->drupalGet('/admin/config/services/mcp-content');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No content type is enabled for MCP.');

    // Only supported bundles are offered; then only writable fields.
    $this->drupalGet('/admin/config/services/mcp-content/add');
    $this->assertSession()->optionExists('target', 'node--brief');
    $this->assertSession()->optionNotExists('target', 'user--user');
    $this->submitForm(['target' => 'node--brief'], 'Continue');
    $this->assertSession()->fieldExists('fields[title]');
    $this->assertSession()->fieldExists('fields[field_notes]');
    foreach (['uid', 'status', 'langcode', 'created', 'promote', 'field_attachment', 'revision_log'] as $field) {
      $this->assertSession()->fieldNotExists("fields[$field]");
    }
    $this->assertSession()->checkboxNotChecked('operations[read]');
    $this->assertSession()->checkboxNotChecked('operations[delete]');

    // Writes without read are refused.
    $this->submitForm(['operations[create]' => TRUE, 'fields[title]' => TRUE], 'Save');
    $this->assertSession()->pageTextContains('Writes need read');

    $this->submitForm(['operations[read]' => TRUE, 'operations[create]' => TRUE, 'fields[title]' => TRUE, 'fields[field_notes]' => TRUE], 'Save');
    $this->assertSession()->pageTextContains('Saved the MCP capability for Brief.');
    $capability = McpContentCapability::load('node--brief');
    $this->assertSame(['read', 'create'], $capability->getOperations());
    $this->assertSame(['title', 'field_notes'], $capability->getWritableFields());
    $this->assertSession()->pageTextContains('read, create');
    $this->assertSession()->pageTextContains('In use');

    // The form changes no permission.
    $this->assertSame([], array_filter(Role::loadMultiple(), fn (Role $role) => $role->hasPermission('create brief content')));
    // A configured bundle is not offered twice.
    $this->drupalGet('/admin/config/services/mcp-content/add');
    $this->assertSession()->optionNotExists('target', 'node--brief');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_mcp_write\Form\McpContentCapabilityForm;
use Drupal\divengine_mcp_write\McpContentCapabilityListBuilder;

/**
 * What MCP clients may do with one content bundle (#86, ADR-0020).
 *
 * Every operation is off until an administrator turns it on. Writable fields
 * are listed one by one; the fields that decide ownership, publication,
 * moderation, revisions and the Development are never writable. The ID is
 * "<entity type>--<bundle>", the name clients use.
 */
#[ConfigEntityType(
  id: 'mcp_content_capability',
  label: new TranslatableMarkup('MCP content capability'),
  label_collection: new TranslatableMarkup('MCP content'),
  label_singular: new TranslatableMarkup('MCP content capability'),
  label_plural: new TranslatableMarkup('MCP content capabilities'),
  config_prefix: 'content_capability',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => McpContentCapabilityListBuilder::class,
    'form' => [
      'add' => McpContentCapabilityForm::class,
      'edit' => McpContentCapabilityForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/services/mcp-content',
    'add-form' => '/admin/config/services/mcp-content/add',
    'edit-form' => '/admin/config/services/mcp-content/{mcp_content_capability}',
    'delete-form' => '/admin/config/services/mcp-content/{mcp_content_capability}/delete',
  ],
  admin_permission: 'administer mcp content capabilities',
  config_export: [
    'id',
    'label',
    'entity_type',
    'bundle',
    'operations',
    'fields',
    'development_field',
    'require_development',
  ],
)]
class McpContentCapability extends ConfigEntityBase {

  /**
   * The operations a capability may enable.
   */
  const OPERATIONS = ['read', 'create', 'update', 'delete'];

  protected string $id = '';

  protected string $label = '';

  protected string $entity_type = '';

  protected string $bundle = '';

  /**
   * Enabled operations, a subset of OPERATIONS.
   *
   * @var string[]
   */
  protected array $operations = [];

  /**
   * Fields clients may set.
   *
   * @var string[]
   */
  protected array $fields = [];

  /**
   * The entity reference field to a Development, if the bundle has one.
   */
  protected string $development_field = '';

  /**
   * Whether creating a record needs a Development.
   */
  protected bool $require_development = FALSE;

  public static function idFor(string $entity_type, string $bundle): string {
    return $entity_type . '--' . $bundle;
  }

  public function getTargetEntityTypeId(): string {
    return $this->entity_type;
  }

  public function getTargetBundle(): string {
    return $this->bundle;
  }

  public function allows(string $operation): bool {
    return in_array($operation, $this->operations, TRUE);
  }

  /**
   * @return string[]
   */
  public function getOperations(): array {
    return array_values(array_intersect(static::OPERATIONS, $this->operations));
  }

  /**
   * @return string[]
   */
  public function getWritableFields(): array {
    return array_values($this->fields);
  }

  public function getDevelopmentField(): string {
    return $this->development_field;
  }

  public function requiresDevelopment(): bool {
    return $this->require_development && $this->development_field !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $entity_type = \Drupal::entityTypeManager()->getDefinition($this->entity_type, FALSE);
    if ($entity_type && ($bundle_type = $entity_type->getBundleEntityType())) {
      $bundle = \Drupal::entityTypeManager()->getStorage($bundle_type)->load($this->bundle);
      if ($bundle) {
        $this->addDependency('config', $bundle->getConfigDependencyName());
      }
    }
    elseif ($entity_type) {
      $this->addDependency('module', $entity_type->getProvider());
    }
    return $this;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_traceability\Form\TraceLinkTypeForm;
use Drupal\divengine_traceability\TraceLinkTypeListBuilder;

/**
 * A relationship type that a trace link can express.
 *
 * Source and target rules are lists of "entity_type" or
 * "entity_type:bundle" patterns; an empty list accepts any entity.
 */
#[ConfigEntityType(
  id: 'trace_link_type',
  label: new TranslatableMarkup('Trace link type'),
  label_collection: new TranslatableMarkup('Trace link types'),
  label_singular: new TranslatableMarkup('trace link type'),
  label_plural: new TranslatableMarkup('trace link types'),
  config_prefix: 'trace_link_type',
  admin_permission: 'administer trace link types',
  handlers: [
    'list_builder' => TraceLinkTypeListBuilder::class,
    'form' => [
      'add' => TraceLinkTypeForm::class,
      'edit' => TraceLinkTypeForm::class,
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'weight' => 'weight',
  ],
  config_export: [
    'id',
    'label',
    'inverse_label',
    'description',
    'source_rules',
    'target_rules',
    'same_kind',
    'weight',
  ],
  links: [
    'add-form' => '/admin/divengine/trace-link-types/add',
    'edit-form' => '/admin/divengine/trace-link-types/{trace_link_type}',
    'delete-form' => '/admin/divengine/trace-link-types/{trace_link_type}/delete',
    'collection' => '/admin/divengine/trace-link-types',
  ],
)]
class TraceLinkType extends ConfigEntityBase {

  protected $id;

  protected $label;

  /**
   * The relationship read from target to source, e.g. "is realized by".
   */
  protected string $inverse_label = '';

  protected string $description = '';

  /**
   * @var string[]
   */
  protected array $source_rules = [];

  /**
   * @var string[]
   */
  protected array $target_rules = [];

  /**
   * Whether both ends must be the same entity type and bundle.
   */
  protected bool $same_kind = FALSE;

  protected int $weight = 0;

  public function getInverseLabel(): string {
    return $this->inverse_label;
  }

  public function getDescription(): string {
    return $this->description;
  }

  public function getSourceRules(): array {
    return $this->source_rules;
  }

  public function getTargetRules(): array {
    return $this->target_rules;
  }

  public function requiresSameKind(): bool {
    return $this->same_kind;
  }

  /**
   * Whether an entity may be the source of a link of this type.
   */
  public function allowsSource(EntityInterface $entity): bool {
    return static::matchesRules($this->source_rules, $entity);
  }

  /**
   * Whether an entity may be the target of a link of this type.
   */
  public function allowsTarget(EntityInterface $entity): bool {
    return static::matchesRules($this->target_rules, $entity);
  }

  /**
   * Checks an entity against "entity_type" / "entity_type:bundle" patterns.
   */
  public static function matchesRules(array $rules, EntityInterface $entity): bool {
    if ($rules === []) {
      return TRUE;
    }
    return in_array($entity->getEntityTypeId(), $rules, TRUE)
      || in_array($entity->getEntityTypeId() . ':' . $entity->bundle(), $rules, TRUE);
  }

  /**
   * Allowed values callback for the trace_link.link_type field.
   */
  public static function allowedValues(): array {
    $types = \Drupal::entityTypeManager()->getStorage('trace_link_type')->loadMultiple();
    uasort($types, [static::class, 'sort']);
    return array_map(static fn (TraceLinkType $type): string => (string) $type->label(), $types);
  }

}

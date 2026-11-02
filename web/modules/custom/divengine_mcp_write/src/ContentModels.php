<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\divengine_mcp_write\Entity\McpContentCapability;
use Drupal\user\EntityOwnerInterface;

/**
 * Which content models and fields MCP content capabilities may cover (#86).
 *
 * Fails closed. An entity type is supported only if every guarantee the
 * tools rely on holds: it is a fieldable, revisionable content entity with a
 * revision log, an owner and a published flag, it is not moderated, and it
 * is not one of the types with their own rules (people, files, OAuth, Studio
 * records with dedicated workflows). A field is writable only if its type is
 * one the tools convert safely and it does not decide identity, ownership,
 * publication, moderation, revisions or the Development.
 */
class ContentModels {

  /**
   * Entity types never offered, whatever their interfaces.
   */
  const EXCLUDED_TYPES = [
    'user', 'file', 'consumer', 'oauth2_token', 'oauth2_scope',
    'path_alias', 'menu_link_content', 'shortcut', 'comment',
    'content_moderation_state', 'workspace',
    // Studio records with their own tools, workflows or governance.
    'artifact', 'trace_link', 'external_reference', 'development',
    'development_phase', 'iteration', 'milestone', 'role_assignment',
    'process_activity', 'change_request', 'baseline',
  ];

  /**
   * Field types the tools convert and validate.
   */
  const FIELD_TYPES = [
    'string', 'string_long', 'text', 'text_long', 'text_with_summary',
    'boolean', 'integer', 'decimal', 'float',
    'list_string', 'list_integer', 'list_float',
    'datetime', 'entity_reference',
  ];

  /**
   * Field names never writable, besides entity keys and revision fields.
   */
  const PROTECTED_FIELDS = [
    'created', 'changed', 'promote', 'sticky', 'path', 'moderation_state',
    'default_langcode', 'revision_translation_affected', 'content_translation_source',
    'content_translation_outdated', 'menu_link',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ?ModerationInformationInterface $moderation = NULL,
  ) {}

  /**
   * Why an entity type is not supported, or NULL when it is.
   */
  public function entityTypeProblem(string $entity_type_id): ?string {
    $type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$type instanceof ContentEntityTypeInterface) {
      return 'Only content entity types are supported.';
    }
    if (in_array($entity_type_id, static::EXCLUDED_TYPES, TRUE)) {
      return 'This entity type has its own rules and cannot be exposed generically.';
    }
    $class = $type->getClass();
    $checks = [
      'fieldable' => $type->entityClassImplements('\Drupal\Core\Entity\FieldableEntityInterface'),
      'a UUID' => $type->hasKey('uuid'),
      'revisions with a log' => $type->isRevisionable() && is_subclass_of($class, RevisionLogInterface::class),
      'an owner' => is_subclass_of($class, EntityOwnerInterface::class),
      'a published flag' => is_subclass_of($class, EntityPublishedInterface::class),
    ];
    $missing = array_keys(array_filter($checks, fn ($ok) => !$ok));
    return $missing ? 'The entity type lacks ' . implode(', ', $missing) . '.' : NULL;
  }

  /**
   * Why a bundle is not supported, or NULL when it is.
   */
  public function bundleProblem(string $entity_type_id, string $bundle): ?string {
    if ($problem = $this->entityTypeProblem($entity_type_id)) {
      return $problem;
    }
    if (!isset($this->bundleInfo->getBundleInfo($entity_type_id)[$bundle])) {
      return 'The bundle does not exist.';
    }
    if ($this->moderation && $this->moderation->shouldModerateEntitiesOfBundle($this->entityTypeManager->getDefinition($entity_type_id), $bundle)) {
      return 'Moderated bundles are not supported: their states are changed through workflow transitions.';
    }
    return NULL;
  }

  /**
   * The fields of a bundle that may be configured as writable, by name.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   */
  public function writableCandidates(string $entity_type_id, string $bundle): array {
    $type = $this->entityTypeManager->getDefinition($entity_type_id);
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    return array_filter($definitions, fn (FieldDefinitionInterface $definition) => $this->fieldProblem($type, $definition) === NULL);
  }

  /**
   * Why a field cannot be writable, or NULL when it can.
   */
  public function fieldProblem(ContentEntityTypeInterface $type, FieldDefinitionInterface $definition): ?string {
    $name = $definition->getName();
    $keys = array_filter([
      $type->getKey('id'), $type->getKey('uuid'), $type->getKey('revision'), $type->getKey('bundle'),
      $type->getKey('owner'), $type->getKey('uid'), $type->getKey('published'), $type->getKey('status'),
      $type->getKey('langcode'), $type->getKey('default_langcode'),
    ]);
    $revision_fields = array_values($type->getRevisionMetadataKeys());
    if (in_array($name, $keys, TRUE) || in_array($name, $revision_fields, TRUE) || in_array($name, static::PROTECTED_FIELDS, TRUE)) {
      return 'This field decides identity, ownership, publication or revisions.';
    }
    if ($definition->isComputed() || $definition->isReadOnly()) {
      return 'The field is computed or read-only.';
    }
    if (!in_array($definition->getType(), static::FIELD_TYPES, TRUE)) {
      return sprintf('Fields of type %s are not supported.', $definition->getType());
    }
    if ($definition->getType() === 'entity_reference') {
      $target = (string) $definition->getSetting('target_type');
      if ($target === 'development') {
        return 'The Development is set with the development argument, not as a field.';
      }
      if ($this->entityTypeProblemForReference($target)) {
        return sprintf('References to %s are not supported.', $target);
      }
    }
    return NULL;
  }

  /**
   * Fields that hold a Development, candidates for the Development field.
   *
   * @return string[]
   *   Field names.
   */
  public function developmentFields(string $entity_type_id, string $bundle): array {
    $out = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $name => $definition) {
      if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'development' && !$definition->getFieldStorageDefinition()->isMultiple()) {
        $out[] = $name;
      }
    }
    return $out;
  }

  /**
   * Everything wrong with a capability, as messages; empty when it is sound.
   *
   * Checked when it is saved and again at every call: a field or bundle that
   * changed since is refused, not used.
   *
   * @return string[]
   */
  public function capabilityProblems(McpContentCapability $capability): array {
    $entity_type_id = $capability->getTargetEntityTypeId();
    $bundle = $capability->getTargetBundle();
    if ($problem = $this->bundleProblem($entity_type_id, $bundle)) {
      return [$problem];
    }
    $problems = [];
    if ($capability->id() !== McpContentCapability::idFor($entity_type_id, $bundle)) {
      $problems[] = 'The ID must be the entity type and bundle joined by two hyphens.';
    }
    $type = $this->entityTypeManager->getDefinition($entity_type_id);
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    foreach ($capability->getWritableFields() as $name) {
      if (!isset($definitions[$name])) {
        $problems[] = sprintf('Field %s does not exist.', $name);
      }
      elseif ($problem = $this->fieldProblem($type, $definitions[$name])) {
        $problems[] = sprintf('Field %s cannot be writable: %s', $name, $problem);
      }
    }
    $development = $capability->getDevelopmentField();
    if ($development !== '' && !in_array($development, $this->developmentFields($entity_type_id, $bundle), TRUE)) {
      $problems[] = sprintf('Field %s is not a single reference to a Development.', $development);
    }
    if ($capability->requiresDevelopment() && $development === '') {
      $problems[] = 'Requiring a Development needs a Development field.';
    }
    if (array_intersect(['create', 'update', 'delete'], $capability->getOperations()) && !$capability->allows('read')) {
      $problems[] = 'Writes need read: clients check what they wrote.';
    }
    return $problems;
  }

  protected function entityTypeProblemForReference(string $target): bool {
    $definition = $this->entityTypeManager->getDefinition($target, FALSE);
    return !$definition || in_array($target, ['user', 'file', 'consumer', 'oauth2_token', 'oauth2_scope', 'oauth2_token_type'], TRUE);
  }

}

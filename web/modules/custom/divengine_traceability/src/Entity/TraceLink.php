<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

#[ContentEntityType(
  id: 'trace_link',
  label: new TranslatableMarkup('Trace Link'),
  label_collection: new TranslatableMarkup('Trace Links'),
  label_singular: new TranslatableMarkup('trace link'),
  label_plural: new TranslatableMarkup('trace links'),
  handlers: [
    'storage' => 'Drupal\divengine_traceability\TraceLinkStorage',
    'list_builder' => 'Drupal\divengine_traceability\TraceLinkListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => 'Drupal\divengine_traceability\TraceLinkAccessControlHandler',
    'form' => [
      'default' => 'Drupal\divengine_traceability\Form\TraceLinkForm',
      'add' => 'Drupal\divengine_traceability\Form\TraceLinkForm',
      'edit' => 'Drupal\divengine_traceability\Form\TraceLinkForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'trace_link',
  revision_table: 'trace_link_revision',
  show_revision_ui: true,
  data_table: 'trace_link_field_data',
  revision_data_table: 'trace_link_field_revision',
  translatable: false,
  admin_permission: 'administer trace links',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'id',
    'published' => 'status',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  constraints: [
    'ValidTraceLink' => [],
    'TraceEndpointAccess' => [],
  ],
  common_reference_target: true,
  links: [
    'canonical' => '/trace-link/{trace_link}',
    'add-form' => '/trace-link/add',
    'edit-form' => '/trace-link/{trace_link}/edit',
    'delete-form' => '/trace-link/{trace_link}/delete',
    'version-history' => '/trace-link/{trace_link}/revisions',
    'revision' => '/trace-link/{trace_link}/revisions/{trace_link_revision}/view',
    'revision-revert-form' => '/trace-link/{trace_link}/revisions/{trace_link_revision}/revert',
    'revision-delete-form' => '/trace-link/{trace_link}/revisions/{trace_link_revision}/delete',
    'collection' => '/traces',
  ],
)]
class TraceLink extends ContentEntityBase implements TraceLinkInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    if (!$this->access('view')) {
      return (string) new TranslatableMarkup('Restricted trace');
    }
    $source = $this->getSourceEntity();
    $target = $this->getTargetEntity();
    $type = $this->getLinkType();
    if ($source && $target && $type) {
      return sprintf('%s %s %s', static::endLabel($source), mb_strtolower((string) $type->label()), static::endLabel($target));
    }
    return (string) parent::label();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->endsChanged()) {
      $this->assertIntegrity();
    }
    if (!$this->isNew()) {
      if (!$this->isNewRevision()) {
        $this->setRevisionUserId((int) \Drupal::currentUser()->id());
        $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      }
      $this->setNewRevision(TRUE);
    }
    $reviewer = $this->get('reviewed_by')->target_id;
    $previous = $this->getOriginal() ? $this->getOriginal()->get('reviewed_by')->target_id : NULL;
    if ($reviewer && $reviewer !== $previous) {
      $this->set('reviewed_at', \Drupal::time()->getRequestTime());
    }
    elseif (!$reviewer) {
      $this->set('reviewed_at', NULL);
    }
  }

  /**
   * Whether this save creates the link or changes its ends or type.
   */
  public function endsChanged(): bool {
    $original = $this->isNew() ? NULL : $this->getOriginal();
    if (!$original) {
      return TRUE;
    }
    foreach (['link_type', 'source_entity_type', 'source_entity_id', 'target_entity_type', 'target_entity_id'] as $field) {
      if ((string) $original->get($field)->value !== (string) $this->get($field)->value) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Persistent integrity, whatever the writer: both ends exist and differ,
   * the type accepts them, and no other link records the same source, type
   * and target. Concurrent writers of the same triple are serialized until
   * the first one's transaction ends, so the second sees it.
   *
   * Whether the writer may reference the ends is authorization, checked by
   * the TraceEndpointAccess constraint when the link is validated.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function assertIntegrity(): void {
    $database = \Drupal::database();
    $lock_backend = \Drupal::lock();
    $lock = 'divengine_trace_link:' . hash('sha256', implode('|', [$this->get('link_type')->value, $this->getSourceEntityType(), $this->getSourceEntityId(), $this->getTargetEntityType(), $this->getTargetEntityId()]));
    $deadline = time() + 30;
    while (!$lock_backend->acquire($lock)) {
      if (time() >= $deadline) {
        throw new EntityStorageException('Another request is writing the same trace link. Try again.');
      }
      $lock_backend->wait($lock, 5);
    }
    if ($database->inTransaction()) {
      $database->transactionManager()->addPostTransactionCallback(fn () => $lock_backend->release($lock));
    }
    $errors = \Drupal::service('divengine_traceability.trace_link_validator')->validateAll(
      $this->getSourceEntityType(),
      $this->getSourceEntityId(),
      $this->getTargetEntityType(),
      $this->getTargetEntityId(),
      (string) $this->get('link_type')->value,
      $this->isNew() ? NULL : (int) $this->id(),
    );
    if (!$database->inTransaction()) {
      $lock_backend->release($lock);
    }
    if ($errors) {
      throw new EntityStorageException('The trace link cannot be stored: ' . implode(' ', $errors));
    }
  }

  /**
   * Short label of a link end: the artifact key when there is one.
   */
  protected static function endLabel(EntityInterface $entity): string {
    if ($entity->hasField('artifact_key') && $entity->get('artifact_key')->value) {
      return $entity->get('artifact_key')->value;
    }
    return (string) $entity->label();
  }

  public function getLinkType(): ?TraceLinkType {
    $id = $this->get('link_type')->value;
    return $id ? TraceLinkType::load($id) : NULL;
  }

  public function getSourceEntity(): ?EntityInterface {
    return static::loadEnd($this->getSourceEntityType(), $this->getSourceEntityId());
  }

  public function getTargetEntity(): ?EntityInterface {
    return static::loadEnd($this->getTargetEntityType(), $this->getTargetEntityId());
  }

  protected static function loadEnd(string $entity_type_id, int $entity_id): ?EntityInterface {
    $manager = \Drupal::entityTypeManager();
    if ($entity_type_id === '' || $entity_id <= 0 || !$manager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    return $manager->getStorage($entity_type_id)->load($entity_id);
  }

  public function getSourceEntityType(): string {
    return $this->get('source_entity_type')->value ?? '';
  }

  public function getSourceEntityId(): int {
    return (int) ($this->get('source_entity_id')->value ?? 0);
  }

  public function getTargetEntityType(): string {
    return $this->get('target_entity_type')->value ?? '';
  }

  public function getTargetEntityId(): int {
    return (int) ($this->get('target_entity_id')->value ?? 0);
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Trace Link ID'))
      ->setDescription(t('The trace link ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The trace link UUID.'))
      ->setReadOnly(TRUE);

    $fields['revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The revision ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['source_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source entity type'))
      ->setDescription(t('The entity type ID of the source.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['source_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Source entity ID'))
      ->setDescription(t('The entity ID of the source.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['target_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target entity type'))
      ->setDescription(t('The entity type ID of the target.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['target_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Target entity ID'))
      ->setDescription(t('The entity ID of the target.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['link_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Link type'))
      ->setDescription(t('The semantic type of this trace link.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values_function', TraceLinkType::class . '::allowedValues')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['rationale'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Rationale'))
      ->setDescription(t('Why this trace link exists.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['confidence'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Confidence'))
      ->setDescription(t('Confidence level of this trace link.'))
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'confirmed' => 'Confirmed',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
        'inferred' => 'Inferred',
      ])
      ->setDefaultValue('medium')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDescription(t('Whether the trace link is published.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['reviewed_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Reviewed by'))
      ->setDescription(t('The user who reviewed this trace link.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['reviewed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Reviewed at'))
      ->setDescription(t('When this trace link was reviewed.'))
      ->setRevisionable(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Created by'))
      ->setDescription(t('The user who created the trace link.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the trace link was created.'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the trace link was last edited.'))
      ->setRevisionable(TRUE);

    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    return $fields;
  }

}

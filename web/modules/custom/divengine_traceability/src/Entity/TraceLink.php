<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
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
    'list_builder' => 'Drupal\divengine_traceability\TraceLinkListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => 'Drupal\divengine_traceability\TraceLinkAccessControlHandler',
    'form' => [
      'default' => 'Drupal\divengine_traceability\Form\TraceLinkForm',
      'add' => 'Drupal\divengine_traceability\Form\TraceLinkForm',
      'edit' => 'Drupal\divengine_traceability\Form\TraceLinkForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
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
  field_ui_base_route: 'entity.trace_link.settings',
  constraints: [
    'ValidTraceLink' => [],
  ],
  common_reference_target: true,
  links: [
    'canonical' => '/trace-link/{trace_link}',
    'add-form' => '/trace-link/add',
    'edit-form' => '/trace-link/{trace_link}/edit',
    'delete-form' => '/trace-link/{trace_link}/delete',
    'version-history' => '/trace-link/{trace_link}/revisions',
    'revision' => '/trace-link/{trace_link}/revisions/{trace_link_revision}/view',
    'collection' => '/admin/divengine/trace-links',
  ],
)]
class TraceLink extends ContentEntityBase implements TraceLinkInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;

  public function label(): string {
    $source_key = $this->resolveArtifactKey($this->getSourceEntityType(), $this->getSourceEntityId());
    $target_key = $this->resolveArtifactKey($this->getTargetEntityType(), $this->getTargetEntityId());
    $link_type = $this->get('link_type')->value ?? '';

    if ($source_key && $target_key && $link_type) {
      return sprintf('%s %s %s', $source_key, $link_type, $target_key);
    }
    return parent::label();
  }

  protected function resolveArtifactKey(string $entity_type, int $entity_id): string {
    if ($entity_type === 'artifact' && $entity_id > 0) {
      $artifact = \Drupal::entityTypeManager()->getStorage('artifact')->load($entity_id);
      if ($artifact) {
        return $artifact->getArtifactKey() ?: $artifact->getTitle();
      }
    }
    return '';
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
      ->setSetting('allowed_values', [
        'derives_from' => 'Derives from',
        'satisfies' => 'Satisfies',
        'realizes' => 'Realizes',
        'validates' => 'Validates',
        'tests' => 'Tests',
        'depends_on' => 'Depends on',
        'conflicts_with' => 'Conflicts with',
        'mitigates' => 'Mitigates',
        'impacts' => 'Impacts',
        'documents' => 'Documents',
        'supersedes' => 'Supersedes',
        'references' => 'References',
      ])
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
      ->setDefaultValue(TRUE);

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

    $fields['revision_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Revision author'))
      ->setDescription(t('The user ID of the revision author.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user');

    $fields['revision_timestamp'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Revision timestamp'))
      ->setDescription(t('The time the revision was created.'))
      ->setRevisionable(TRUE);

    $fields['revision_log'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Revision log message'))
      ->setDescription(t('A log message describing the revision.'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}

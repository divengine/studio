<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
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
  id: 'artifact',
  label: new TranslatableMarkup('Artifact'),
  label_collection: new TranslatableMarkup('Artifacts'),
  label_singular: new TranslatableMarkup('artifact'),
  label_plural: new TranslatableMarkup('artifacts'),
  handlers: [
    'list_builder' => 'Drupal\divengine_artifact\ArtifactListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => 'Drupal\divengine_artifact\ArtifactAccessControlHandler',
    'form' => [
      'default' => 'Drupal\divengine_artifact\Form\ArtifactForm',
      'add' => 'Drupal\divengine_artifact\Form\ArtifactForm',
      'edit' => 'Drupal\divengine_artifact\Form\ArtifactForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'artifact',
  revision_table: 'artifact_revision',
  show_revision_ui: true,
  data_table: 'artifact_field_data',
  revision_data_table: 'artifact_field_revision',
  translatable: false,
  admin_permission: 'administer divengine',
  permission_granularity: 'bundle',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'bundle' => 'type',
    'label' => 'title',
    'published' => 'status',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  bundle_entity_type: 'artifact_type',
  field_ui_base_route: 'entity.artifact_type.edit_form',
  constraints: [
    'UniqueArtifactKey' => [],
  ],
  common_reference_target: true,
  links: [
    'canonical' => '/artifact/{artifact}',
    'add-page' => '/artifact/add',
    'add-form' => '/artifact/add/{artifact_type}',
    'edit-form' => '/artifact/{artifact}/edit',
    'delete-form' => '/artifact/{artifact}/delete',
    'version-history' => '/artifact/{artifact}/revisions',
    'revision' => '/artifact/{artifact}/revisions/{artifact_revision}/view',
    'revision-revert-form' => '/artifact/{artifact}/revisions/{artifact_revision}/revert',
    'revision-delete-form' => '/artifact/{artifact}/revisions/{artifact_revision}/delete',
    'collection' => '/artifacts',
  ],
)]
class Artifact extends ContentEntityBase implements ArtifactInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    // Keys are identifiers: surrounding whitespace never makes them distinct.
    $this->set('artifact_key', trim($this->getArtifactKey()));
    if ($this->getArtifactKey() === '' && ($type = $this->get('type')->entity) && $type->getKeyPrefix() !== '') {
      $this->set('artifact_key', \Drupal::service('divengine_core.sequence')->next($type->getKeyPrefix()));
    }
  }

  public function getTitle(): string {
    return $this->get('title')->value ?? '';
  }

  public function setTitle(string $title): static {
    $this->set('title', $title);
    return $this;
  }

  public function getArtifactKey(): string {
    return $this->get('artifact_key')->value ?? '';
  }

  public function setArtifactKey(string $key): static {
    $this->set('artifact_key', $key);
    return $this;
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Artifact ID'))
      ->setDescription(t('The artifact ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The artifact UUID.'))
      ->setReadOnly(TRUE);

    $fields['revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The revision ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Artifact type'))
      ->setDescription(t('The artifact type bundle.'))
      ->setSetting('target_type', 'artifact_type')
      ->setReadOnly(TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The artifact title.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['artifact_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Artifact key'))
      ->setDescription(t('A unique key for the artifact (e.g., REQ-0001). Types with a key prefix assign it on save.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Summary'))
      ->setDescription(t('A brief summary of the artifact.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['body'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Body'))
      ->setDescription(t('The full description or body content.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDescription(t('Whether the artifact is published.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setDescription(t('The artifact owner.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the artifact was created.'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the artifact was last edited.'))
      ->setRevisionable(TRUE);

    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    return $fields;
  }

}

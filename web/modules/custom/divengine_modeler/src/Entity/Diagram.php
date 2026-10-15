<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_modeler\DiagramAccessControlHandler;
use Drupal\divengine_modeler\DiagramListBuilder;
use Drupal\divengine_modeler\DiagramViewBuilder;
use Drupal\divengine_modeler\Form\DiagramForm;
use Drupal\divengine_modeler\ModelerNotationInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

#[ContentEntityType(
  id: 'diagram',
  label: new TranslatableMarkup('Diagram'),
  label_collection: new TranslatableMarkup('Diagrams'),
  label_singular: new TranslatableMarkup('diagram'),
  label_plural: new TranslatableMarkup('diagrams'),
  handlers: [
    'list_builder' => DiagramListBuilder::class,
    'view_builder' => DiagramViewBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => DiagramAccessControlHandler::class,
    'form' => [
      'default' => DiagramForm::class,
      'add' => DiagramForm::class,
      'edit' => DiagramForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'diagram',
  revision_table: 'diagram_revision',
  show_revision_ui: true,
  translatable: false,
  admin_permission: 'administer diagrams',
  permission_granularity: 'bundle',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'bundle' => 'type',
    'label' => 'label',
    'published' => 'status',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  bundle_entity_type: 'diagram_type',
  field_ui_base_route: 'entity.diagram_type.edit_form',
  constraints: [
    'ValidDiagramModel' => [],
  ],
  common_reference_target: true,
  links: [
    'canonical' => '/diagram/{diagram}',
    'add-page' => '/diagram/add',
    'add-form' => '/diagram/add/{diagram_type}',
    'edit-form' => '/diagram/{diagram}/edit',
    'delete-form' => '/diagram/{diagram}/delete',
    'version-history' => '/diagram/{diagram}/revisions',
    'revision' => '/diagram/{diagram}/revisions/{diagram_revision}/view',
    'revision-revert-form' => '/diagram/{diagram}/revisions/{diagram_revision}/revert',
    'revision-delete-form' => '/diagram/{diagram}/revisions/{diagram_revision}/delete',
    'collection' => '/admin/divengine/diagrams',
  ],
)]
class Diagram extends ContentEntityBase implements DiagramInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $notation = $this->getNotation();
    if (trim($this->getSource()) === '') {
      $this->setSource($notation->defaultSource());
    }
    $this->set('search_text', $notation->extractSearchText($this->getSource()));
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    // The index describes the working copy: the latest revision. The loaded
    // revision ID is only updated after postSave(), so compare directly.
    if ((int) $storage->getLatestRevisionId($this->id()) === (int) $this->getRevisionId()) {
      \Drupal::service('divengine_modeler.link_index')->rebuild($this);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $index = \Drupal::service('divengine_modeler.link_index');
    foreach ($entities as $entity) {
      $index->deleteDiagram((int) $entity->id());
    }
  }

  public function getNotation(): ModelerNotationInterface {
    $type = DiagramType::load($this->bundle());
    if (!$type instanceof DiagramType) {
      throw new \LogicException(sprintf('Diagram type "%s" does not exist.', $this->bundle()));
    }
    return $type->getNotation();
  }

  public function getSource(): string {
    return (string) ($this->get('source')->value ?? '');
  }

  public function setSource(string $source): static {
    $this->set('source', $source);
    return $this;
  }

  public function getBindings(): array {
    $value = $this->get('element_bindings')->getValue();
    return $value[0] ?? [];
  }

  public function setBindings(array $bindings): static {
    ksort($bindings);
    $this->set('element_bindings', $bindings === [] ? [] : [$bindings]);
    return $this;
  }

  public function getPreviewSvg(): string {
    return (string) ($this->get('preview_svg')->value ?? '');
  }

  public function getPreviewHash(): string {
    return (string) ($this->get('preview_hash')->value ?? '');
  }

  public function setPreview(string $svg): static {
    $this->set('preview_svg', $svg);
    $this->set('preview_hash', $svg === '' ? '' : static::hashSource($this->getSource()));
    return $this;
  }

  public function hasFreshPreview(): bool {
    return $this->getPreviewSvg() !== '' && hash_equals(static::hashSource($this->getSource()), $this->getPreviewHash());
  }

  public static function hashSource(string $source): string {
    return hash('sha256', $source);
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('What the diagram shows and why it exists.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -8,
        'settings' => ['rows' => 3],
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'label' => 'hidden',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['source'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Source'))
      ->setDescription(t('The canonical document of the diagram (XML, JSON or text, depending on the notation). Leave empty to start from the notation template; edit it visually in the modeler.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 10,
        'settings' => ['rows' => 8],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['element_bindings'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Element bindings'))
      ->setDescription(t('Links of text-notation elements to entities, keyed by semantic key.'))
      ->setRevisionable(TRUE);

    $fields['preview_svg'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Preview'))
      ->setDescription(t('SVG rendered by the notation engine from the source.'))
      ->setRevisionable(TRUE);

    $fields['preview_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Preview source hash'))
      ->setDescription(t('Hash of the source the preview was rendered from.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['search_text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Searchable text'))
      ->setDescription(t('Element names and documentation extracted from the source.'))
      ->setRevisionable(TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setRevisionable(TRUE);

    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    return $fields;
  }

}

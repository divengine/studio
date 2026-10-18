<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_codedocs\Access\CodeDocsAccessControlHandler;
use Drupal\divengine_codedocs\CodeDocSetViewBuilder;
use Drupal\divengine_codedocs\Form\CodeDocSetForm;
use Drupal\divengine_process\Entity\DevelopmentScopedTrait;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * The documentation of one codebase of a Development: a backend, a
 * frontend, a library. Extractors address it by its identifier.
 *
 * Optionally linked to a Code Source repository, so symbols open the code
 * they document.
 */
#[ContentEntityType(
  id: 'codedoc_set',
  label: new TranslatableMarkup('Documentation set'),
  label_collection: new TranslatableMarkup('Code docs'),
  label_singular: new TranslatableMarkup('documentation set'),
  label_plural: new TranslatableMarkup('documentation sets'),
  handlers: [
    'view_builder' => CodeDocSetViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => CodeDocsAccessControlHandler::class,
    'form' => [
      'default' => CodeDocSetForm::class,
      'add' => CodeDocSetForm::class,
      'edit' => CodeDocSetForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'codedoc_set',
  admin_permission: 'administer code docs',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'CodeDocSetIdentity' => [],
  ],
  links: [
    'canonical' => '/docs/{codedoc_set}',
    'add-form' => '/docs/add',
    'edit-form' => '/docs/{codedoc_set}/edit',
    'delete-form' => '/docs/{codedoc_set}/delete',
  ],
)]
class CodeDocSet extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use DevelopmentScopedTrait;
  use EntityChangedTrait;
  use EntityOwnerTrait;

  public function label() {
    return '[' . $this->get('code')->value . '] ' . $this->get('name')->value;
  }

  public function getIdentifier(): string {
    return (string) $this->get('identifier')->value;
  }

  /**
   * The newest ready snapshot.
   */
  public function getLatestSnapshot(): ?CodeDocSnapshot {
    if ($this->isNew()) {
      return NULL;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('codedoc_snapshot');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('doc_set', $this->id())->condition('state', 'ready')->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 1)->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  public function hasHistory(bool $ready_only = FALSE): bool {
    if ($this->isNew()) {
      return FALSE;
    }
    $query = \Drupal::entityTypeManager()->getStorage('codedoc_snapshot')->getQuery()->accessCheck(FALSE)->condition('doc_set', $this->id())->range(0, 1);
    if ($ready_only) {
      $query->condition('state', 'ready');
    }
    return (bool) $query->execute();
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew() && trim((string) $this->get('code')->value) === '') {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next('DOC'));
    }
    $this->set('identifier', trim((string) $this->get('identifier')->value));
    $this->set('source_root', trim(trim((string) $this->get('source_root')->value), '/'));
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $snapshots = \Drupal::entityTypeManager()->getStorage('codedoc_snapshot');
    $ids = $snapshots->getQuery()->accessCheck(FALSE)->condition('doc_set', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $snapshots->delete($snapshots->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Registered by'));
    $fields['development'] = static::developmentFieldDefinition()
      ->setDescription(new TranslatableMarkup('The Development whose code this documents. Only its members see it.'));
    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setSetting('max_length', 32)
      ->addConstraint('UniqueField');
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);
    $fields['identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Identifier'))
      ->setDescription(new TranslatableMarkup('How extractors name this codebase when they publish ("project"), e.g. billing-backend. Letters, digits, dot, dash and underscore.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE);
    $fields['language'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Language'))
      ->setDescription(new TranslatableMarkup('Java, TypeScript, PHP… Taken from the first snapshot when empty.'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE);
    $fields['repository'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Repository'))
      ->setDescription(new TranslatableMarkup('The Code Source repository of this code, so each symbol links to its source.'))
      ->setSetting('target_type', 'source_repository')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -7])
      ->setDisplayConfigurable('form', TRUE);
    $fields['source_root'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Folder in the repository'))
      ->setDescription(new TranslatableMarkup('Where the files the extractor reports live inside the repository, e.g. backend/src/main/java. Empty: the repository root.'))
      ->setSetting('max_length', 512)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -6])
      ->setDisplayConfigurable('form', TRUE);
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => -5, 'settings' => ['rows' => 3]])
      ->setDisplayConfigurable('form', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Registered on'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

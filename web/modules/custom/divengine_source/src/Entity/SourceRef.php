<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\DevelopmentScopedTrait;
use Drupal\divengine_source\Access\SourceAccessControlHandler;
use Drupal\divengine_source\Form\SourceEntityForm;

/**
 * A Git ref Studio indexes: a branch, a tag or a remote-tracking ref that
 * already exists in the local clone. A repository has as many as needed.
 */
#[ContentEntityType(
  id: 'source_ref',
  label: new TranslatableMarkup('Ref'),
  label_collection: new TranslatableMarkup('Refs'),
  label_singular: new TranslatableMarkup('ref'),
  label_plural: new TranslatableMarkup('refs'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => SourceAccessControlHandler::class,
    'form' => [
      'default' => SourceEntityForm::class,
      'add' => SourceEntityForm::class,
      'edit' => SourceEntityForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'source_ref',
  admin_permission: 'administer source code',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'status' => 'status',
  ],
  constraints: [
    'SourceRefRules' => [],
  ],
  links: [
    'add-form' => '/source/ref/add',
    'edit-form' => '/source/ref/{source_ref}/edit',
    'delete-form' => '/source/ref/{source_ref}/delete',
  ],
)]
class SourceRef extends ContentEntityBase {

  use DevelopmentScopedTrait;

  public static function kinds(): array {
    return [
      'branch' => new TranslatableMarkup('Branch'),
      'tag' => new TranslatableMarkup('Tag'),
      'other' => new TranslatableMarkup('Other'),
    ];
  }

  public function label() {
    return (string) ($this->get('label')->value ?: $this->get('name')->value);
  }

  public function getRepository(): ?SourceRepository {
    return $this->get('repository')->entity;
  }

  public function getLastSnapshot(): ?SourceSnapshot {
    return $this->get('last_snapshot')->entity;
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('development', $this->getRepository()?->get('development')->target_id);
    $this->set('name', trim((string) $this->get('name')->value));
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service('divengine_source.index')->forgetRefs(array_keys($entities));
    $snapshots = \Drupal::entityTypeManager()->getStorage('source_snapshot');
    $ids = $snapshots->getQuery()->accessCheck(FALSE)->condition('ref', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $snapshots->delete($snapshots->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Development'))
      ->setSetting('target_type', 'development');
    $fields['repository'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Repository'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'source_repository');
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Git ref'))
      ->setDescription(new TranslatableMarkup('A ref the local clone resolves, e.g. main, develop, origin/develop, v1.2. Studio never fetches it.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Optional name shown instead of the ref.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE);
    $fields['kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setRequired(TRUE)
      ->setDefaultValue('branch')
      ->setSetting('allowed_values', array_map('strval', static::kinds()))
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE);
    $fields['is_default'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Default ref of the repository'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => -7, 'settings' => ['display_label' => TRUE]])
      ->setDisplayConfigurable('form', TRUE);
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => -6, 'settings' => ['display_label' => TRUE]])
      ->setDisplayConfigurable('form', TRUE);
    $fields['last_snapshot'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Last snapshot'))
      ->setSetting('target_type', 'source_snapshot');
    $fields['last_indexed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last indexed'));
    return $fields;
  }

}

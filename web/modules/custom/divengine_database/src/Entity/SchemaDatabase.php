<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_database\Access\DatabaseAccessControlHandler;
use Drupal\divengine_database\DatabaseViewBuilder;
use Drupal\divengine_database\Form\DatabaseForm;
use Drupal\divengine_process\Entity\DevelopmentScopedTrait;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A logical database of a Development, stable across its snapshots.
 *
 * Analyzers address it by its identifier (the "database.code" of the
 * contract). Once it has snapshots, the identifier and the engine are its
 * identity and do not change.
 */
#[ContentEntityType(
  id: 'schema_database',
  label: new TranslatableMarkup('Database'),
  label_collection: new TranslatableMarkup('Databases'),
  label_singular: new TranslatableMarkup('database'),
  label_plural: new TranslatableMarkup('databases'),
  handlers: [
    'view_builder' => DatabaseViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => DatabaseAccessControlHandler::class,
    'form' => [
      'default' => DatabaseForm::class,
      'add' => DatabaseForm::class,
      'edit' => DatabaseForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'schema_database',
  admin_permission: 'administer database schemas',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'SchemaDatabaseIdentity' => [],
  ],
  links: [
    'canonical' => '/database/{schema_database}',
    'add-form' => '/database/add',
    'edit-form' => '/database/{schema_database}/edit',
    'delete-form' => '/database/{schema_database}/delete',
  ],
)]
class SchemaDatabase extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

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
  public function getLatestSnapshot(): ?SchemaSnapshot {
    if ($this->isNew()) {
      return NULL;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('schema_snapshot');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('database', $this->id())->condition('state', 'ready')->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 1)->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Whether any snapshot was imported into this database.
   */
  public function hasHistory(bool $ready_only = FALSE): bool {
    if ($this->isNew()) {
      return FALSE;
    }
    $query = \Drupal::entityTypeManager()->getStorage('schema_snapshot')->getQuery()->accessCheck(FALSE)->condition('database', $this->id())->range(0, 1);
    if ($ready_only) {
      $query->condition('state', 'ready');
    }
    return (bool) $query->execute();
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew() && trim((string) $this->get('code')->value) === '') {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next('DB'));
    }
    $this->set('identifier', trim((string) $this->get('identifier')->value));
    $this->set('engine', mb_strtolower(trim((string) $this->get('engine')->value)));
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $snapshots = \Drupal::entityTypeManager()->getStorage('schema_snapshot');
    $ids = $snapshots->getQuery()->accessCheck(FALSE)->condition('database', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $snapshots->delete($snapshots->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Registered by'));
    $fields['development'] = static::developmentFieldDefinition()
      ->setDescription(new TranslatableMarkup('The Development whose database this is. Only its members see it.'));
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
      ->setDescription(new TranslatableMarkup('How analyzers name this database in their snapshots ("database.code" of the contract), e.g. billing_prod. Letters, digits, dot, dash and underscore.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE);
    $fields['engine'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Engine'))
      ->setDescription(new TranslatableMarkup('MySQL, PostgreSQL, SQL Server… Taken from the first snapshot when empty.'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE);
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => -7, 'settings' => ['rows' => 3]])
      ->setDisplayConfigurable('form', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Registered on'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

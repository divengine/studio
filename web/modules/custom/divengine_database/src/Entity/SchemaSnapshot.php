<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_database\Access\DatabaseAccessControlHandler;
use Drupal\divengine_database\SnapshotViewBuilder;

/**
 * A point-in-time capture of the schema of a database, written by the
 * importer only.
 *
 * Its identity is the snapshot key (<identifier>@<revision>, or the content
 * hash when no revision was given). Ready snapshots never change: a new
 * capture is a new snapshot.
 */
#[ContentEntityType(
  id: 'schema_snapshot',
  label: new TranslatableMarkup('Snapshot'),
  label_collection: new TranslatableMarkup('Snapshots'),
  label_singular: new TranslatableMarkup('snapshot'),
  label_plural: new TranslatableMarkup('snapshots'),
  handlers: [
    'view_builder' => SnapshotViewBuilder::class,
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => DatabaseAccessControlHandler::class,
    'form' => [
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'schema_snapshot',
  admin_permission: 'administer database schemas',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'snapshot_key',
    'owner' => 'uid',
  ],
  links: [
    'canonical' => '/database/snapshot/{schema_snapshot}',
    'delete-form' => '/database/snapshot/{schema_snapshot}/delete',
  ],
)]
class SchemaSnapshot extends ContentEntityBase {

  const COUNTERS = [
    'table_count' => 'Tables',
    'view_count' => 'Views',
    'column_count' => 'Columns',
    'index_count' => 'Indexes',
    'real_fk_count' => 'Foreign keys',
    'implied_fk_count' => 'Implied relationships',
    'routine_count' => 'Routines',
    'finding_count' => 'Findings',
    'finding_warning_count' => 'Warnings',
    'finding_error_count' => 'Errors',
  ];

  public static function states(): array {
    return [
      'draft' => new TranslatableMarkup('Importing'),
      'ready' => new TranslatableMarkup('Ready'),
      'failed' => new TranslatableMarkup('Failed'),
    ];
  }

  public function isReady(): bool {
    return $this->get('state')->value === 'ready';
  }

  public function getDatabase(): ?SchemaDatabase {
    return $this->get('database')->entity;
  }

  public function count(string $counter): int {
    return (int) $this->get($counter)->value;
  }

  /**
   * The ready snapshot of the same database captured just before this one.
   */
  public function getPrevious(): ?SchemaSnapshot {
    $storage = \Drupal::entityTypeManager()->getStorage('schema_snapshot');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('database', $this->get('database')->target_id)
      ->condition('state', 'ready')
      ->condition('id', $this->id(), '<>');
    $captured = (int) $this->get('captured_at')->value;
    $query->condition($query->orConditionGroup()
      ->condition('captured_at', $captured, '<')
      ->condition($query->andConditionGroup()->condition('captured_at', $captured)->condition('id', $this->id(), '<')));
    $ids = $query->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 1)->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service('divengine_database.facts')->forget(array_keys($entities));
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $reference = fn (string $label, string $type) => BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup($label))->setSetting('target_type', $type);
    $string = fn (string $label, int $length = 255) => BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup($label))->setSetting('max_length', $length);
    $integer = fn (string $label) => BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup($label))->setDefaultValue(0);
    $fields['uid'] = $reference('Imported by', 'user');
    $fields['development'] = $reference('Development', 'development');
    $fields['database'] = $reference('Database', 'schema_database');
    $fields['snapshot_key'] = $string('Snapshot key', 255)->addConstraint('UniqueField');
    $fields['captured_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Captured on'));
    $fields['source'] = $string('Analyzer', 64);
    $fields['source_version'] = $string('Analyzer version', 64);
    $fields['source_revision'] = $string('Revision', 128);
    $fields['catalog'] = $string('Catalog');
    $fields['contract_version'] = $string('Contract version', 16);
    $fields['content_hash'] = $string('Content hash', 64);
    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setDefaultValue('draft')
      ->setSetting('allowed_values', array_map('strval', static::states()));
    $fields['import_error'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Error'));
    foreach (static::COUNTERS as $name => $label) {
      $fields[$name] = $integer($label);
    }
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Imported on'));
    return $fields;
  }

}

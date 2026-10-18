<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_codedocs\Access\CodeDocsAccessControlHandler;

/**
 * The documentation of a codebase at a commit, written by the importer.
 *
 * It is uploaded in chunks (state "uploading", read by nobody) and becomes
 * "ready" when it is finalized; ready snapshots never change.
 */
#[ContentEntityType(
  id: 'codedoc_snapshot',
  label: new TranslatableMarkup('Documentation snapshot'),
  label_collection: new TranslatableMarkup('Documentation snapshots'),
  label_singular: new TranslatableMarkup('documentation snapshot'),
  label_plural: new TranslatableMarkup('documentation snapshots'),
  handlers: [
    'view_builder' => 'Drupal\divengine_codedocs\CodeDocSetViewBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => CodeDocsAccessControlHandler::class,
    'form' => [
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'codedoc_snapshot',
  admin_permission: 'administer code docs',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'snapshot_key',
  ],
  links: [
    'canonical' => '/docs/snapshot/{codedoc_snapshot}',
    'delete-form' => '/docs/snapshot/{codedoc_snapshot}/delete',
  ],
)]
class CodeDocSnapshot extends ContentEntityBase {

  const COUNTERS = [
    'unit_count' => 'Units',
    'symbol_count' => 'Symbols',
    'member_count' => 'Members',
    'ref_count' => 'References',
    'endpoint_count' => 'Endpoints',
    'documentable_count' => 'Documentable',
    'documented_count' => 'Documented',
  ];

  public static function states(): array {
    return [
      'uploading' => new TranslatableMarkup('Uploading'),
      'ready' => new TranslatableMarkup('Ready'),
    ];
  }

  public function isReady(): bool {
    return $this->get('state')->value === 'ready';
  }

  public function getDocSet(): ?CodeDocSet {
    return $this->get('doc_set')->entity;
  }

  public function count(string $counter): int {
    return (int) $this->get($counter)->value;
  }

  /**
   * Share of documentable symbols that carry documentation, 0–100.
   */
  public function coverage(): ?float {
    $documentable = $this->count('documentable_count');
    return $documentable ? round(100 * $this->count('documented_count') / $documentable, 1) : NULL;
  }

  /**
   * Symbols by kind, from the finalization.
   */
  public function kinds(): array {
    return json_decode((string) $this->get('kinds')->value, TRUE) ?: [];
  }

  /**
   * The ready snapshot of the same set captured just before this one.
   */
  public function getPrevious(): ?CodeDocSnapshot {
    $storage = \Drupal::entityTypeManager()->getStorage('codedoc_snapshot');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('doc_set', $this->get('doc_set')->target_id)
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
    \Drupal::service('divengine_codedocs.index')->forget(array_keys($entities));
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $reference = fn (string $label, string $type) => BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup($label))->setSetting('target_type', $type);
    $string = fn (string $label, int $length = 255) => BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup($label))->setSetting('max_length', $length);
    $fields['uid'] = $reference('Published by', 'user');
    $fields['development'] = $reference('Development', 'development');
    $fields['doc_set'] = $reference('Documentation set', 'codedoc_set');
    $fields['snapshot_key'] = $string('Snapshot key')->addConstraint('UniqueField');
    $fields['format'] = $string('Format', 32);
    $fields['producer'] = $string('Extractor', 128);
    $fields['language'] = $string('Language', 64);
    $fields['commit_sha'] = $string('Commit', 64);
    $fields['branch'] = $string('Branch', 255);
    $fields['source_hash'] = $string('Source hash', 64);
    $fields['content_hash'] = $string('Content hash', 64);
    $fields['captured_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Captured on'));
    $fields['header'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Header'))
      ->setDescription(new TranslatableMarkup('What the extractor said about the whole document, for the chunks.'));
    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setDefaultValue('uploading')
      ->setSetting('allowed_values', array_map('strval', static::states()));
    foreach (static::COUNTERS as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup($label))->setDefaultValue(0);
    }
    $fields['kinds'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Symbols by kind'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Started on'));
    return $fields;
  }

}

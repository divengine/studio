<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_source\Access\SourceAccessControlHandler;

/**
 * An observation of a ref at a commit, written by the indexer only.
 *
 * Ready snapshots never change: the commit and its counts are history.
 */
#[ContentEntityType(
  id: 'source_snapshot',
  label: new TranslatableMarkup('Snapshot'),
  label_collection: new TranslatableMarkup('Snapshots'),
  label_singular: new TranslatableMarkup('snapshot'),
  label_plural: new TranslatableMarkup('snapshots'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => SourceAccessControlHandler::class,
  ],
  base_table: 'source_snapshot',
  admin_permission: 'administer source code',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class SourceSnapshot extends ContentEntityBase {

  public static function states(): array {
    return [
      'draft' => new TranslatableMarkup('Indexing'),
      'ready' => new TranslatableMarkup('Ready'),
      'failed' => new TranslatableMarkup('Failed'),
    ];
  }

  public function label() {
    return substr((string) $this->get('commit_sha')->value, 0, 12);
  }

  public function getCommit(): string {
    return (string) $this->get('commit_sha')->value;
  }

  public function isReady(): bool {
    return $this->get('state')->value === 'ready';
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $reference = fn (string $label, string $type) => BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup($label))->setSetting('target_type', $type);
    $integer = fn (string $label) => BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup($label))->setDefaultValue(0);
    $fields['development'] = $reference('Development', 'development');
    $fields['repository'] = $reference('Repository', 'source_repository');
    $fields['ref'] = $reference('Ref', 'source_ref');
    $fields['commit_sha'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Commit'))->setSetting('max_length', 64);
    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('State'))
      ->setDefaultValue('draft')
      ->setSetting('allowed_values', array_map('strval', static::states()));
    $fields['error'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Error'));
    $fields['file_count'] = $integer('Files in the tree');
    $fields['added_count'] = $integer('Added');
    $fields['changed_count'] = $integer('Changed');
    $fields['reactivated_count'] = $integer('Back');
    $fields['missing_count'] = $integer('Gone');
    $fields['skipped_count'] = $integer('Skipped (name not UTF-8)');
    $fields['indexed'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Indexed on'));
    return $fields;
  }

}

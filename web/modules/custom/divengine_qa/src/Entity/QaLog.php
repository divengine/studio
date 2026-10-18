<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_qa\Access\QaLogAccessControlHandler;

/**
 * One entry of the activity of a QA record: what happened, who, when.
 *
 * The equivalent of Odoo's chatter notes: a run reassigned or reopened, a
 * case skipped, a scenario discovered, a QA Issue reported. Entries are
 * written by the workflow and never edited.
 */
#[ContentEntityType(
  id: 'qa_log',
  label: new TranslatableMarkup('QA activity'),
  label_collection: new TranslatableMarkup('QA activity'),
  label_singular: new TranslatableMarkup('QA activity entry'),
  label_plural: new TranslatableMarkup('QA activity entries'),
  handlers: [
    'access' => QaLogAccessControlHandler::class,
  ],
  base_table: 'qa_log',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class QaLog extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['target_type'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Record type'))->setSetting('max_length', 64)->setRequired(TRUE);
    $fields['target_id'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Record'))->setRequired(TRUE);
    $fields['message'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Message'));
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('By'))->setSetting('target_type', 'user')
      ->setDefaultValueCallback(QaResultCorrection::class . '::currentUser');
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('On'));
    return $fields;
  }

}

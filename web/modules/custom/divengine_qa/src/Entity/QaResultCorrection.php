<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_qa\Access\QaAccessControlHandler;

/**
 * An immutable record of a changed result: who, when, from what, to what,
 * and why.
 *
 * Written only by the result workflow; never edited or deleted, not even by
 * administrators. Its rows keep their execution, step and run from being
 * deleted.
 */
#[ContentEntityType(
  id: 'qa_result_correction',
  label: new TranslatableMarkup('Result correction'),
  label_collection: new TranslatableMarkup('Result corrections'),
  label_singular: new TranslatableMarkup('result correction'),
  label_plural: new TranslatableMarkup('result corrections'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => QaAccessControlHandler::class,
  ],
  base_table: 'qa_result_correction',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class QaResultCorrection extends ContentEntityBase {

  use QaScopedTrait;

  public function label() {
    return (string) new TranslatableMarkup('Correction of @label', ['@label' => $this->get('step_label')->value ?: $this->get('execution')->entity?->label()]);
  }

  public function preSave(EntityStorageInterface $storage): void {
    if (!$this->isNew()) {
      throw new \LogicException('The result correction history cannot be modified.');
    }
    if ($this->get('old_state')->value === $this->get('new_state')->value) {
      throw new \LogicException('A correction must change the recorded result.');
    }
    if (trim((string) $this->get('reason')->value) === '') {
      throw new \LogicException('A correction requires a reason.');
    }
    $level = $this->get('level')->value;
    $step = $this->get('step_result')->entity;
    if ($level === 'step' && (!$step || (string) $step->get('execution')->target_id !== (string) $this->get('execution')->target_id)) {
      throw new \LogicException('A step correction must reference a step of its execution.');
    }
    if ($level === 'execution' && $step) {
      throw new \LogicException('An execution correction cannot reference a step.');
    }
    parent::preSave($storage);
    $execution = $this->get('execution')->entity;
    $this->inheritDevelopment($execution);
    $this->set('run', $execution?->get('run')->target_id);
  }

  public function delete(): void {
    throw new \LogicException('The result correction history is an immutable audit trail and cannot be deleted.');
  }

  public function createDuplicate() {
    throw new \LogicException('The result correction history cannot be duplicated.');
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    throw new \LogicException('The result correction history is an immutable audit trail and cannot be deleted.');
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::derivedDevelopmentField();
    $fields['run'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Run'))->setSetting('target_type', 'qa_test_run');
    $fields['execution'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Execution'))->setRequired(TRUE)->setSetting('target_type', 'qa_test_execution');
    $fields['step_result'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Step Result'))->setSetting('target_type', 'qa_step_result');
    $fields['level'] = QaFields::list(new TranslatableMarkup('Level'), 'execution', 0);
    $fields['step_label'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Step'))->setSetting('max_length', 255);
    $fields['old_state'] = QaFields::list(new TranslatableMarkup('Previous Result'), 'pending', 0);
    $fields['new_state'] = QaFields::list(new TranslatableMarkup('New Result'), 'pending', 0);
    $fields['reason'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Reason'))->setRequired(TRUE);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Corrected By'))->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::currentUser');
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Corrected On'));
    return $fields;
  }

  public static function currentUser(): array {
    return [['target_id' => \Drupal::currentUser()->id()]];
  }

}

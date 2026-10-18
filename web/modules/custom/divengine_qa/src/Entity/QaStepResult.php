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
use Drupal\divengine_qa\QaDeletion;

/**
 * The result of one step in an execution, with a frozen copy of the step.
 */
#[ContentEntityType(
  id: 'qa_step_result',
  label: new TranslatableMarkup('Step result'),
  label_collection: new TranslatableMarkup('Step results'),
  label_singular: new TranslatableMarkup('step result'),
  label_plural: new TranslatableMarkup('step results'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => QaAccessControlHandler::class,
  ],
  base_table: 'qa_step_result',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  constraints: [
    'QaResultRules' => [],
  ],
)]
class QaStepResult extends ContentEntityBase {

  use QaResultTrait;
  use QaScopedTrait;
  use QaTrustedWriteTrait;

  public static function snapshotFields(): array {
    return ['execution', 'test_step', 'weight', 'action', 'expected_result', 'snapshot_skippable'];
  }

  /**
   * 1-based position among the execution's steps.
   */
  public function position(): int {
    $execution = $this->get('execution')->entity;
    $position = 1;
    foreach ($execution ? $execution->getStepResults() : [] as $step) {
      if ((string) $step->id() === (string) $this->id()) {
        return $position;
      }
      $position++;
    }
    return (int) $this->get('weight')->value;
  }

  public function label() {
    return (string) new TranslatableMarkup('Step @n: @action', ['@n' => $this->position(), '@action' => $this->get('action')->value]);
  }

  public function isOptional(): bool {
    return (bool) $this->get('snapshot_skippable')->value;
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $execution = $this->get('execution')->entity;
    $this->inheritDevelopment($execution);
    $this->set('run', $execution?->get('run')->target_id);
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    QaDeletion::assertDeletable($entities);
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::derivedDevelopmentField();
    $fields['run'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Run'))->setSetting('target_type', 'qa_test_run');
    $fields['execution'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Test Execution'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'qa_test_execution');
    $fields['test_step'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Step'))->setSetting('target_type', 'qa_test_step');
    $fields['weight'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Sequence'))->setDefaultValue(10);
    $fields['action'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Action'))->setSetting('max_length', 255);
    $fields['expected_result'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Expected Result'));
    $fields['snapshot_skippable'] = BaseFieldDefinition::create('boolean')->setLabel(new TranslatableMarkup('Optional Step'))->setDefaultValue(FALSE);
    $fields += static::resultFieldDefinitions();
    return $fields;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessFields;

/**
 * Result fields shared by executions and step results.
 */
trait QaResultTrait {

  /**
   * Fields that may only change while the run is In Progress.
   */
  public static function runtimeFields(): array {
    return ['state', 'actual_result', 'comments', 'result_reason', 'correction_reason'];
  }

  /**
   * Fields maintained by the result workflow only.
   */
  public static function systemFields(): array {
    return ['executed_by', 'execution_date'];
  }

  public function getResult(): string {
    return (string) $this->get('state')->value;
  }

  /**
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   */
  protected static function resultFieldDefinitions(): array {
    $fields['state'] = QaFields::list(new TranslatableMarkup('Result'), 'pending', -10);
    $fields['actual_result'] = ProcessFields::text(new TranslatableMarkup('Actual Result'), -8)
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['comments'] = ProcessFields::text(new TranslatableMarkup('Comments'), -7)
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['result_reason'] = ProcessFields::text(new TranslatableMarkup('Reason'), -6)
      ->setDescription(new TranslatableMarkup('Required when the result is Blocked or Skipped: explain what prevented execution or why it was intentionally not executed.'))
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['correction_reason'] = ProcessFields::text(new TranslatableMarkup('Last Correction Reason'), -5)
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['executed_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Recorded By'))
      ->setSetting('target_type', 'user');
    $fields['execution_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Recorded On'));
    $fields['evidence'] = QaFields::files(new TranslatableMarkup('Evidence'), 20);
    return $fields;
  }

}

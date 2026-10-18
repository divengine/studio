<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessFields;
use Drupal\divengine_qa\Access\QaAccessControlHandler;
use Drupal\divengine_qa\Form\QaEntityForm;

/**
 * A step of a test case: an action and its expected result.
 *
 * An Optional step may be skipped with a justification without keeping the
 * execution from passing; a mandatory one never.
 */
#[ContentEntityType(
  id: 'qa_test_step',
  label: new TranslatableMarkup('Test step'),
  label_collection: new TranslatableMarkup('Test steps'),
  label_singular: new TranslatableMarkup('test step'),
  label_plural: new TranslatableMarkup('test steps'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => QaAccessControlHandler::class,
    'form' => [
      'default' => QaEntityForm::class,
      'add' => QaEntityForm::class,
      'edit' => QaEntityForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'qa_test_step',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  constraints: [
    'QaStepCase' => [],
  ],
  links: [
    'canonical' => '/qa/step/{qa_test_step}',
    'add-form' => '/qa/step/add',
    'edit-form' => '/qa/step/{qa_test_step}/edit',
    'delete-form' => '/qa/step/{qa_test_step}/delete',
  ],
)]
class QaTestStep extends ContentEntityBase {

  use QaScopedTrait;

  public function label() {
    return (string) $this->get('name')->value;
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $case = $this->get('case')->entity;
    $this->inheritDevelopment($case);
    $this->set('plan', $case?->get('plan')->target_id);
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::derivedDevelopmentField();
    $fields['case'] = ProcessFields::reference(new TranslatableMarkup('Test Case'), 'qa_test_case', -20, TRUE);
    $fields['plan'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Test Plan'))
      ->setSetting('target_type', 'qa_test_plan');
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Action'), -19, TRUE);
    $fields['weight'] = ProcessFields::weight();
    $fields['expected_result'] = ProcessFields::text(new TranslatableMarkup('Expected Result'), -15);
    $fields['description'] = ProcessFields::text(new TranslatableMarkup('Description'), -10);
    $fields['is_skippable'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Optional Step'))
      ->setDescription(new TranslatableMarkup('Approved by the test designer: a Tester may skip this step with a justification without preventing the execution from passing. Skipping a mandatory step never produces a Passed execution.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE], 'weight' => -12])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'boolean', 'weight' => -12])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['attachments'] = QaFields::files(new TranslatableMarkup('Attachments'), -5);
    return $fields;
  }

}

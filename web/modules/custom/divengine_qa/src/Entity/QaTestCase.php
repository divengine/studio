<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessFields;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Lifecycle\StatefulTrait;
use Drupal\divengine_process\Lifecycle\Transition;
use Drupal\divengine_qa\Access\QaAccessControlHandler;
use Drupal\divengine_qa\QaDeletion;
use Drupal\divengine_qa\Form\QaEntityForm;
use Drupal\divengine_qa\QaVocabulary;
use Drupal\divengine_qa\QaViewBuilder;

/**
 * A test case: what to check, its steps and its expected outcome.
 *
 * Only Ready cases are copied into new runs. A case promoted from an ad-hoc
 * scenario points back to it.
 */
#[ContentEntityType(
  id: 'qa_test_case',
  label: new TranslatableMarkup('Test case'),
  label_collection: new TranslatableMarkup('Test cases'),
  label_singular: new TranslatableMarkup('test case'),
  label_plural: new TranslatableMarkup('test cases'),
  handlers: [
    'view_builder' => QaViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
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
  base_table: 'qa_test_case',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'QaPromotedCase' => [],
  ],
  links: [
    'canonical' => '/qa/case/{qa_test_case}',
    'add-form' => '/qa/case/add',
    'edit-form' => '/qa/case/{qa_test_case}/edit',
    'delete-form' => '/qa/case/{qa_test_case}/delete',
  ],
)]
class QaTestCase extends ContentEntityBase implements StatefulInterface {

  use EntityChangedTrait;
  use QaScopedTrait;
  use StatefulTrait;

  public static function transitions(): array {
    return [
      'ready' => new Transition('ready', new TranslatableMarkup('Mark Ready'), ['draft'], 'ready'),
      'obsolete' => new Transition('obsolete', new TranslatableMarkup('Mark Obsolete'), ['draft', 'ready'], 'obsolete'),
      'reset' => new Transition('reset', new TranslatableMarkup('Reset to Draft'), ['ready', 'obsolete'], 'draft'),
    ];
  }

  public static function stateLabels(): array {
    return QaVocabulary::caseStates();
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $suite = $this->get('suite')->entity;
    $this->inheritDevelopment($suite);
    $this->set('plan', $suite?->get('plan')->target_id);
    if ($this->isNew()) {
      $this->assignCode('TC');
    }
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    QaDeletion::assertDeletable($entities);
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $steps = \Drupal::entityTypeManager()->getStorage('qa_test_step');
    $ids = $steps->getQuery()->accessCheck(FALSE)->condition('case', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $steps->delete($steps->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::derivedDevelopmentField();
    $fields['suite'] = ProcessFields::reference(new TranslatableMarkup('Test Suite'), 'qa_test_suite', -20, TRUE);
    $fields['plan'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Test Plan'))
      ->setSetting('target_type', 'qa_test_plan');
    $fields['code'] = QaFields::code()
      ->setDescription(new TranslatableMarkup('Leave empty to assign the next TC- reference.'))
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -18])
      ->setDisplayConfigurable('form', TRUE);
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Test Case'), -19, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['weight'] = ProcessFields::weight();
    $fields['description'] = ProcessFields::text(new TranslatableMarkup('Description / Objective'), -10);
    $fields['preconditions'] = ProcessFields::text(new TranslatableMarkup('Preconditions'), -9);
    $fields['test_data'] = ProcessFields::text(new TranslatableMarkup('Test Data'), -8);
    $fields['acceptance_criteria'] = ProcessFields::text(new TranslatableMarkup('Acceptance Criteria'), -7);
    $fields['postconditions'] = ProcessFields::text(new TranslatableMarkup('Postconditions'), -6);
    $fields['priority'] = ProcessFields::reference(new TranslatableMarkup('Priority'), 'qa_priority', -15);
    $fields['state'] = QaFields::list(new TranslatableMarkup('Status'), 'draft', -16);
    $fields['tags'] = QaFields::tags(-5);
    $fields['source_execution'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Promoted From'))
      ->setSetting('target_type', 'qa_test_execution')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => -4])
      ->setDisplayConfigurable('view', TRUE);
    $fields['last_result'] = QaFields::list(new TranslatableMarkup('Last Result'), 'no_exec', -3);
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

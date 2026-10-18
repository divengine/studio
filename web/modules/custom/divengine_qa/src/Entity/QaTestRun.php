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
use Drupal\divengine_qa\Access\QaAccessControlHandler;
use Drupal\divengine_qa\QaDeletion;
use Drupal\divengine_qa\Form\QaRunForm;
use Drupal\divengine_qa\QaViewBuilder;
use Drupal\divengine_qa\QaVocabulary;
use Drupal\divengine_qa\TestUrl;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A test run: one Tester executes a frozen snapshot of a plan.
 *
 * Creating it copies the plan's Ready cases and their steps into
 * executions and step results (QaSnapshot). Its lifecycle moves through the
 * QA workflow services: it starts by hand, completes by itself when the
 * last result is recorded, and once Completed or Cancelled it is historical
 * evidence.
 */
#[ContentEntityType(
  id: 'qa_test_run',
  label: new TranslatableMarkup('Test run'),
  label_collection: new TranslatableMarkup('Test runs'),
  label_singular: new TranslatableMarkup('test run'),
  label_plural: new TranslatableMarkup('test runs'),
  handlers: [
    'view_builder' => QaViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => QaAccessControlHandler::class,
    'form' => [
      'default' => QaRunForm::class,
      'add' => QaRunForm::class,
      'edit' => QaRunForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'qa_test_run',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'QaRunRules' => [],
  ],
  links: [
    'canonical' => '/qa/run/{qa_test_run}',
    'add-form' => '/qa/run/add',
    'edit-form' => '/qa/run/{qa_test_run}/edit',
    'delete-form' => '/qa/run/{qa_test_run}/delete',
  ],
)]
class QaTestRun extends ContentEntityBase implements StatefulInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use QaScopedTrait;
  use QaTrustedWriteTrait;
  use StatefulTrait;

  /**
   * Transitions go through QaRunWorkflow, which checks who may apply them.
   */
  public static function transitions(): array {
    return [];
  }

  public static function stateLabels(): array {
    return QaVocabulary::runStates();
  }

  public function isHistorical(): bool {
    return in_array($this->getState(), ['completed', 'cancelled'], TRUE);
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $plan = $this->get('plan')->entity;
    $this->inheritDevelopment($plan);
    // Copied once from the environment when not given; an explicitly empty
    // URL stays empty and later changes of the default never propagate.
    if ($this->isNew() && $this->get('test_url')->value === NULL && $plan) {
      $this->set('test_url', $plan->get('environment')->entity?->getDefaultTestUrl() ?? '');
    }
    $this->set('test_url', TestUrl::normalize($this->get('test_url')->value));
    if ($this->isNew()) {
      $this->assignCode('RUN');
    }
  }

  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    if (!$update) {
      \Drupal::service('divengine_qa.snapshot')->build($this);
    }
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    QaDeletion::assertDeletable($entities);
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $executions = \Drupal::entityTypeManager()->getStorage('qa_test_execution');
    $ids = $executions->getQuery()->accessCheck(FALSE)->condition('run', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $executions->delete($executions->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Created by'));
    $fields['development'] = static::derivedDevelopmentField();
    $fields['plan'] = ProcessFields::reference(new TranslatableMarkup('Test Plan'), 'qa_test_plan', -20, TRUE);
    $fields['code'] = QaFields::code();
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Run Name'), -19, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['tester'] = ProcessFields::user(new TranslatableMarkup('Tester'), -18);
    $fields['is_self_service'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Self-created'))
      ->setDefaultValue(FALSE);
    $fields['is_auto_completed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Automatically Completed'))
      ->setDefaultValue(FALSE);
    $fields['test_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Test URL'))
      ->setSetting('max_length', 2048)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -15])
      ->setDisplayConfigurable('form', TRUE);
    $fields['is_automated'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Automated Run'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE], 'weight' => -14])
      ->setDisplayConfigurable('form', TRUE);
    $fields['state'] = QaFields::list(new TranslatableMarkup('Status'), 'draft', -16);
    $fields['start_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Start Date'))
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);
    $fields['end_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('End Date'))
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE);
    $fields['notes'] = ProcessFields::text(new TranslatableMarkup('Notes'), -5);
    $fields['tags'] = QaFields::tags(-4);
    $fields['last_reopened_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Last Reopened By'))
      ->setSetting('target_type', 'user');
    $fields['last_reopened_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Last Reopened At'));
    $fields['last_reopen_reason'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Last Reopen Reason'));
    $fields['reopen_count'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Reopen Count'))->setDefaultValue(0);
    foreach ([
      'execution_count' => 'Executions',
      'pass_count' => 'Passed',
      'fail_count' => 'Failed',
      'blocked_count' => 'Blocked',
      'skipped_count' => 'Skipped',
      'pending_count' => 'Pending',
    ] as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup($label))->setDefaultValue(0);
    }
    $fields['pass_rate'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Pass Rate'))
      ->setDescription(new TranslatableMarkup('Quality of the results recorded so far: Passed / (Passed + Failed). Blocked, Skipped and Pending executions are not counted, so an In Progress Run can show 100% before every execution has a result; check Execution Progress for how much of the Run is done.'))
      ->setDefaultValue(0);
    $fields['execution_progress'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Execution Progress'))
      ->setDescription(new TranslatableMarkup('How much of the Run is done: percentage of executions with a final result (Passed, Failed, Blocked or Skipped) over the total.'))
      ->setDefaultValue(0);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

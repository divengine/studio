<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_qa\Access\QaAccessControlHandler;
use Drupal\divengine_qa\QaDeletion;
use Drupal\divengine_qa\QaViewBuilder;
use Drupal\divengine_qa\QaVocabulary;

/**
 * The execution of one test case (or ad-hoc scenario) in a run.
 *
 * Its instructions are a frozen copy taken when the run was created. With
 * steps, its result is derived from the step results; without, it is
 * recorded directly. Executions are only created by the snapshot and by
 * Add Scenario.
 */
#[ContentEntityType(
  id: 'qa_test_execution',
  label: new TranslatableMarkup('Test execution'),
  label_collection: new TranslatableMarkup('Test executions'),
  label_singular: new TranslatableMarkup('test execution'),
  label_plural: new TranslatableMarkup('test executions'),
  handlers: [
    'view_builder' => QaViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => QaAccessControlHandler::class,
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'qa_test_execution',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  constraints: [
    'QaResultRules' => [],
  ],
  links: [
    'canonical' => '/qa/execution/{qa_test_execution}',
  ],
)]
class QaTestExecution extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  use QaResultTrait;
  use QaScopedTrait;
  use QaTrustedWriteTrait;

  /**
   * Fields frozen when the run is created.
   */
  public static function snapshotFields(): array {
    return [
      'run', 'test_case', 'weight', 'suite', 'suite_name', 'suite_weight', 'case_weight',
      'snapshot_name', 'snapshot_code', 'snapshot_description', 'snapshot_preconditions',
      'snapshot_test_data', 'snapshot_acceptance_criteria', 'snapshot_postconditions',
      'origin', 'discovered_by', 'discovered_on',
    ];
  }

  public function label() {
    return (string) $this->get('name')->value;
  }

  public function isAdHoc(): bool {
    return $this->get('origin')->value === 'ad_hoc';
  }

  public function isCaseSkipped(): bool {
    return (bool) $this->get('is_case_skipped')->value;
  }

  /**
   * The step results, in order.
   *
   * @return \Drupal\divengine_qa\Entity\QaStepResult[]
   */
  public function getStepResults(): array {
    if ($this->isNew()) {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('qa_step_result');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('execution', $this->id())->sort('weight')->sort('id')->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Whether the result comes from the steps (has steps, not case-skipped).
   */
  public function isDerived(): bool {
    return !$this->isCaseSkipped() && (bool) $this->getStepResults();
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $run = $this->get('run')->entity;
    $this->inheritDevelopment($run);
    $this->set('plan', $run?->get('plan')->target_id);
    $this->set('tester', $run?->get('tester')->target_id);
    $code = (string) ($this->get('snapshot_code')->value ?: $this->get('snapshot_name')->value ?: 'Test Case');
    $this->set('name', $run ? (($run->get('code')->value ?: $run->label()) . ' - ' . $code) : '');
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    QaDeletion::assertDeletable($entities);
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $results = \Drupal::entityTypeManager()->getStorage('qa_step_result');
    $ids = $results->getQuery()->accessCheck(FALSE)->condition('execution', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $results->delete($results->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::derivedDevelopmentField();
    $fields['name'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Execution'))->setSetting('max_length', 255);
    $fields['run'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Test Run'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'qa_test_run');
    $fields['plan'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Plan'))->setSetting('target_type', 'qa_test_plan');
    $fields['tester'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Tester'))->setSetting('target_type', 'user');
    $fields['weight'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Order'))->setDefaultValue(10);
    $fields['suite'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Suite'))->setSetting('target_type', 'qa_test_suite');
    $fields['suite_name'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Test Suite Name'))->setSetting('max_length', 255);
    $fields['suite_weight'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Suite Order'))->setDefaultValue(0);
    $fields['case_weight'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Case Order'))->setDefaultValue(0);
    $fields['is_case_skipped'] = BaseFieldDefinition::create('boolean')->setLabel(new TranslatableMarkup('Test Case Skipped'))->setDefaultValue(FALSE);
    $fields['origin'] = QaFields::list(new TranslatableMarkup('Origin'), 'planned', 0);
    $fields['discovered_by'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Discovered By'))->setSetting('target_type', 'user');
    $fields['discovered_on'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Discovered On'));
    $fields['test_case'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Test Case'))->setSetting('target_type', 'qa_test_case');
    $fields['snapshot_name'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Snapshot Name'))->setSetting('max_length', 255);
    $fields['snapshot_code'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Snapshot Code'))->setSetting('max_length', 64);
    foreach ([
      'snapshot_description' => 'Description / Objective',
      'snapshot_preconditions' => 'Preconditions',
      'snapshot_test_data' => 'Test Data',
      'snapshot_acceptance_criteria' => 'Acceptance Criteria',
      'snapshot_postconditions' => 'Postconditions',
    ] as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup($label));
    }
    $fields += static::resultFieldDefinitions();
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

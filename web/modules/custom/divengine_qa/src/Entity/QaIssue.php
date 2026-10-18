<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessFields;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Lifecycle\StatefulTrait;
use Drupal\divengine_process\Lifecycle\Transition;
use Drupal\divengine_qa\Access\QaAccessControlHandler;
use Drupal\divengine_qa\Form\QaEntityForm;
use Drupal\divengine_qa\QaViewBuilder;
use Drupal\divengine_qa\QaVocabulary;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A defect found by testing, reported from a test execution.
 *
 * It belongs to the Development of its executions: the one it was created
 * from (its origin) and any other execution later linked to it. The test
 * case, run and plan of the origin are kept for filtering.
 */
#[ContentEntityType(
  id: 'qa_issue',
  label: new TranslatableMarkup('QA Issue'),
  label_collection: new TranslatableMarkup('QA Issues'),
  label_singular: new TranslatableMarkup('QA issue'),
  label_plural: new TranslatableMarkup('QA issues'),
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
  base_table: 'qa_issue',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'QaIssueDevelopment' => [],
  ],
  links: [
    'canonical' => '/qa/issue/{qa_issue}',
    'edit-form' => '/qa/issue/{qa_issue}/edit',
    'delete-form' => '/qa/issue/{qa_issue}/delete',
  ],
)]
class QaIssue extends ContentEntityBase implements StatefulInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use QaScopedTrait;
  use StatefulTrait;

  /**
   * States of an issue still to be dealt with.
   */
  const OPEN_STATES = ['open', 'in_progress'];

  public static function transitions(): array {
    return [
      'start' => new Transition('start', new TranslatableMarkup('Start'), ['open'], 'in_progress'),
      'resolve' => new Transition('resolve', new TranslatableMarkup('Resolve'), ['open', 'in_progress'], 'resolved'),
      'cancel' => new Transition('cancel', new TranslatableMarkup('Cancel'), ['open', 'in_progress'], 'cancelled'),
      'reopen' => new Transition('reopen', new TranslatableMarkup('Reopen'), ['resolved', 'cancelled'], 'open'),
    ];
  }

  public static function stateLabels(): array {
    return QaVocabulary::issueStates();
  }

  public function isOpen(): bool {
    return in_array($this->getState(), static::OPEN_STATES, TRUE);
  }

  /**
   * The executions linked to the issue.
   *
   * @return \Drupal\divengine_qa\Entity\QaTestExecution[]
   */
  public function getExecutions(): array {
    return array_filter(array_map(fn ($item) => $item->entity, iterator_to_array($this->get('executions'))));
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $origin = $this->get('origin')->entity;
    // The origin is always one of the linked executions.
    if ($origin && !in_array((string) $origin->id(), array_map('strval', array_column($this->get('executions')->getValue(), 'target_id')), TRUE)) {
      $this->get('executions')->appendItem($origin->id());
    }
    if ($origin) {
      $this->set('test_case', $origin->get('test_case')->target_id);
      $this->set('run', $origin->get('run')->target_id);
      $this->set('plan', $origin->get('plan')->target_id);
    }
    if ($this->get('development')->isEmpty()) {
      $executions = $this->getExecutions();
      $this->inheritDevelopment($origin ?: (reset($executions) ?: NULL));
    }
    if ($this->isNew()) {
      $this->assignCode('QI');
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Reported by'));
    $fields['development'] = static::derivedDevelopmentField()->setRequired(TRUE);
    $fields['code'] = QaFields::code();
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('QA Issue Title'), -20, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => -10, 'settings' => ['rows' => 14]])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['state'] = QaFields::list(new TranslatableMarkup('Status'), 'open', -15);
    $fields['priority'] = QaFields::list(new TranslatableMarkup('Priority'), '1', -14, TRUE);
    $fields['assignee'] = ProcessFields::user(new TranslatableMarkup('Assigned to'), -13);
    $fields['origin'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('QA Execution Origin'))
      ->setDescription(new TranslatableMarkup('The test execution the issue was reported from.'))
      ->setSetting('target_type', 'qa_test_execution');
    $fields['executions'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Linked QA Executions'))
      ->setSetting('target_type', 'qa_test_execution')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $fields['test_case'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Related Test Case'))
      ->setSetting('target_type', 'qa_test_case');
    $fields['run'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Related Test Run'))
      ->setSetting('target_type', 'qa_test_run');
    $fields['plan'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Related Test Plan'))
      ->setSetting('target_type', 'qa_test_plan');
    $fields['evidence'] = QaFields::files(new TranslatableMarkup('Attachments'), 5);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Reported on'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

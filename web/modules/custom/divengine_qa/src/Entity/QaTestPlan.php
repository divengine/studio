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
use Drupal\divengine_qa\Form\QaEntityForm;
use Drupal\divengine_qa\QaVocabulary;
use Drupal\divengine_qa\QaViewBuilder;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A test plan of a Development: suites of cases, executed by test runs.
 *
 * Only Ready cases are copied into new runs; self-service runs need an
 * Active plan.
 */
#[ContentEntityType(
  id: 'qa_test_plan',
  label: new TranslatableMarkup('Test plan'),
  label_collection: new TranslatableMarkup('Test plans'),
  label_singular: new TranslatableMarkup('test plan'),
  label_plural: new TranslatableMarkup('test plans'),
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
  base_table: 'qa_test_plan',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'ProcessDevelopmentEditable' => [],
  ],
  links: [
    'canonical' => '/qa/plan/{qa_test_plan}',
    'add-form' => '/qa/plan/add',
    'edit-form' => '/qa/plan/{qa_test_plan}/edit',
    'delete-form' => '/qa/plan/{qa_test_plan}/delete',
  ],
)]
class QaTestPlan extends ContentEntityBase implements StatefulInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use QaScopedTrait;
  use StatefulTrait;

  public static function transitions(): array {
    return [
      'activate' => new Transition('activate', new TranslatableMarkup('Activate'), ['draft'], 'active'),
      'close' => new Transition('close', new TranslatableMarkup('Close'), ['active'], 'closed'),
      'reset' => new Transition('reset', new TranslatableMarkup('Reset to Draft'), ['active', 'closed'], 'draft'),
    ];
  }

  public static function stateLabels(): array {
    return QaVocabulary::planStates();
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew()) {
      $this->assignCode('TP');
    }
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    // A plan takes its runs and suites (and so its cases and steps) with it.
    foreach (['qa_test_run' => 'plan', 'qa_test_suite' => 'plan'] as $entity_type_id => $field) {
      $children = \Drupal::entityTypeManager()->getStorage($entity_type_id);
      $ids = $children->getQuery()->accessCheck(FALSE)->condition($field, array_keys($entities), 'IN')->execute();
      if ($ids) {
        $children->delete($children->loadMultiple($ids));
      }
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['development'] = static::developmentFieldDefinition()
      ->setDescription(new TranslatableMarkup('The Development this plan belongs to.'));
    $fields['code'] = QaFields::code();
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Test Plan'), -20, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['description'] = ProcessFields::text(new TranslatableMarkup('Description'), -10);
    $fields['state'] = QaFields::list(new TranslatableMarkup('Status'), 'draft', -15);
    $fields['start_date'] = ProcessFields::date(new TranslatableMarkup('Start Date'), -8);
    $fields['end_date'] = ProcessFields::date(new TranslatableMarkup('End Date'), -7);
    $fields['responsible'] = ProcessFields::user(new TranslatableMarkup('Responsible'), -9);
    $fields['environment'] = ProcessFields::reference(new TranslatableMarkup('Environment'), 'qa_environment', -14, TRUE);
    $fields['stage'] = ProcessFields::reference(new TranslatableMarkup('Execution Stage'), 'qa_stage', -13, TRUE);
    $fields['tags'] = QaFields::tags(-5);
    $fields['uid']->setLabel(new TranslatableMarkup('Created by'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

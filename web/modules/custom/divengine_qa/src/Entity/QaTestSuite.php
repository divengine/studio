<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessFields;
use Drupal\divengine_qa\Access\QaAccessControlHandler;
use Drupal\divengine_qa\QaDeletion;
use Drupal\divengine_qa\Form\QaEntityForm;
use Drupal\divengine_qa\QaViewBuilder;

/**
 * A suite of test cases, possibly inside a parent suite of the same plan.
 */
#[ContentEntityType(
  id: 'qa_test_suite',
  label: new TranslatableMarkup('Test suite'),
  label_collection: new TranslatableMarkup('Test suites'),
  label_singular: new TranslatableMarkup('test suite'),
  label_plural: new TranslatableMarkup('test suites'),
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
  base_table: 'qa_test_suite',
  admin_permission: 'administer qa',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  constraints: [
    'QaSuiteHierarchy' => [],
  ],
  links: [
    'canonical' => '/qa/suite/{qa_test_suite}',
    'add-form' => '/qa/suite/add',
    'edit-form' => '/qa/suite/{qa_test_suite}/edit',
    'delete-form' => '/qa/suite/{qa_test_suite}/delete',
  ],
)]
class QaTestSuite extends ContentEntityBase {

  use QaScopedTrait;

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->inheritDevelopment($this->get('plan')->entity);
    if ($this->isNew()) {
      $this->assignCode('TS');
    }
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    QaDeletion::assertDeletable($entities);
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $cases = \Drupal::entityTypeManager()->getStorage('qa_test_case');
    $ids = $cases->getQuery()->accessCheck(FALSE)->condition('suite', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $cases->delete($cases->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::derivedDevelopmentField();
    $fields['plan'] = ProcessFields::reference(new TranslatableMarkup('Test Plan'), 'qa_test_plan', -20, TRUE);
    $fields['code'] = QaFields::code();
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Suite Name'), -19, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['weight'] = ProcessFields::weight();
    $fields['parent'] = ProcessFields::reference(new TranslatableMarkup('Parent Suite'), 'qa_test_suite', -15);
    $fields['description'] = ProcessFields::text(new TranslatableMarkup('Description'), -10);
    $fields['state'] = QaFields::list(new TranslatableMarkup('Status'), 'draft', -12, TRUE);
    $fields['tags'] = QaFields::tags(-5);
    return $fields;
  }

}

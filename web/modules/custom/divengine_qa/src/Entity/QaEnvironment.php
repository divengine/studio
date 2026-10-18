<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessConfigEntityBase;
use Drupal\divengine_process\ProcessConfigListBuilder;
use Drupal\divengine_qa\Access\QaConfigAccessControlHandler;
use Drupal\divengine_qa\Form\QaConfigForm;

/**
 * Where a plan is executed; its default test URL is copied once into new runs.
 */
#[ConfigEntityType(
  id: 'qa_environment',
  label: new TranslatableMarkup('Test environment'),
  label_collection: new TranslatableMarkup('Test environments'),
  label_singular: new TranslatableMarkup('test environment'),
  label_plural: new TranslatableMarkup('test environments'),
  config_prefix: 'environment',
  admin_permission: 'administer qa',
  handlers: [
    'access' => QaConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => QaConfigForm::class,
      'edit' => QaConfigForm::class,
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'weight' => 'weight',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'weight',
    'status',
    'default_test_url',
  ],
  links: [
    'add-form' => '/admin/divengine/qa/environments/add',
    'edit-form' => '/admin/divengine/qa/environments/{qa_environment}',
    'delete-form' => '/admin/divengine/qa/environments/{qa_environment}/delete',
    'collection' => '/admin/divengine/qa/environments',
  ],
)]
class QaEnvironment extends ProcessConfigEntityBase {

  /**
   * The test URL proposed for new runs of plans in this environment.
   */
  protected string $default_test_url = '';

  public function getDefaultTestUrl(): string {
    return $this->default_test_url;
  }

  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    // Blank means no default.
    $this->default_test_url = trim($this->default_test_url);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessConfigEntityBase;
use Drupal\divengine_process\ProcessConfigListBuilder;
use Drupal\divengine_qa\Access\QaConfigAccessControlHandler;
use Drupal\divengine_qa\Form\QaConfigForm;

/**
 * How important a test case is; QA Issues take their priority from it.
 */
#[ConfigEntityType(
  id: 'qa_priority',
  label: new TranslatableMarkup('Test priority'),
  label_collection: new TranslatableMarkup('Test priorities'),
  label_singular: new TranslatableMarkup('test priority'),
  label_plural: new TranslatableMarkup('test priorities'),
  config_prefix: 'priority',
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
    'color',
  ],
  links: [
    'add-form' => '/admin/divengine/qa/priorities/add',
    'edit-form' => '/admin/divengine/qa/priorities/{qa_priority}',
    'delete-form' => '/admin/divengine/qa/priorities/{qa_priority}/delete',
    'collection' => '/admin/divengine/qa/priorities',
  ],
)]
class QaPriority extends ProcessConfigEntityBase {

  protected string $color = '#64748b';

  public function getColor(): string {
    return $this->color;
  }

}

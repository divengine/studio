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
 * The kind of testing a plan does: unit, integration, system, acceptance, regression.
 */
#[ConfigEntityType(
  id: 'qa_stage',
  label: new TranslatableMarkup('Test execution stage'),
  label_collection: new TranslatableMarkup('Test execution stages'),
  label_singular: new TranslatableMarkup('test execution stage'),
  label_plural: new TranslatableMarkup('test execution stages'),
  config_prefix: 'stage',
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
  ],
  links: [
    'add-form' => '/admin/divengine/qa/stages/add',
    'edit-form' => '/admin/divengine/qa/stages/{qa_stage}',
    'delete-form' => '/admin/divengine/qa/stages/{qa_stage}/delete',
    'collection' => '/admin/divengine/qa/stages',
  ],
)]
class QaStage extends ProcessConfigEntityBase {

}

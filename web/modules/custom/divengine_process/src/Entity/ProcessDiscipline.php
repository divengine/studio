<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Form\ProcessConfigForm;
use Drupal\divengine_process\ProcessConfigAccessControlHandler;
use Drupal\divengine_process\ProcessConfigListBuilder;

/**
 * An engineering concern active across phases and iterations.
 */
#[ConfigEntityType(
  id: 'process_discipline',
  label: new TranslatableMarkup('Discipline'),
  label_collection: new TranslatableMarkup('Disciplines'),
  label_singular: new TranslatableMarkup('discipline'),
  label_plural: new TranslatableMarkup('disciplines'),
  config_prefix: 'discipline',
  admin_permission: 'administer unified process',
  handlers: [
    'access' => ProcessConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => ProcessConfigForm::class,
      'edit' => ProcessConfigForm::class,
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
    'add-form' => '/admin/divengine/process/disciplines/add',
    'edit-form' => '/admin/divengine/process/disciplines/{process_discipline}',
    'delete-form' => '/admin/divengine/process/disciplines/{process_discipline}/delete',
    'collection' => '/admin/divengine/process/disciplines',
  ],
)]
class ProcessDiscipline extends ProcessConfigEntityBase {

}

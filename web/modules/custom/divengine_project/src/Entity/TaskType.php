<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_project\Access\ProjectConfigAccessControlHandler;
use Drupal\divengine_project\Form\ProjectConfigForm;
use Drupal\divengine_project\ProjectConfigListBuilder;

/**
 * The kind of a task: Epic, Feature, Story, Task, Bug…
 */
#[ConfigEntityType(
  id: 'project_task_type',
  label: new TranslatableMarkup('Task type'),
  label_collection: new TranslatableMarkup('Task types'),
  label_singular: new TranslatableMarkup('task type'),
  label_plural: new TranslatableMarkup('task types'),
  config_prefix: 'task_type',
  admin_permission: 'administer projects',
  handlers: [
    'access' => ProjectConfigAccessControlHandler::class,
    'list_builder' => ProjectConfigListBuilder::class,
    'form' => [
      'add' => ProjectConfigForm::class,
      'edit' => ProjectConfigForm::class,
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
    'color',
    'weight',
    'status',
  ],
  links: [
    'add-form' => '/admin/divengine/projects/types/add',
    'edit-form' => '/admin/divengine/projects/types/{project_task_type}',
    'delete-form' => '/admin/divengine/projects/types/{project_task_type}/delete',
    'collection' => '/admin/divengine/projects/types',
  ],
)]
class TaskType extends ProjectConfigEntityBase {

  protected string $color = '#335999';

  public function getColor(): string {
    return $this->color;
  }

}

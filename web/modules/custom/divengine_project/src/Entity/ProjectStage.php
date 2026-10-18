<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_project\Access\ProjectConfigAccessControlHandler;
use Drupal\divengine_project\Form\ProjectConfigForm;
use Drupal\divengine_project\ProjectConfigListBuilder;

/**
 * A column of the task boards (Backlog, To Do, In Progress…).
 *
 * Its category tells Studio what the stage means, whatever its name: work
 * not started, started, finished or abandoned. Projects pick the stages
 * they use.
 */
#[ConfigEntityType(
  id: 'project_stage',
  label: new TranslatableMarkup('Task stage'),
  label_collection: new TranslatableMarkup('Task stages'),
  label_singular: new TranslatableMarkup('task stage'),
  label_plural: new TranslatableMarkup('task stages'),
  config_prefix: 'stage',
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
    'category',
    'folded',
    'weight',
    'status',
  ],
  links: [
    'add-form' => '/admin/divengine/projects/stages/add',
    'edit-form' => '/admin/divengine/projects/stages/{project_stage}',
    'delete-form' => '/admin/divengine/projects/stages/{project_stage}/delete',
    'collection' => '/admin/divengine/projects/stages',
  ],
)]
class ProjectStage extends ProjectConfigEntityBase {

  protected string $category = 'todo';

  protected bool $folded = FALSE;

  public static function categories(): array {
    return [
      'todo' => new TranslatableMarkup('Not started'),
      'in_progress' => new TranslatableMarkup('In progress'),
      'done' => new TranslatableMarkup('Done'),
      'cancelled' => new TranslatableMarkup('Cancelled'),
    ];
  }

  public function getCategory(): string {
    return $this->category;
  }

  /**
   * Whether tasks in this stage are closed (done or cancelled).
   */
  public function isClosed(): bool {
    return in_array($this->category, ['done', 'cancelled'], TRUE);
  }

  /**
   * Whether boards show the column collapsed by default.
   */
  public function isFolded(): bool {
    return $this->folded;
  }

  /**
   * Badge tone of the category.
   */
  public function tone(): string {
    return match ($this->category) {
      'in_progress' => 'warning',
      'done' => 'success',
      'cancelled' => 'neutral',
      default => 'info',
    };
  }

}

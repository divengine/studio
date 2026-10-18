<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_project\Access\ProjectAccessControlHandler;
use Drupal\divengine_project\Form\ProjectEntityForm;
use Drupal\divengine_project\ProjectViewBuilder;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A task of a project, possibly a subtask of another task.
 *
 * Its code comes from the project key (BILL-0001). The stage is a column of
 * the project's board; entering a Done stage records when it was closed.
 * Related tasks (relates to, blocks, duplicates) and links to diagrams and
 * artifacts are traces.
 */
#[ContentEntityType(
  id: 'project_task',
  label: new TranslatableMarkup('Task'),
  label_collection: new TranslatableMarkup('Tasks'),
  label_singular: new TranslatableMarkup('task'),
  label_plural: new TranslatableMarkup('tasks'),
  handlers: [
    'view_builder' => ProjectViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => ProjectAccessControlHandler::class,
    'form' => [
      'default' => ProjectEntityForm::class,
      'add' => ProjectEntityForm::class,
      'edit' => ProjectEntityForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'project_task',
  revision_table: 'project_task_revision',
  show_revision_ui: TRUE,
  admin_permission: 'administer projects',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  constraints: [
    'ProjectHierarchy' => [],
  ],
  links: [
    'canonical' => '/task/{project_task}',
    'add-form' => '/task/add',
    'edit-form' => '/task/{project_task}/edit',
    'delete-form' => '/task/{project_task}/delete',
    'version-history' => '/task/{project_task}/revisions',
    'revision' => '/task/{project_task}/revisions/{project_task_revision}/view',
    'revision-revert-form' => '/task/{project_task}/revisions/{project_task_revision}/revert',
    'revision-delete-form' => '/task/{project_task}/revisions/{project_task_revision}/delete',
  ],
)]
class ProjectTask extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface, RevisionLogInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;

  public static function priorities(): array {
    return [
      '0' => new TranslatableMarkup('Low'),
      '1' => new TranslatableMarkup('Normal'),
      '2' => new TranslatableMarkup('High'),
      '3' => new TranslatableMarkup('Urgent'),
    ];
  }

  public function label() {
    $code = (string) $this->get('code')->value;
    return ($code !== '' ? "$code " : '') . $this->get('name')->value;
  }

  public function getProject(): ?Project {
    return $this->get('project')->entity;
  }

  public function getStage(): ?ProjectStage {
    return $this->get('stage')->entity;
  }

  public function isClosed(): bool {
    return (bool) $this->getStage()?->isClosed();
  }

  /**
   * Whether the deadline has passed and the task is still open.
   */
  public function isOverdue(): bool {
    $deadline = $this->get('deadline')->value;
    return $deadline && !$this->isClosed() && $deadline < date('Y-m-d', \Drupal::time()->getRequestTime());
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $project = $this->getProject();
    // A subtask belongs to the project of its parent.
    if ($parent = $this->get('parent')->entity) {
      $project = $parent->getProject();
      $this->set('project', $project?->id());
    }
    if ($project && $this->get('stage')->isEmpty()) {
      $this->set('stage', $project->getInitialStage()?->id());
    }
    if ($this->isNew() && trim((string) $this->get('code')->value) === '' && $project) {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next($project->getKey()));
    }
    // Closing records the date; reopening clears it.
    $closed = $this->isClosed();
    if ($closed && !$this->get('date_closed')->value) {
      $this->set('date_closed', \Drupal::time()->getRequestTime());
    }
    elseif (!$closed) {
      $this->set('date_closed', NULL);
    }
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    // Subtasks go with their parent.
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('parent', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Reporter'));
    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setSetting('max_length', 32)
      ->addConstraint('UniqueField');
    $fields['name'] = ProjectFields::string(new TranslatableMarkup('Title'), -20, TRUE)->setRevisionable(TRUE);
    $fields['description'] = ProjectFields::formatted(new TranslatableMarkup('Description'), -10)->setRevisionable(TRUE);
    $fields['project'] = ProjectFields::reference(new TranslatableMarkup('Project'), 'project', -19, TRUE)->setRevisionable(TRUE);
    $fields['parent'] = ProjectFields::reference(new TranslatableMarkup('Parent task'), 'project_task', -18)
      ->setDescription(new TranslatableMarkup('Make this a subtask of another task of the same project.'))
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -18])
      ->setRevisionable(TRUE);
    $fields['type'] = ProjectFields::reference(new TranslatableMarkup('Type'), 'project_task_type', -17, TRUE)
      ->setDefaultValue('task')
      ->setRevisionable(TRUE);
    $fields['stage'] = ProjectFields::reference(new TranslatableMarkup('Stage'), 'project_stage', -16)->setRevisionable(TRUE);
    $fields['priority'] = ProjectFields::list(new TranslatableMarkup('Priority'), static::priorities(), '1', -15)->setRevisionable(TRUE);
    $fields['assignees'] = ProjectFields::user(new TranslatableMarkup('Assignees'), -14, TRUE)->setRevisionable(TRUE);
    $fields['tags'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Tags'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['project_tag' => 'project_tag'], 'auto_create' => TRUE])
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => -13])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);
    $fields['start_date'] = ProjectFields::date(new TranslatableMarkup('Start date'), -12)->setRevisionable(TRUE);
    $fields['deadline'] = ProjectFields::date(new TranslatableMarkup('Deadline'), -11)->setRevisionable(TRUE);
    $fields['estimate'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Estimate (hours)'))
      ->setSetting('precision', 8)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);
    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Order on the board'))
      ->setDefaultValue(0);
    $fields['date_closed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Closed on'))
      ->setRevisionable(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'))->setRevisionable(TRUE);
    return $fields;
  }

}

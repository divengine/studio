<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_project\Access\ProjectAccessControlHandler;
use Drupal\divengine_project\Form\ProjectEntityForm;
use Drupal\divengine_project\ProjectViewBuilder;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A project: a team, a board of tasks and, optionally, subprojects.
 *
 * Its key (e.g. BILL) prefixes the codes of its tasks (BILL-0001). People
 * see a project when they own, manage or are members of it or of one of
 * its ancestors.
 */
#[ContentEntityType(
  id: 'project',
  label: new TranslatableMarkup('Project'),
  label_collection: new TranslatableMarkup('Projects'),
  label_singular: new TranslatableMarkup('project'),
  label_plural: new TranslatableMarkup('projects'),
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
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'project',
  admin_permission: 'administer projects',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'ProjectHierarchy' => [],
  ],
  links: [
    'canonical' => '/project/{project}',
    'add-form' => '/project/add',
    'edit-form' => '/project/{project}/edit',
    'delete-form' => '/project/{project}/delete',
  ],
)]
class Project extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public static function statuses(): array {
    return [
      'active' => new TranslatableMarkup('Active'),
      'on_hold' => new TranslatableMarkup('On Hold'),
      'done' => new TranslatableMarkup('Done'),
      'archived' => new TranslatableMarkup('Archived'),
    ];
  }

  public function label() {
    return '[' . $this->getKey() . '] ' . $this->get('name')->value;
  }

  public function getKey(): string {
    return (string) $this->get('project_key')->value;
  }

  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * The stages of the project's board, in their order.
   *
   * @return \Drupal\divengine_project\Entity\ProjectStage[]
   */
  public function getStages(): array {
    $all = ProjectStage::loadOrdered();
    $chosen = array_column($this->get('stages')->getValue(), 'target_id');
    return $chosen ? array_intersect_key($all, array_flip($chosen)) : $all;
  }

  /**
   * The stage new tasks start in: the first not-started one.
   */
  public function getInitialStage(): ?ProjectStage {
    $stages = $this->getStages();
    foreach ($stages as $stage) {
      if ($stage->getCategory() === 'todo' && !$stage->isFolded()) {
        return $stage;
      }
    }
    return reset($stages) ?: NULL;
  }

  /**
   * Whether a user owns, manages or is a member of this very project.
   */
  public function hasMember(int|string $uid): bool {
    $uid = (string) $uid;
    return (string) $this->getOwnerId() === $uid
      || (string) $this->get('manager')->target_id === $uid
      || in_array($uid, array_map('strval', array_column($this->get('members')->getValue(), 'target_id')), TRUE);
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('project_key', mb_strtoupper(trim($this->getKey())));
    \Drupal::service('divengine_project.hierarchy')->assertAcyclic($this);
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    // Subprojects move up to the deleted project's parent; tasks go with it.
    $ids = array_keys($entities);
    foreach ($storage->loadByProperties(['parent' => $ids]) as $child) {
      $child->set('parent', $entities[$child->get('parent')->target_id]->get('parent')->target_id)->save();
    }
    $tasks = \Drupal::entityTypeManager()->getStorage('project_task');
    $task_ids = $tasks->getQuery()->accessCheck(FALSE)->condition('project', $ids, 'IN')->execute();
    if ($task_ids) {
      $tasks->delete($tasks->loadMultiple($task_ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Owner'));
    $fields['name'] = ProjectFields::string(new TranslatableMarkup('Project name'), -20, TRUE);
    $fields['project_key'] = ProjectFields::string(new TranslatableMarkup('Key'), -19, TRUE, 10)
      ->setDescription(new TranslatableMarkup('2 to 10 capital letters or digits, starting with a letter. It prefixes the task codes, e.g. BILL-0001.'))
      ->addConstraint('UniqueField')
      ->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^[A-Za-z][A-Za-z0-9]{1,9}$/', 'message' => 'The key must be 2 to 10 letters or digits, starting with a letter.']]);
    $fields['description'] = ProjectFields::formatted(new TranslatableMarkup('Description'), -10);
    $fields['parent'] = ProjectFields::reference(new TranslatableMarkup('Parent project'), 'project', -15)
      ->setDescription(new TranslatableMarkup('Make this a subproject of another project.'));
    $fields['status'] = ProjectFields::list(new TranslatableMarkup('Status'), static::statuses(), 'active', -14);
    $fields['manager'] = ProjectFields::user(new TranslatableMarkup('Project manager'), -13);
    $fields['members'] = ProjectFields::user(new TranslatableMarkup('Members'), -12, TRUE)
      ->setDescription(new TranslatableMarkup('People who see the project, its subprojects and their tasks.'));
    $fields['start_date'] = ProjectFields::date(new TranslatableMarkup('Start date'), -11);
    $fields['end_date'] = ProjectFields::date(new TranslatableMarkup('End date'), -11);
    $fields['stages'] = ProjectFields::reference(new TranslatableMarkup('Board stages'), 'project_stage', -5, FALSE, TRUE)
      ->setDescription(new TranslatableMarkup('The columns of the board. Leave empty to use every stage.'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Access\DevelopmentAccessControlHandler;
use Drupal\divengine_process\DevelopmentListBuilder;
use Drupal\divengine_process\DevelopmentViewBuilder;
use Drupal\divengine_process\Form\DevelopmentForm;
use Drupal\divengine_process\Lifecycle\StatefulTrait;
use Drupal\divengine_process\Lifecycle\Transition;
use Drupal\divengine_process\Vocabulary;
use Drupal\user\EntityOwnerTrait;

/**
 * One governed software development effort: the root of the process.
 *
 * Creating one creates its lifecycle phases and milestones and applies the
 * default Development Case (see DevelopmentProcess). The owner is the
 * creator.
 */
#[ContentEntityType(
  id: 'development',
  label: new TranslatableMarkup('Development'),
  label_collection: new TranslatableMarkup('Developments'),
  label_singular: new TranslatableMarkup('Development'),
  label_plural: new TranslatableMarkup('Developments'),
  handlers: [
    'list_builder' => DevelopmentListBuilder::class,
    'view_builder' => DevelopmentViewBuilder::class,
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => DevelopmentAccessControlHandler::class,
    'form' => [
      'default' => DevelopmentForm::class,
      'add' => DevelopmentForm::class,
      'edit' => DevelopmentForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'development',
  revision_table: 'development_revision',
  show_revision_ui: true,
  admin_permission: 'administer unified process',
  collection_permission: 'use unified process',
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
    'ProcessStateTransition' => [],
    'ProcessDateOrder' => [],
    'ProcessSameDevelopment' => ['fields' => ['current_phase', 'current_iteration'], 'self' => TRUE],
    'ProcessUniqueFields' => ['fields' => ['code'], 'message' => 'This code is already in use.'],
  ],
  links: [
    'canonical' => '/development/{development}',
    'add-form' => '/development/add',
    'edit-form' => '/development/{development}/edit',
    'delete-form' => '/development/{development}/delete',
    'collection' => '/developments',
    'version-history' => '/development/{development}/revisions',
    'revision' => '/development/{development}/revisions/{development_revision}/view',
    'revision-revert-form' => '/development/{development}/revisions/{development_revision}/revert',
    'revision-delete-form' => '/development/{development}/revisions/{development_revision}/delete',
  ],
)]
class Development extends ContentEntityBase implements DevelopmentInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;
  use StatefulTrait;

  public static function transitions(): array {
    return [
      'activate' => new Transition('activate', new TranslatableMarkup('Activate'), ['planned', 'on_hold'], 'active'),
      'hold' => new Transition('hold', new TranslatableMarkup('Put on hold'), ['active'], 'on_hold'),
      'close' => new Transition('close', new TranslatableMarkup('Close'), ['active', 'on_hold'], 'closed'),
      'cancel' => new Transition('cancel', new TranslatableMarkup('Cancel'), ['planned', 'active', 'on_hold'], 'cancelled'),
      'reset' => new Transition('reset', new TranslatableMarkup('Reset to planned'), ['closed', 'cancelled'], 'planned'),
    ];
  }

  public static function stateLabels(): array {
    return Vocabulary::developmentStates();
  }

  public function label() {
    $name = (string) $this->get('name')->value;
    return $this->getCode() !== '' ? '[' . $this->getCode() . '] ' . $name : $name;
  }

  public function getCode(): string {
    return (string) $this->get('code')->value;
  }

  public function isVisibleTo(int $uid): bool {
    if ($uid === 0) {
      return FALSE;
    }
    if ((int) $this->getOwnerId() === $uid || (int) $this->get('responsible')->target_id === $uid) {
      return TRUE;
    }
    return in_array($uid, array_map('intval', array_column($this->get('members')->getValue(), 'target_id')), TRUE);
  }

  public function getCase(): ?DevelopmentCase {
    return $this->get('case')->entity;
  }

  public function getCaseVersion(): ?int {
    $version = $this->get('case_version')->value;
    return $version === NULL ? NULL : (int) $version;
  }

  public function isCaseOutdated(): bool {
    $case = $this->getCase();
    return $case && $this->getCaseVersion() !== NULL && $case->getVersion() !== $this->getCaseVersion();
  }

  public function getPolicy(): array {
    return $this->get('policy')->first()?->getValue()['lines'] ?? [];
  }

  /**
   * Default value callback: the default Development Case.
   *
   * @return array<int, array<string, string>>
   */
  public static function getDefaultCase(): array {
    $case = DevelopmentCase::loadDefault();
    return $case ? [['target_id' => $case->id()]] : [];
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('name', trim((string) $this->get('name')->value));
    if ($this->isNew()) {
      if ($this->getCode() === '') {
        $this->set('code', \Drupal::service('divengine_core.sequence')->next('DEV'));
      }
    }
  }

  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    if (!$update) {
      \Drupal::service('divengine_process.development')->initialize($this);
    }
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    // The structure of a Development goes with it.
    $entity_type_manager = \Drupal::entityTypeManager();
    foreach (['role_assignment', 'milestone', 'iteration', 'development_phase'] as $entity_type_id) {
      $children = $entity_type_manager->getStorage($entity_type_id);
      $ids = $children->getQuery()->accessCheck(FALSE)->condition('development', array_keys($entities), 'IN')->execute();
      if ($ids) {
        $children->delete($children->loadMultiple($ids));
      }
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['uid']->setLabel(new TranslatableMarkup('Created by'))
      ->setDescription(new TranslatableMarkup('The creator always sees the Development.'));

    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setDescription(new TranslatableMarkup('Assigned on creation, e.g. DEV-0001.'))
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('view', TRUE);

    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Name'), -25, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);

    $fields['responsible'] = ProcessFields::user(new TranslatableMarkup('Responsible'), -20)
      ->setDescription(new TranslatableMarkup('Accountable for this Development.'))
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);

    $fields['members'] = ProcessFields::user(new TranslatableMarkup('Members'), -19, TRUE)
      ->setDescription(new TranslatableMarkup('Who else sees and works on this Development and everything in it. Process roles grant no access; membership does.'))
      ->setRevisionable(TRUE);

    $fields['state'] = ProcessFields::list(new TranslatableMarkup('State'), 'planned', -18, FALSE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);

    $fields['date_start'] = ProcessFields::date(new TranslatableMarkup('Start date'), -15)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);
    $fields['date_end'] = ProcessFields::date(new TranslatableMarkup('End date'), -14)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);

    $fields['objective'] = ProcessFields::text(new TranslatableMarkup('Objective'), -10)
      ->setDescription(new TranslatableMarkup('What this Development must achieve.'))
      ->setRevisionable(TRUE);
    $fields['notes'] = ProcessFields::text(new TranslatableMarkup('Notes'), -9)->setRevisionable(TRUE);

    $fields['current_phase'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Current phase'))
      ->setSetting('target_type', 'development_phase')
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['current_iteration'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Current iteration'))
      ->setSetting('target_type', 'iteration')
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['case'] = ProcessFields::reference(new TranslatableMarkup('Development Case'), 'development_case', -5)
      ->setDescription(new TranslatableMarkup('The tailoring this Development follows. Changing it takes effect when the case is applied.'))
      ->setDefaultValueCallback(static::class . '::getDefaultCase')
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);
    $fields['case_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Applied version'))
      ->setRevisionable(TRUE);
    $fields['case_applied'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Applied on'))
      ->setRevisionable(TRUE);
    $fields['policy'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Policy'))
      ->setDescription(new TranslatableMarkup('Snapshot of the Development Case lines.'))
      ->setRevisionable(TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDescription(new TranslatableMarkup('Archived Developments are hidden from the default lists.'))
      ->setDefaultValue(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE], 'weight' => 40])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}

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
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Access\ScopedAccessControlHandler;
use Drupal\divengine_process\Form\ScopedEntityForm;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Lifecycle\StatefulTrait;
use Drupal\divengine_process\Lifecycle\Transition;
use Drupal\divengine_process\ProcessEntityViewBuilder;
use Drupal\divengine_process\Vocabulary;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A governed change to engineering information.
 *
 * Submitted, triaged, decided with a rationale, scheduled into an iteration,
 * implemented, verified and closed. What it affects is recorded as explicit
 * "impacts" traces, never inferred.
 */
#[ContentEntityType(
  id: 'change_request',
  label: new TranslatableMarkup('Change request'),
  label_collection: new TranslatableMarkup('Change requests'),
  label_singular: new TranslatableMarkup('change request'),
  label_plural: new TranslatableMarkup('change requests'),
  handlers: [
    'view_builder' => ProcessEntityViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => ScopedAccessControlHandler::class,
    'form' => [
      'default' => ScopedEntityForm::class,
      'add' => ScopedEntityForm::class,
      'edit' => ScopedEntityForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'change_request',
  revision_table: 'change_request_revision',
  show_revision_ui: true,
  admin_permission: 'administer unified process',
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
    'ProcessDevelopmentEditable' => [],
    'ProcessSameDevelopment' => [],
    'ProcessUniqueFields' => ['fields' => ['code'], 'message' => 'This code is already in use.'],
  ],
  links: [
    'canonical' => '/change/{change_request}',
    'add-form' => '/change/add',
    'edit-form' => '/change/{change_request}/edit',
    'delete-form' => '/change/{change_request}/delete',
    'version-history' => '/change/{change_request}/revisions',
    'revision' => '/change/{change_request}/revisions/{change_request_revision}/view',
    'revision-revert-form' => '/change/{change_request}/revisions/{change_request_revision}/revert',
    'revision-delete-form' => '/change/{change_request}/revisions/{change_request_revision}/delete',
  ],
)]
class ChangeRequest extends ContentEntityBase implements StatefulInterface, EntityOwnerInterface, RevisionLogInterface {

  use DevelopmentScopedTrait;
  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;
  use StatefulTrait;

  /**
   * States in which a change request is still open.
   */
  const OPEN_STATES = ['submitted', 'triaged', 'accepted', 'scheduled', 'implemented'];

  public static function transitions(): array {
    return [
      'triage' => new Transition('triage', new TranslatableMarkup('Triage'), ['submitted'], 'triaged'),
      'accept' => new Transition('accept', new TranslatableMarkup('Accept'), ['triaged'], 'accepted', guard: 'checkRationale', effect: 'recordDecision', field: 'decision_rationale'),
      'reject' => new Transition('reject', new TranslatableMarkup('Reject'), ['triaged'], 'rejected', guard: 'checkRationale', effect: 'recordDecision', field: 'decision_rationale'),
      'schedule' => new Transition('schedule', new TranslatableMarkup('Schedule'), ['accepted'], 'scheduled', guard: 'checkTargetIteration', field: 'target_iteration'),
      'implement' => new Transition('implement', new TranslatableMarkup('Mark implemented'), ['scheduled'], 'implemented'),
      'verify' => new Transition('verify', new TranslatableMarkup('Mark verified'), ['implemented'], 'verified'),
      'close' => new Transition('close', new TranslatableMarkup('Close'), ['verified', 'rejected'], 'closed'),
      'retriage' => new Transition('retriage', new TranslatableMarkup('Back to triage'), ['accepted', 'rejected'], 'triaged'),
    ];
  }

  public static function stateLabels(): array {
    return Vocabulary::changeRequestStates();
  }

  public function label() {
    $name = (string) $this->get('name')->value;
    $code = (string) $this->get('code')->value;
    return $code !== '' ? "[$code] $name" : $name;
  }

  public function checkRationale(): ?TranslatableMarkup {
    return trim((string) $this->get('decision_rationale')->value) === ''
      ? new TranslatableMarkup('Write the decision rationale of %name first.', ['%name' => $this->label()])
      : NULL;
  }

  public function checkTargetIteration(): ?TranslatableMarkup {
    return $this->get('target_iteration')->isEmpty()
      ? new TranslatableMarkup('Choose the target iteration of %name first.', ['%name' => $this->label()])
      : NULL;
  }

  /**
   * Records who decided and when.
   */
  public function recordDecision(): void {
    $this->set('decided_by', \Drupal::currentUser()->id());
    $this->set('decision_date', \Drupal::time()->getRequestTime());
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew() && (string) $this->get('code')->value === '') {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next('CR'));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Owner'))
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 20])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 20, 'settings' => ['link' => FALSE]])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['development'] = static::developmentFieldDefinition();
    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setSetting('max_length', 32);
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Title'), -19, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);
    $fields['description'] = ProcessFields::text(new TranslatableMarkup('Change'), -18)
      ->setDescription(new TranslatableMarkup('The problem or the change requested.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);
    $fields['requested_by'] = ProcessFields::string(new TranslatableMarkup('Requested by'), -15)->setRevisionable(TRUE);
    $fields['source'] = ProcessFields::string(new TranslatableMarkup('Source'), -14)
      ->setDescription(new TranslatableMarkup('Where the request comes from (ticket, meeting, audit…).'))
      ->setRevisionable(TRUE);
    $fields['priority'] = ProcessFields::list(new TranslatableMarkup('Priority'), 'medium', -13)->setRevisionable(TRUE);
    $fields['severity'] = ProcessFields::list(new TranslatableMarkup('Severity'), 'minor', -12)->setRevisionable(TRUE);
    $fields['state'] = ProcessFields::list(new TranslatableMarkup('State'), 'submitted', -11, FALSE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setRevisionable(TRUE);
    $fields['target_iteration'] = ProcessFields::reference(new TranslatableMarkup('Target iteration'), 'iteration', -10)
      ->setDescription(new TranslatableMarkup('Required to schedule the change.'))
      ->setRevisionable(TRUE);
    $fields['decision_rationale'] = ProcessFields::text(new TranslatableMarkup('Decision rationale'), -5)
      ->setDescription(new TranslatableMarkup('Why it was accepted or rejected.'))
      ->setDisplayOptions('form', ['region' => 'hidden'])
      ->setRevisionable(TRUE);
    $fields['decided_by'] = ProcessFields::user(new TranslatableMarkup('Decided by'), -4)
      ->setDisplayOptions('form', ['region' => 'hidden'])
      ->setRevisionable(TRUE);
    $fields['decision_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Decided on'))
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => -3])
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDefaultValue(TRUE)
      ->setRevisionable(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Submitted'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'))->setRevisionable(TRUE);
    return $fields;
  }

}

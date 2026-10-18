<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
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
 * The execution of an activity in one Development, normally one iteration.
 *
 * Not its definition and not a project task. Picking a definition proposes
 * the name, discipline and responsible role. Completing an activity without
 * the outputs its definition expects, or skipping it, needs a deviation
 * note; blocking it needs a reason.
 */
#[ContentEntityType(
  id: 'process_activity',
  label: new TranslatableMarkup('Activity'),
  label_collection: new TranslatableMarkup('Activities'),
  label_singular: new TranslatableMarkup('activity'),
  label_plural: new TranslatableMarkup('activities'),
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
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'process_activity',
  admin_permission: 'administer unified process',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'ProcessDevelopmentEditable' => [],
    'ProcessSameDevelopment' => [],
  ],
  links: [
    'canonical' => '/activity/{process_activity}',
    'add-form' => '/activity/add',
    'edit-form' => '/activity/{process_activity}/edit',
    'delete-form' => '/activity/{process_activity}/delete',
  ],
)]
class ProcessActivity extends ContentEntityBase implements StatefulInterface, EntityOwnerInterface {

  use DevelopmentScopedTrait;
  use EntityOwnerTrait;
  use StatefulTrait;

  public static function transitions(): array {
    return [
      'ready' => new Transition('ready', new TranslatableMarkup('Ready'), ['planned', 'blocked'], 'ready'),
      'start' => new Transition('start', new TranslatableMarkup('Start'), ['planned', 'ready', 'blocked'], 'in_progress'),
      'block' => new Transition('block', new TranslatableMarkup('Block'), ['planned', 'ready', 'in_progress'], 'blocked', guard: 'checkBlockReason', field: 'block_reason'),
      'complete' => new Transition('complete', new TranslatableMarkup('Complete'), ['ready', 'in_progress'], 'completed', guard: 'checkOutputs'),
      'skip' => new Transition('skip', new TranslatableMarkup('Skip'), ['planned', 'ready', 'blocked'], 'skipped', guard: 'checkDeviation', field: 'deviation_note'),
      'replan' => new Transition('replan', new TranslatableMarkup('Re-plan'), ['skipped', 'completed', 'blocked'], 'planned'),
    ];
  }

  public static function stateLabels(): array {
    return Vocabulary::activityStates();
  }

  public function checkBlockReason(): ?TranslatableMarkup {
    return trim((string) $this->get('block_reason')->value) === ''
      ? new TranslatableMarkup('Write why activity %name is blocked first.', ['%name' => $this->label()])
      : NULL;
  }

  public function checkDeviation(): ?TranslatableMarkup {
    return trim((string) $this->get('deviation_note')->value) === ''
      ? new TranslatableMarkup('Explain why activity %name is skipped (deviation) first.', ['%name' => $this->label()])
      : NULL;
  }

  public function checkOutputs(): ?TranslatableMarkup {
    $missing = $this->missingOutputs();
    if ($missing && trim((string) $this->get('deviation_note')->value) === '') {
      return new TranslatableMarkup('Activity %name has no output for @missing: add it, or explain the deviation first.', [
        '%name' => $this->label(),
        '@missing' => implode(', ', array_map(fn ($d) => $d->label(), $missing)),
      ]);
    }
    return NULL;
  }

  /**
   * Expected outputs of the definition that no output work product covers.
   *
   * @return \Drupal\divengine_process\Entity\WorkProductDefinition[]
   */
  public function missingOutputs(): array {
    $definition = $this->get('definition')->entity;
    if (!$definition instanceof ActivityDefinition || !$definition->getOutputDefinitions()) {
      return [];
    }
    $covered = [];
    foreach ($this->get('outputs')->referencedEntities() as $output) {
      if ($output->hasField('field_wp_definition') && ($id = $output->get('field_wp_definition')->target_id)) {
        $covered[$id] = TRUE;
      }
    }
    $missing = array_diff($definition->getOutputDefinitions(), array_keys($covered));
    return $missing ? \Drupal::entityTypeManager()->getStorage('work_product_definition')->loadMultiple($missing) : [];
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $definition = $this->get('definition')->entity;
    if (!$definition instanceof ActivityDefinition) {
      return;
    }
    // The definition proposes the name and responsible role without
    // overwriting what was typed; the discipline follows the definition.
    if (trim((string) $this->get('name')->value) === '') {
      $this->set('name', $definition->label());
    }
    $original = $this->isNew() ? NULL : $this->getOriginal();
    if ($this->get('discipline')->isEmpty() || ($original && $original->get('definition')->target_id !== $definition->id())) {
      $this->set('discipline', $definition->getDiscipline() ?: NULL);
    }
    if ($this->get('responsible_role')->isEmpty() && $definition->getResponsibleRole()) {
      $this->set('responsible_role', $definition->getResponsibleRole());
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Owner'))
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 20])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 20, 'settings' => ['link' => FALSE]])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['development'] = static::developmentFieldDefinition();
    $fields['definition'] = ProcessFields::reference(new TranslatableMarkup('Activity definition'), 'activity_definition', -19)
      ->setDescription(new TranslatableMarkup('Proposes the name, discipline and responsible role, and the outputs it should produce.'));
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Name'), -18)
      ->setDescription(new TranslatableMarkup('Leave empty to use the name of the definition.'))
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['weight'] = ProcessFields::weight();
    $fields['state'] = ProcessFields::list(new TranslatableMarkup('State'), 'planned', -16, FALSE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['iteration'] = ProcessFields::reference(new TranslatableMarkup('Iteration'), 'iteration', -15);
    $fields['discipline'] = ProcessFields::reference(new TranslatableMarkup('Discipline'), 'process_discipline', -14);
    $fields['responsible_role'] = ProcessFields::reference(new TranslatableMarkup('Responsible role'), 'process_role', -13);
    $fields['assignees'] = ProcessFields::user(new TranslatableMarkup('Assignees'), -12, TRUE);
    $fields['objective'] = ProcessFields::text(new TranslatableMarkup('Objective'), -10);
    $fields['date_start'] = ProcessFields::date(new TranslatableMarkup('Start date'), -8);
    $fields['date_deadline'] = ProcessFields::date(new TranslatableMarkup('Deadline'), -7);
    $fields['inputs'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Input work products'))
      ->setSetting('target_type', 'artifact')
      ->setSetting('handler', 'divengine_process_development')
      ->setSetting('handler_settings', ['target_bundles' => ['work_product' => 'work_product']])
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => -5])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['outputs'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Output work products'))
      ->setSetting('target_type', 'artifact')
      ->setSetting('handler', 'divengine_process_development')
      ->setSetting('handler_settings', ['target_bundles' => ['work_product' => 'work_product']])
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => -4])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => -4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['block_reason'] = ProcessFields::text(new TranslatableMarkup('Block reason'), 5)
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['deviation_note'] = ProcessFields::text(new TranslatableMarkup('Deviation / rationale'), 6)
      ->setDescription(new TranslatableMarkup('Why it was skipped, or completed without an expected output.'));
    return $fields;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_timesheet\Access\TimesheetAccessControlHandler;
use Drupal\divengine_timesheet\Form\TimesheetForm;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Time someone spent on a project, and optionally on one of its tasks.
 *
 * Like an Odoo timesheet line: date, person, project, task, description and
 * hours. A negative entry adjusts the total of a day (the weekly grid
 * writes those when a cell already holds several entries).
 */
#[ContentEntityType(
  id: 'timesheet',
  label: new TranslatableMarkup('Timesheet entry'),
  label_collection: new TranslatableMarkup('Timesheets'),
  label_singular: new TranslatableMarkup('timesheet entry'),
  label_plural: new TranslatableMarkup('timesheet entries'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => TimesheetAccessControlHandler::class,
    'form' => [
      'default' => TimesheetForm::class,
      'add' => TimesheetForm::class,
      'edit' => TimesheetForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'timesheet',
  admin_permission: 'administer projects',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  constraints: [
    'TimesheetRules' => [],
  ],
  links: [
    'add-form' => '/timesheet/add',
    'edit-form' => '/timesheet/{timesheet}/edit',
    'delete-form' => '/timesheet/{timesheet}/delete',
  ],
)]
class Timesheet extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public function label() {
    return (string) new TranslatableMarkup('@hours h on @date', ['@hours' => static::formatHours((float) $this->get('hours')->value), '@date' => $this->get('date')->value]);
  }

  public function getHours(): float {
    return (float) $this->get('hours')->value;
  }

  /**
   * Hours as h:mm, the way timesheets are read (1.5 → 1:30).
   */
  public static function formatHours(float $hours): string {
    $sign = $hours < 0 ? '-' : '';
    $minutes = (int) round(abs($hours) * 60);
    return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
  }

  /**
   * Reads "1.5", "1,5" or "1:30" as hours; NULL when it is not a duration.
   */
  public static function parseHours(string $input): ?float {
    $input = trim(str_replace(',', '.', $input));
    if ($input === '') {
      return NULL;
    }
    if (preg_match('/^(-?)(\d+):([0-5]?\d)$/', $input, $m)) {
      $hours = (int) $m[2] + (int) $m[3] / 60;
      return $m[1] === '-' ? -$hours : $hours;
    }
    return is_numeric($input) ? (float) $input : NULL;
  }

  /**
   * Whether this save gives the entry a project its task is not part of.
   *
   * An entry on a task belongs to the task's project. Only a new entry, or a
   * change of its task or project, is checked: entries logged before their
   * task moved to another project keep their project and can still be
   * edited.
   */
  public function contradictsTask(): bool {
    $task = $this->get('task')->entity;
    $project = $this->get('project')->target_id;
    if (!$task || !$project || (string) $task->get('project')->target_id === (string) $project) {
      return FALSE;
    }
    if ($this->isNew()) {
      return TRUE;
    }
    $original = $this->getOriginal() ?? \Drupal::entityTypeManager()->getStorage('timesheet')->loadUnchanged($this->id());
    return !$original
      || (string) $original->get('task')->target_id !== (string) $task->id()
      || (string) $original->get('project')->target_id !== (string) $project;
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    // An entry on a task without a project takes the task's project. A
    // contradicting project is refused, never replaced: the TimesheetRules
    // constraint reports it to forms and the API, this guards direct saves.
    $task = $this->get('task')->entity;
    if ($task && $this->get('project')->isEmpty()) {
      $this->set('project', $task->get('project')->target_id);
    }
    if ($this->get('project')->isEmpty()) {
      throw new EntityStorageException('A timesheet entry needs a project or a task.');
    }
    if ($this->contradictsTask()) {
      throw new EntityStorageException(sprintf('Task %s is not part of project %s. Give the task\'s project (%s) or no project.', $task->id(), $this->get('project')->target_id, $task->get('project')->target_id));
    }
    $this->set('description', trim((string) $this->get('description')->value) ?: '/');
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Person'))
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE);
    $fields['date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Date'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDefaultValueCallback(static::class . '::today')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);
    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      // Required unless a task gives it: see preSave() and TimesheetRules.
      ->setSetting('target_type', 'project')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE);
    $fields['task'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Task'))
      ->setSetting('target_type', 'project_task')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE);
    $fields['description'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -7, 'settings' => ['placeholder' => 'What did you do?']])
      ->setDisplayConfigurable('form', TRUE);
    $fields['hours'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Hours'))
      ->setRequired(TRUE)
      ->setSetting('precision', 6)
      ->setSetting('scale', 2)
      ->setDescription(new TranslatableMarkup('Decimal hours, e.g. 1.5 for an hour and a half.'))
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => -6, 'settings' => ['placeholder' => '1.5']])
      ->setDisplayConfigurable('form', TRUE);
    $fields['adjustment'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Adjustment'))
      ->setDescription(new TranslatableMarkup('Written by the weekly grid to change the total of a day that holds several entries.'))
      ->setDefaultValue(FALSE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

  /**
   * Default date: today.
   */
  public static function today(): array {
    return [['value' => date('Y-m-d', \Drupal::time()->getRequestTime())]];
  }

}

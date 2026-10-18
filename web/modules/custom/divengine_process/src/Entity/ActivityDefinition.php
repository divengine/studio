<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Form\ActivityDefinitionForm;
use Drupal\divengine_process\ProcessConfigAccessControlHandler;
use Drupal\divengine_process\ProcessConfigListBuilder;

/**
 * The reusable meaning of an activity.
 *
 * When an activity is needed is not part of the definition: Development Case
 * lines decide that, per milestone.
 */
#[ConfigEntityType(
  id: 'activity_definition',
  label: new TranslatableMarkup('Activity definition'),
  label_collection: new TranslatableMarkup('Activity definitions'),
  label_singular: new TranslatableMarkup('activity definition'),
  label_plural: new TranslatableMarkup('activity definitions'),
  config_prefix: 'activity_definition',
  admin_permission: 'administer unified process',
  handlers: [
    'access' => ProcessConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => ActivityDefinitionForm::class,
      'edit' => ActivityDefinitionForm::class,
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
    'discipline',
    'responsible_role',
    'participant_roles',
    'input_definitions',
    'output_definitions',
    'guidance',
  ],
  links: [
    'add-form' => '/admin/divengine/process/activity-definitions/add',
    'edit-form' => '/admin/divengine/process/activity-definitions/{activity_definition}',
    'delete-form' => '/admin/divengine/process/activity-definitions/{activity_definition}/delete',
    'collection' => '/admin/divengine/process/activity-definitions',
  ],
)]
class ActivityDefinition extends ProcessConfigEntityBase {

  protected string $discipline = '';

  protected string $responsible_role = '';

  /**
   * @var string[]
   */
  protected array $participant_roles = [];

  /**
   * Expected inputs (work product definitions).
   *
   * @var string[]
   */
  protected array $input_definitions = [];

  /**
   * Expected outputs (work product definitions).
   *
   * An activity completed without one of them must explain the deviation.
   *
   * @var string[]
   */
  protected array $output_definitions = [];

  protected string $guidance = '';

  public function getDiscipline(): string {
    return $this->discipline;
  }

  public function getResponsibleRole(): string {
    return $this->responsible_role;
  }

  /**
   * @return string[]
   */
  public function getParticipantRoles(): array {
    return $this->participant_roles;
  }

  /**
   * @return string[]
   */
  public function getInputDefinitions(): array {
    return $this->input_definitions;
  }

  /**
   * @return string[]
   */
  public function getOutputDefinitions(): array {
    return $this->output_definitions;
  }

  public function getGuidance(): string {
    return $this->guidance;
  }

  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addReferenceDependencies('process_discipline', [$this->discipline]);
    $this->addReferenceDependencies('process_role', [$this->responsible_role, ...$this->participant_roles]);
    $this->addReferenceDependencies('work_product_definition', [...$this->input_definitions, ...$this->output_definitions]);
    return $this;
  }

}

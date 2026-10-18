<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Form\ProcessPhaseTypeForm;
use Drupal\divengine_process\ProcessConfigAccessControlHandler;
use Drupal\divengine_process\ProcessConfigListBuilder;

/**
 * A lifecycle phase: Inception, Elaboration, Construction, Transition.
 *
 * Every new Development gets one phase per enabled phase type, and one
 * milestone per closing milestone.
 */
#[ConfigEntityType(
  id: 'process_phase_type',
  label: new TranslatableMarkup('Lifecycle phase type'),
  label_collection: new TranslatableMarkup('Lifecycle phase types'),
  label_singular: new TranslatableMarkup('lifecycle phase type'),
  label_plural: new TranslatableMarkup('lifecycle phase types'),
  config_prefix: 'phase_type',
  admin_permission: 'administer unified process',
  handlers: [
    'access' => ProcessConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => ProcessPhaseTypeForm::class,
      'edit' => ProcessPhaseTypeForm::class,
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
    'milestone_type',
  ],
  links: [
    'add-form' => '/admin/divengine/process/phase-types/add',
    'edit-form' => '/admin/divengine/process/phase-types/{process_phase_type}',
    'delete-form' => '/admin/divengine/process/phase-types/{process_phase_type}/delete',
    'collection' => '/admin/divengine/process/phase-types',
  ],
)]
class ProcessPhaseType extends ProcessConfigEntityBase {

  /**
   * The lifecycle milestone that closes this phase, if any.
   */
  protected string $milestone_type = '';

  public function getMilestoneType(): string {
    return $this->milestone_type;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Form\ProcessRoleForm;
use Drupal\divengine_process\ProcessConfigAccessControlHandler;
use Drupal\divengine_process\ProcessConfigListBuilder;

/**
 * A role people play in a Development.
 *
 * Methodology only: it is not a Drupal role and playing it grants no access.
 */
#[ConfigEntityType(
  id: 'process_role',
  label: new TranslatableMarkup('Process role'),
  label_collection: new TranslatableMarkup('Process roles'),
  label_singular: new TranslatableMarkup('process role'),
  label_plural: new TranslatableMarkup('process roles'),
  config_prefix: 'role',
  admin_permission: 'administer unified process',
  handlers: [
    'access' => ProcessConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => ProcessRoleForm::class,
      'edit' => ProcessRoleForm::class,
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
    'disciplines',
  ],
  links: [
    'add-form' => '/admin/divengine/process/roles/add',
    'edit-form' => '/admin/divengine/process/roles/{process_role}',
    'delete-form' => '/admin/divengine/process/roles/{process_role}/delete',
    'collection' => '/admin/divengine/process/roles',
  ],
)]
class ProcessRole extends ProcessConfigEntityBase {

  /**
   * Disciplines this role covers (machine names).
   *
   * @var string[]
   */
  protected array $disciplines = [];

  /**
   * @return string[]
   */
  public function getDisciplines(): array {
    return $this->disciplines;
  }

  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addReferenceDependencies('process_discipline', $this->disciplines);
    return $this;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Form\WorkProductDefinitionForm;
use Drupal\divengine_process\ProcessConfigAccessControlHandler;
use Drupal\divengine_process\ProcessConfigListBuilder;

/**
 * A kind of engineering output, such as the Vision.
 */
#[ConfigEntityType(
  id: 'work_product_definition',
  label: new TranslatableMarkup('Work product definition'),
  label_collection: new TranslatableMarkup('Work product definitions'),
  label_singular: new TranslatableMarkup('work product definition'),
  label_plural: new TranslatableMarkup('work product definitions'),
  config_prefix: 'work_product_definition',
  admin_permission: 'administer unified process',
  handlers: [
    'access' => ProcessConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => WorkProductDefinitionForm::class,
      'edit' => WorkProductDefinitionForm::class,
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
  ],
  links: [
    'add-form' => '/admin/divengine/process/work-product-definitions/add',
    'edit-form' => '/admin/divengine/process/work-product-definitions/{work_product_definition}',
    'delete-form' => '/admin/divengine/process/work-product-definitions/{work_product_definition}/delete',
    'collection' => '/admin/divengine/process/work-product-definitions',
  ],
)]
class WorkProductDefinition extends ProcessConfigEntityBase {

  /**
   * The discipline that produces it, if any.
   */
  protected string $discipline = '';

  public function getDiscipline(): string {
    return $this->discipline;
  }

  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addReferenceDependencies('process_discipline', [$this->discipline]);
    return $this;
  }

}

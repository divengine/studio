<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\ProcessConfigEntityBase;
use Drupal\divengine_process\ProcessConfigListBuilder;
use Drupal\divengine_qa\Access\QaConfigAccessControlHandler;
use Drupal\divengine_qa\Form\QaConfigForm;

/**
 * A free classification of plans, suites, cases and runs.
 */
#[ConfigEntityType(
  id: 'qa_tag',
  label: new TranslatableMarkup('Test tag'),
  label_collection: new TranslatableMarkup('Test tags'),
  label_singular: new TranslatableMarkup('test tag'),
  label_plural: new TranslatableMarkup('test tags'),
  config_prefix: 'tag',
  admin_permission: 'administer qa',
  handlers: [
    'access' => QaConfigAccessControlHandler::class,
    'list_builder' => ProcessConfigListBuilder::class,
    'form' => [
      'add' => QaConfigForm::class,
      'edit' => QaConfigForm::class,
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
    'color',
  ],
  links: [
    'add-form' => '/admin/divengine/qa/tags/add',
    'edit-form' => '/admin/divengine/qa/tags/{qa_tag}',
    'delete-form' => '/admin/divengine/qa/tags/{qa_tag}/delete',
    'collection' => '/admin/divengine/qa/tags',
  ],
)]
class QaTag extends ProcessConfigEntityBase {

  protected string $color = '#64748b';

  public function getColor(): string {
    return $this->color;
  }

}

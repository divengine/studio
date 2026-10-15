<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_modeler\DiagramTypeListBuilder;
use Drupal\divengine_modeler\Form\DiagramTypeForm;
use Drupal\divengine_modeler\ModelerNotationInterface;

/**
 * A diagram type: a bundle of diagrams bound to one notation plugin.
 */
#[ConfigEntityType(
  id: 'diagram_type',
  label: new TranslatableMarkup('Diagram type'),
  label_collection: new TranslatableMarkup('Diagram types'),
  label_singular: new TranslatableMarkup('diagram type'),
  label_plural: new TranslatableMarkup('diagram types'),
  config_prefix: 'diagram_type',
  admin_permission: 'administer diagram types',
  bundle_of: 'diagram',
  handlers: [
    'list_builder' => DiagramTypeListBuilder::class,
    'form' => [
      'add' => DiagramTypeForm::class,
      'edit' => DiagramTypeForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'notation',
    'new_revision',
  ],
  links: [
    'add-form' => '/admin/divengine/diagram-types/add',
    'edit-form' => '/admin/divengine/diagram-types/{diagram_type}',
    'delete-form' => '/admin/divengine/diagram-types/{diagram_type}/delete',
    'collection' => '/admin/divengine/diagram-types',
  ],
)]
class DiagramType extends ConfigEntityBundleBase implements RevisionableEntityBundleInterface {

  protected $id;

  protected $label;

  protected string $description = '';

  /**
   * The ModelerNotation plugin ID.
   */
  protected string $notation = '';

  protected bool $new_revision = TRUE;

  public function getDescription(): string {
    return $this->description;
  }

  public function getNotationId(): string {
    return $this->notation;
  }

  public function getNotation(): ModelerNotationInterface {
    return \Drupal::service('plugin.manager.modeler_notation')->createInstance($this->notation);
  }

  /**
   * {@inheritdoc}
   */
  public function shouldCreateNewRevision(): bool {
    return $this->new_revision;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $manager = \Drupal::service('plugin.manager.modeler_notation');
    if ($this->notation !== '' && $manager->hasDefinition($this->notation)) {
      $this->addDependency('module', $manager->getDefinition($this->notation)['provider']);
    }
    return $this;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\BundlePermissionHandlerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_modeler\Entity\DiagramType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides per-diagram-type permissions.
 */
class DiagramPermissions implements ContainerInjectionInterface {

  use BundlePermissionHandlerTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Returns the permissions of every diagram type.
   */
  public function diagramTypePermissions(): array {
    return $this->generatePermissions(
      $this->entityTypeManager->getStorage('diagram_type')->loadMultiple(),
      [$this, 'buildPermissions'],
    );
  }

  protected function buildPermissions(DiagramType $type): array {
    $id = $type->id();
    $params = ['%type' => $type->label()];
    return [
      "create $id diagram" => ['title' => $this->t('%type: Create diagrams', $params)],
      "edit own $id diagram" => ['title' => $this->t('%type: Edit own diagrams', $params)],
      "edit any $id diagram" => ['title' => $this->t('%type: Edit any diagram', $params)],
      "delete own $id diagram" => ['title' => $this->t('%type: Delete own diagrams', $params)],
      "delete any $id diagram" => ['title' => $this->t('%type: Delete any diagram', $params)],
    ];
  }

}

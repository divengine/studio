<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\BundlePermissionHandlerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_artifact\Entity\ArtifactType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides per-artifact-type permissions.
 */
class ArtifactPermissions implements ContainerInjectionInterface {

  use BundlePermissionHandlerTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Returns the permissions of every artifact type.
   */
  public function artifactTypePermissions(): array {
    return $this->generatePermissions(
      $this->entityTypeManager->getStorage('artifact_type')->loadMultiple(),
      [$this, 'buildPermissions'],
    );
  }

  protected function buildPermissions(ArtifactType $type): array {
    $id = $type->id();
    $params = ['%type' => $type->label()];
    return [
      "create $id artifact" => ['title' => $this->t('%type: Create artifacts', $params)],
      "edit $id artifact" => ['title' => $this->t('%type: Edit artifacts', $params)],
      "delete $id artifact" => ['title' => $this->t('%type: Delete artifacts', $params)],
      "view $id artifact revisions" => ['title' => $this->t('%type: View revisions', $params)],
      "revert $id artifact revisions" => ['title' => $this->t('%type: Revert revisions', $params)],
      "delete $id artifact revisions" => ['title' => $this->t('%type: Delete revisions', $params)],
    ];
  }

}

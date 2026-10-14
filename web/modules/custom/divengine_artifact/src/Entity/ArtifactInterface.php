<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

interface ArtifactInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, RevisionLogInterface {

  public function getTitle(): string;

  public function setTitle(string $title): static;

  public function getArtifactKey(): string;

  public function setArtifactKey(string $key): static;

}

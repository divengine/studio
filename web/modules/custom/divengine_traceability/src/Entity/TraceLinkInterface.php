<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

interface TraceLinkInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, RevisionLogInterface {

  public function getSourceEntityType(): string;

  public function getSourceEntityId(): int;

  public function getTargetEntityType(): string;

  public function getTargetEntityId(): int;

  public function getLinkType(): ?TraceLinkType;

  public function getSourceEntity(): ?EntityInterface;

  public function getTargetEntity(): ?EntityInterface;

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

interface TraceLinkInterface extends ContentEntityInterface, EntityChangedInterface {

  public function getSourceEntityType(): string;

  public function getSourceEntityId(): int;

  public function getTargetEntityType(): string;

  public function getTargetEntityId(): int;

}

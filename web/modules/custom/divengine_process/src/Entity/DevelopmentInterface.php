<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * One governed software development effort.
 */
interface DevelopmentInterface extends StatefulInterface, EntityChangedInterface, EntityOwnerInterface, RevisionLogInterface {

  public function getCode(): string;

  /**
   * Whether a user is its creator, its Responsible or a member.
   */
  public function isVisibleTo(int $uid): bool;

  public function getCase(): ?DevelopmentCase;

  /**
   * The Development Case version the snapshot was taken from.
   */
  public function getCaseVersion(): ?int;

  /**
   * Whether the case changed after it was applied.
   */
  public function isCaseOutdated(): bool;

  /**
   * The snapshot of the case lines.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getPolicy(): array;

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Storage;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/** Native artifact SQL storage with baseline retention checks. */
class BaselineArtifactStorage extends SqlContentEntityStorage {

  use BaselineRetentionStorageTrait;

}

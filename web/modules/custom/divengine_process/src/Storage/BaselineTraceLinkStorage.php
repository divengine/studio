<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Storage;

use Drupal\divengine_traceability\TraceLinkStorage;

/** Preserve Traceability's append policy and add Process baseline retention. */
class BaselineTraceLinkStorage extends TraceLinkStorage {

  use BaselineRetentionStorageTrait;

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

/**
 * Access to lightweight Development records, such as role assignments.
 *
 * Whoever edits the Development may also delete them.
 */
class LightweightScopedAccessControlHandler extends ScopedAccessControlHandler {

  protected bool $usersDelete = TRUE;

}

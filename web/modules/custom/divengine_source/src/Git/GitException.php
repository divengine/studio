<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Git;

/**
 * A Git call failed, timed out or returned something unexpected. The
 * message is safe to show.
 */
class GitException extends \RuntimeException {}

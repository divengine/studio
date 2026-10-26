<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

/**
 * A link that cannot be registered as an external reference.
 *
 * Its message is safe to show to the person who entered the link.
 */
class InvalidExternalUrlException extends \InvalidArgumentException {}

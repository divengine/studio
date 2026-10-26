<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

/**
 * A reference or trace link that the writer may not store.
 *
 * Its message is safe to show to that writer: it names nothing they cannot
 * see.
 */
class ExternalReferenceException extends \RuntimeException {}

<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write;

/**
 * A write the client may not make. Its message is safe to send back: it
 * names nothing the person cannot see.
 */
class EngineeringWriteException extends \RuntimeException {}

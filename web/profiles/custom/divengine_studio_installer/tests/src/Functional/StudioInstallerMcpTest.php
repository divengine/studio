<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_studio_installer\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Web installation with the MCP integration selected.
 */
#[Group('divengine_studio_installer')]
#[RunTestsInSeparateProcesses]
class StudioInstallerMcpTest extends StudioInstallerTestBase {

  protected bool $selectMcp = TRUE;

}

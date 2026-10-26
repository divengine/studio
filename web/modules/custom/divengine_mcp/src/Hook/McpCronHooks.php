<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp\Hook;

use Drupal\Core\Extension\ProceduralCall;
use Drupal\Core\Hook\Attribute\RemoveHook;

/**
 * Drops the MCP module's cron hook, which calls a service that no longer exists.
 *
 * drupal/mcp removed its SSE transport and the "mcp.client_message.storage"
 * service that buffered SSE messages (upstream commit 42ef1c5, before 1.2.x),
 * but kept mcp_cron(), which only asks that service to clean up. Every cron
 * run therefore logs a ServiceNotFoundException. The HTTP transport Studio
 * uses stores no client messages, so there is nothing for cron to clean.
 *
 * Remove this class once drupal/mcp drops or fixes mcp_cron().
 */
#[RemoveHook('cron', class: ProceduralCall::class, method: 'mcp_cron')]
class McpCronHooks {

}

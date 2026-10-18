<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Plugin\Mcp;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_codedocs\Controller\CodeDocsApiController;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Symfony\Component\HttpFoundation\Request;

/** MCP reuses the HTTP read service and its entity access checks. */
#[Mcp(
  id: 'codedocs',
  name: new TranslatableMarkup('Divengine Code Docs'),
  description: new TranslatableMarkup('Browse code documentation, symbols and snapshot changes across languages.'),
)]
class CodeDocs extends McpPluginBase {

  public function getTools(): array {
    $properties = ['project' => ['type' => 'string', 'description' => 'Documentation set identifier.'], 'snapshot' => ['type' => 'integer', 'description' => 'Snapshot ID; defaults to latest.'], 'q' => ['type' => 'string'], 'symbol' => ['type' => 'integer'], 'from' => ['type' => 'integer'], 'offset' => ['type' => 'integer']];
    $tools = [];
    foreach (['sets' => 'List visible code documentation sets. Start here.', 'snapshots' => 'List ready snapshots of a project.', 'units' => 'List packages, modules and files (200 per page).', 'symbols' => 'Search documented symbols (100 per page).', 'symbol' => 'Describe a symbol with parameters, tags, members and references.', 'endpoints' => 'List routes and HTTP calls extracted from code (200 per page).', 'diff' => 'Compare a project snapshot with the snapshot given by from.'] as $name => $description) {
      $required = $name === 'sets' ? [] : ['project'];
      if ($name === 'symbol') { $required[] = 'symbol'; }
      if ($name === 'diff') { $required[] = 'from'; }
      $tools[] = new Tool(name: $name, description: $description, inputSchema: ['type' => 'object', 'properties' => $properties, 'required' => $required], title: ucfirst($name));
    }
    return $tools;
  }

  public function executeTool(string $toolId, mixed $arguments): array {
    $names = ['sets', 'snapshots', 'units', 'symbols', 'symbol', 'endpoints', 'diff'];
    foreach ($names as $name) {
      if ($toolId === $name || $toolId === md5($name)) {
        $response = (new CodeDocsApiController())->read(Request::create('/', 'GET', (array) $arguments), $name);
        return [['type' => 'text', 'text' => $response->getContent()]];
      }
    }
    return [['type' => 'text', 'text' => 'Unknown documentation tool.']];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Plugin\Mcp;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_source\Entity\SourceRef;
use Drupal\divengine_source\Entity\SourceRepository;
use Drupal\divengine_source\Git\GitException;
use Drupal\divengine_source\Git\GitRepository;
use Drupal\divengine_source\SourceIndex;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Code of the Developments for agents: repositories, trees, files, search.
 *
 * Every answer is limited to what the connected account may see. Files are
 * read from Git when asked, at the commit each ref was last indexed.
 */
#[Mcp(
  id: 'code',
  name: new TranslatableMarkup('Divengine Code Source'),
  description: new TranslatableMarkup('Lets agents list the repositories of a Development, browse their indexed refs, find, read and search source files.'),
)]
class SourceCode extends McpPluginBase implements ContainerFactoryPluginInterface {

  const MAX_LINES = 2000;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected SourceIndex $index;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->index = $container->get('divengine_source.index');
    return $instance;
  }

  public function getTools(): array {
    $repository = ['type' => 'string', 'description' => 'Repository code (e.g. SRC-0001) or name.'];
    $ref = ['type' => 'string', 'description' => 'Git ref name (e.g. main). Defaults to the repository\'s default ref.'];
    return [
      new Tool(
        name: 'repositories',
        description: 'Lists the Git repositories you can see, optionally of one Development (code like DEV-0001), with their refs, indexed commits and file counts. Start here to find code.',
        inputSchema: ['type' => 'object', 'properties' => ['development' => ['type' => 'string', 'description' => 'Development code, e.g. DEV-0001 (optional).']]],
        title: 'Repositories',
      ),
      new Tool(
        name: 'tree',
        description: 'Lists the folders and files of a folder of an indexed ref (the root when path is empty).',
        inputSchema: ['type' => 'object', 'properties' => ['repository' => $repository, 'ref' => $ref, 'path' => ['type' => 'string', 'description' => 'Folder path, e.g. src/Controller.']], 'required' => ['repository']],
        title: 'Folder tree',
      ),
      new Tool(
        name: 'find_files',
        description: 'Finds files whose path contains a text (case-insensitive), e.g. "Controller" or ".install".',
        inputSchema: ['type' => 'object', 'properties' => ['repository' => $repository, 'ref' => $ref, 'text' => ['type' => 'string']], 'required' => ['repository', 'text']],
        title: 'Find files',
      ),
      new Tool(
        name: 'read',
        description: 'Reads a text file of an indexed ref, with line numbers. Use start_line/end_line for long files (at most 2000 lines per call).',
        inputSchema: ['type' => 'object', 'properties' => [
          'repository' => $repository,
          'ref' => $ref,
          'path' => ['type' => 'string', 'description' => 'File path from the repository root.'],
          'start_line' => ['type' => 'integer', 'minimum' => 1],
          'end_line' => ['type' => 'integer', 'minimum' => 1],
        ], 'required' => ['repository', 'path']],
        title: 'Read file',
      ),
      new Tool(
        name: 'search',
        description: 'Searches the text of the files of an indexed ref (fixed string, case-insensitive), optionally below a path. Returns path:line: text.',
        inputSchema: ['type' => 'object', 'properties' => ['repository' => $repository, 'ref' => $ref, 'text' => ['type' => 'string'], 'path' => ['type' => 'string']], 'required' => ['repository', 'text']],
        title: 'Search code',
      ),
    ];
  }

  public function executeTool(string $toolId, mixed $arguments): array {
    $arguments = (array) $arguments;
    try {
      $result = match ($toolId) {
        'repositories', md5('repositories') => $this->repositories($arguments),
        'tree', md5('tree') => $this->tree($arguments),
        'find_files', md5('find_files') => $this->findFiles($arguments),
        'read', md5('read') => $this->read($arguments),
        'search', md5('search') => $this->search($arguments),
        default => throw new \InvalidArgumentException('Unknown tool.'),
      };
    }
    catch (GitException | \InvalidArgumentException $e) {
      $result = 'Error: ' . $e->getMessage();
    }
    return [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]];
  }

  protected function repositories(array $arguments): array {
    $storage = $this->entityTypeManager->getStorage('source_repository');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('name');
    if (!empty($arguments['development'])) {
      $developments = $this->entityTypeManager->getStorage('development')->getQuery()->accessCheck(TRUE)->condition('code', mb_strtoupper(trim($arguments['development'])))->execute();
      if (!$developments) {
        throw new \InvalidArgumentException('No Development with that code is visible to you.');
      }
      $query->condition('development', reset($developments));
    }
    $result = [];
    foreach ($storage->loadMultiple($query->execute()) as $repository) {
      $refs = [];
      foreach ($this->entityTypeManager->getStorage('source_ref')->loadByProperties(['repository' => $repository->id(), 'status' => 1]) as $ref) {
        $snapshot = $ref->getLastSnapshot();
        $refs[] = [
          'ref' => $ref->get('name')->value,
          'default' => (bool) $ref->get('is_default')->value,
          'commit' => $snapshot?->getCommit(),
          'files' => $snapshot ? $this->index->count($ref) : 0,
        ];
      }
      $result[] = [
        'code' => $repository->get('code')->value,
        'name' => $repository->get('name')->value,
        'development' => $repository->get('development')->entity?->label(),
        'description' => $repository->get('description')->value,
        'refs' => $refs,
      ];
    }
    return $result;
  }

  protected function tree(array $arguments): array {
    $ref = $this->ref($arguments);
    $path = trim((string) ($arguments['path'] ?? ''), '/');
    $listing = $this->index->directory($ref, $path);
    return [
      'ref' => $ref->get('name')->value,
      'commit' => $ref->getLastSnapshot()->getCommit(),
      'path' => $path,
      'folders' => array_map(fn ($name, $count) => ['name' => $name, 'files' => $count], array_keys($listing['folders']), $listing['folders']),
      'files' => array_map(fn ($file) => ['path' => $file->path, 'size' => (int) $file->size], $listing['files']),
    ];
  }

  protected function findFiles(array $arguments): array {
    $ref = $this->ref($arguments);
    $text = trim((string) ($arguments['text'] ?? ''));
    if ($text === '') {
      throw new \InvalidArgumentException('Give a text to look for in file paths.');
    }
    return array_map(fn ($file) => ['path' => $file->path, 'size' => (int) $file->size], $this->index->findFiles($ref, $text, 200));
  }

  protected function read(array $arguments): string {
    $ref = $this->ref($arguments);
    $file = $this->index->file($ref, (string) ($arguments['path'] ?? ''));
    if (!$file) {
      throw new \InvalidArgumentException('No such file in this ref. Use code_tree or code_find_files.');
    }
    if ($file->entry_type !== 'blob') {
      return sprintf('%s is a %s, not a file.', $file->path, $file->entry_type);
    }
    $content = $this->index->read($ref, $file);
    if (GitRepository::isBinary($content)) {
      return sprintf('%s is a binary file (%d bytes).', $file->path, strlen($content));
    }
    $lines = preg_split('/\R/', $content);
    $total = count($lines);
    $start = max(1, (int) ($arguments['start_line'] ?? 1));
    $end = min($total, (int) ($arguments['end_line'] ?? ($start + static::MAX_LINES - 1)), $start + static::MAX_LINES - 1);
    $out = [];
    for ($i = $start; $i <= $end; $i++) {
      $out[] = sprintf('%5d  %s', $i, $lines[$i - 1]);
    }
    $header = sprintf('%s @ %s (%s), lines %d-%d of %d', $file->path, $ref->get('name')->value, substr($ref->getLastSnapshot()->getCommit(), 0, 12), $start, $end, $total);
    return $header . "\n" . implode("\n", $out) . ($end < $total ? "\n… more lines: call again with start_line=" . ($end + 1) : '');
  }

  protected function search(array $arguments): array {
    $ref = $this->ref($arguments);
    return $this->index->grep($ref, trim((string) ($arguments['text'] ?? '')), (string) ($arguments['path'] ?? ''), 200);
  }

  /**
   * The ref of a call: the repository by code or name, the ref by name or
   * the default one. Only indexed refs the account may see.
   */
  protected function ref(array $arguments): SourceRef {
    $key = trim((string) ($arguments['repository'] ?? ''));
    $storage = $this->entityTypeManager->getStorage('source_repository');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $query->condition($query->orConditionGroup()->condition('code', mb_strtoupper($key))->condition('name', $key));
    $ids = $query->execute();
    $repository = $ids ? $storage->load(reset($ids)) : NULL;
    if (!$repository instanceof SourceRepository || !$repository->access('view')) {
      throw new \InvalidArgumentException('No repository with that code or name is visible to you. Use code_repositories.');
    }
    if (!empty($arguments['ref'])) {
      $refs = $this->entityTypeManager->getStorage('source_ref')->loadByProperties(['repository' => $repository->id(), 'name' => trim($arguments['ref'])]);
      $ref = reset($refs) ?: NULL;
    }
    else {
      $ref = $repository->getDefaultRef();
    }
    if (!$ref instanceof SourceRef || !$ref->getLastSnapshot()) {
      throw new \InvalidArgumentException('That ref is not registered or not indexed yet.');
    }
    return $ref;
  }

}

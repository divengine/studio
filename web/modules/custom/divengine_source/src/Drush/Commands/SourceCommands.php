<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_source\Git\GitException;
use Drupal\divengine_source\SourceIndex;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Indexes refs from the command line (for cron or after a git pull).
 */
final class SourceCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'divengine_source.index')]
    protected SourceIndex $index,
  ) {
    parent::__construct();
  }

  /**
   * Indexes the active refs of one repository or of all of them.
   */
  #[CLI\Command(name: 'divengine:source-index', aliases: ['dsi'])]
  #[CLI\Argument(name: 'repository', description: 'Repository code (SRC-0001); all repositories when omitted.')]
  #[CLI\Usage(name: 'drush divengine:source-index SRC-0001', description: 'Index the refs of one repository.')]
  public function index(?string $repository = NULL): void {
    $repositories = $this->entityTypeManager->getStorage('source_repository');
    $ids = $repositories->getQuery()->accessCheck(FALSE);
    if ($repository) {
      $ids->condition('code', mb_strtoupper($repository));
    }
    foreach ($repositories->loadMultiple($ids->execute()) as $entity) {
      foreach ($this->entityTypeManager->getStorage('source_ref')->loadByProperties(['repository' => $entity->id(), 'status' => 1]) as $ref) {
        try {
          [$snapshot, $outcome] = $this->index->index($ref);
          $this->logger()->success(sprintf('%s %s @ %s: %s (%d files)', $entity->get('code')->value, $ref->get('name')->value, $snapshot->label(), $outcome, $snapshot->get('file_count')->value));
        }
        catch (GitException $e) {
          $this->logger()->error(sprintf('%s %s: %s', $entity->get('code')->value, $ref->get('name')->value, $e->getMessage()));
        }
      }
    }
  }

}

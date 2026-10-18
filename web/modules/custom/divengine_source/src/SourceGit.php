<?php

declare(strict_types=1);

namespace Drupal\divengine_source;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\divengine_source\Entity\SourceRepository;
use Drupal\divengine_source\Git\GitRepository;
use Drupal\divengine_source\Git\Roots;

/**
 * The settings of Code Source and Git readers on allowed repositories.
 */
class SourceGit {

  const MIN_VIEWER_BYTES = 1024;

  const MAX_VIEWER_BYTES = 10 * 1024 * 1024;

  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  /**
   * @return string[]
   */
  public function roots(): array {
    return Roots::parse((string) $this->configFactory->get('divengine_source.settings')->get('allowed_roots'));
  }

  /**
   * The viewer limit, clamped to a sane range whatever the setting says.
   */
  public function maxBytes(): int {
    $value = (int) ($this->configFactory->get('divengine_source.settings')->get('viewer_max_bytes') ?: 524288);
    return min(max($value, static::MIN_VIEWER_BYTES), static::MAX_VIEWER_BYTES);
  }

  /**
   * A Git reader on a repository, after checking its path again.
   *
   * @throws \Drupal\divengine_source\Git\GitException
   */
  public function repository(SourceRepository $repository): GitRepository {
    $path = Roots::resolve((string) $repository->get('path')->value, $this->roots());
    return new GitRepository($path, (string) ($this->configFactory->get('divengine_source.settings')->get('git_binary') ?: 'git'));
  }

}

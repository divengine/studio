<?php

declare(strict_types=1);

namespace Drupal\divengine_thread;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Renders the attachments and the discussion of a record.
 */
class ThreadView {

  use StringTranslationTrait;

  public function __construct(
    protected ThreadManager $manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public function build(ContentEntityInterface $entity, string $field): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-thread']],
      '#attached' => ['library' => ['divengine_thread/thread']],
      '#cache' => ['tags' => ['comment_list'], 'contexts' => ['user.permissions']],
    ];
    $build['attachments'] = $this->attachments($entity, $field);
    $build['discussion'] = $entity->get($field)->view([
      'label' => 'above',
      'type' => 'comment_default',
      'settings' => ['view_mode' => 'default', 'pager_id' => 0],
    ]);
    return $build;
  }

  /**
   * The files posted in the thread, with who and when.
   *
   * Only the files the viewer may download are listed. Core's download
   * access result carries no cache context although it depends on who asks
   * (private files follow the entities that use them), so the list varies
   * by user.
   */
  protected function attachments(ContentEntityInterface $entity, string $field): array {
    $rows = $this->manager->attachments($entity, $field);
    if (!$rows) {
      return [];
    }
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple(array_column($rows, 'fid'));
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_unique(array_column($rows, 'uid')));
    $cacheability = (new CacheableMetadata())->addCacheContexts(['user']);
    $items = [];
    foreach ($rows as $row) {
      $file = $files[$row['fid']] ?? NULL;
      if (!$file) {
        continue;
      }
      $access = $file->access('download', NULL, TRUE);
      $cacheability->addCacheableDependency($file)->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-attachment']],
        'icon' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->icon((string) $file->getMimeType()), '#attributes' => ['class' => ['dv-attachment__icon'], 'aria-hidden' => 'true']],
        'link' => [
          '#type' => 'link',
          '#title' => $file->getFilename(),
          '#url' => Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($file->getFileUri())),
          '#attributes' => ['class' => ['dv-attachment__name']],
        ],
        'meta' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => ByteSizeMarkup::create((int) $file->getSize()) . ' · ' . ($users[$row['uid']]?->getDisplayName() ?? '') . ' · ' . $this->dateFormatter->format($row['created'], 'short'),
          '#attributes' => ['class' => ['dv-attachment__meta']],
        ],
      ];
    }
    $build = [];
    if ($items) {
      $build = [
        '#type' => 'component',
        '#component' => 'divengine_core:section',
        '#props' => ['title' => (string) $this->t('Attachments'), 'count' => (string) count($items)],
        '#slots' => ['content' => ['#type' => 'container', '#attributes' => ['class' => ['dv-attachments']], 'items' => $items]],
      ];
    }
    $cacheability->applyTo($build);
    return $build;
  }

  protected function icon(string $mime): string {
    return match (TRUE) {
      str_starts_with($mime, 'image/') => '🖼',
      str_starts_with($mime, 'video/') => '🎞',
      $mime === 'application/pdf' => '📕',
      str_contains($mime, 'zip') => '🗜',
      str_contains($mime, 'sheet') || str_contains($mime, 'csv') => '📊',
      default => '📄',
    };
  }

}

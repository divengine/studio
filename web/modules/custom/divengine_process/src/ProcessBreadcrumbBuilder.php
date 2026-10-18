<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Breadcrumbs of records that belong to a Development.
 *
 * Home › Developments › the Development › the tab that lists the record.
 */
class ProcessBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * The Development tab that lists each kind of record.
   */
  const TABS = [
    'process_activity' => 'divengine_process.development_process',
    'change_request' => 'divengine_process.development_changes',
    'baseline' => 'divengine_process.development_baselines',
  ];

  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    return $this->record($route_match) !== NULL;
  }

  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $record = $this->record($route_match);
    $development = $record->get('development')->entity;
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $breadcrumb->addCacheableDependency($record);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $breadcrumb->addLink(Link::createFromRoute($this->t('Developments'), 'entity.development.collection'));
    if ($development && $development->access('view')) {
      $breadcrumb->addCacheableDependency($development);
      $breadcrumb->addLink($development->toLink());
      if ($tab = $this->tab($record)) {
        $breadcrumb->addLink(Link::fromTextAndUrl($tab[1], Url::fromRoute($tab[0], ['development' => $development->id()])));
      }
    }
    return $breadcrumb;
  }

  /**
   * The record of a Development the route shows, if any.
   */
  protected function record(RouteMatchInterface $route_match): ?FieldableEntityInterface {
    foreach ($route_match->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface && $parameter->getEntityTypeId() !== 'development'
        && $parameter->hasField('development') && !$parameter->get('development')->isEmpty()) {
        return $parameter;
      }
    }
    return NULL;
  }

  /**
   * The route and label of the tab that lists a record.
   *
   * @return array{0: string, 1: \Drupal\Core\StringTranslation\TranslatableMarkup}|null
   */
  protected function tab(FieldableEntityInterface $record): ?array {
    if ($record->getEntityTypeId() === 'artifact') {
      return match (Vocabulary::scopedArtifactTypes()[$record->bundle()] ?? NULL) {
        'business' => ['divengine_process.development_business', $this->t('Business model')],
        'requirements' => ['divengine_process.development_requirements', $this->t('Requirements')],
        'process' => ['divengine_process.development_process', $this->t('Process')],
        default => NULL,
      };
    }
    return match ($record->getEntityTypeId()) {
      'process_activity' => [static::TABS['process_activity'], $this->t('Process')],
      'change_request' => [static::TABS['change_request'], $this->t('Changes')],
      'baseline' => [static::TABS['baseline'], $this->t('Baselines')],
      default => NULL,
    };
  }

}

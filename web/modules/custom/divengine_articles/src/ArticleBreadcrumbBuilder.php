<?php

declare(strict_types=1);

namespace Drupal\divengine_articles;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Home › Knowledge (or the Development's knowledge) › parent articles.
 */
class ArticleBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    $node = $route_match->getParameter('node');
    return $node instanceof NodeInterface && $node->bundle() === 'knowledge_article';
  }

  public function build(RouteMatchInterface $route_match): Breadcrumb {
    /** @var \Drupal\node\NodeInterface $article */
    $article = $route_match->getParameter('node');
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route', 'user']);
    $breadcrumb->addCacheTags(['node_list:knowledge_article']);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $development = $article->get('kb_development')->entity;
    if ($development && $development->access('view')) {
      $breadcrumb->addLink($development->toLink());
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Knowledge'), Url::fromRoute('divengine_articles.development', ['development' => $development->id()])));
    }
    else {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Knowledge'), 'divengine_articles.index'));
    }
    foreach (\Drupal::service('divengine_articles.tree')->ancestors($article) as $ancestor) {
      if ($ancestor->access('view')) {
        $breadcrumb->addLink($ancestor->toLink());
      }
    }
    if ($route_match->getRouteName() !== 'entity.node.canonical') {
      $breadcrumb->addLink($article->toLink());
    }
    return $breadcrumb;
  }

}

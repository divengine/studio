<?php

declare(strict_types=1);

namespace Drupal\divengine_articles;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The sections around an article: header, sub-articles, navigation.
 */
class ArticleView implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected ArticleTree $tree,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_articles.tree'), $container->get('date.formatter'));
  }

  public function build(NodeInterface $article): array {
    $build = [
      '#attached' => ['library' => ['divengine_articles/articles']],
      '#cache' => ['tags' => ['node_list:knowledge_article']],
    ];
    $actions = [];
    if ($article->access('update')) {
      $actions[] = $this->button($this->t('Edit'), $article->toUrl('edit-form'), TRUE);
    }
    if (\Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess('knowledge_article')) {
      $actions[] = $this->button($this->t('Add sub-article'), Url::fromRoute('node.add', ['node_type' => 'knowledge_article'], ['query' => ['parent' => $article->id()]]));
    }
    if ($article->access('view all revisions')) {
      $actions[] = $this->button($this->t('History'), $article->toUrl('version-history'));
    }
    $facts = array_filter([
      ['label' => (string) $this->t('Development'), 'value' => (string) $article->get('kb_development')->entity?->label()],
      ['label' => (string) $this->t('Topics'), 'value' => implode(', ', array_map(fn ($item) => (string) $item->entity?->label(), iterator_to_array($article->get('kb_topics'))))],
      ['label' => (string) $this->t('Updated'), 'value' => $this->dateFormatter->format($article->getChangedTime(), 'short') . ' · ' . $article->getRevisionUser()?->getDisplayName()],
    ], fn ($fact) => $fact['value'] !== '');
    $build['dv_header'] = [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => ['code' => (string) $article->get('kb_code')->value, 'facts' => array_values($facts)],
      '#slots' => array_filter([
        'badge' => ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => $article->isPublished() ? (string) $this->t('Published') : (string) $this->t('Draft'), 'tone' => $article->isPublished() ? 'success' : 'neutral']],
        'actions' => $actions ? ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'actions' => $actions] : NULL,
      ]),
      '#weight' => -100,
    ];

    $children = $this->tree->children($article);
    if ($children) {
      $items = [];
      foreach ($children as $child) {
        $summary = trim(strip_tags((string) ($child->get('body')->summary ?: $child->get('body')->value)));
        // [[CODE|text]] reads as text, [[CODE]] as the code.
        $summary = preg_replace('/\[\[([^\]|]+)\|([^\]]+)\]\]/', '$2', $summary);
        $summary = preg_replace('/\[\[([^\]]+)\]\]/', '$1', $summary);
        $items[] = array_filter([
          '#type' => 'container',
          '#attributes' => ['class' => ['dv-kb-card']],
          'head' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['dv-kb-card__head']],
            'title' => Link::fromTextAndUrl($child->label(), $child->toUrl())->toRenderable() + ['#attributes' => ['class' => ['dv-kb-card__title']]],
            'code' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => (string) $child->get('kb_code')->value, '#attributes' => ['class' => ['dv-kb-card__code']]],
          ],
          'summary' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => mb_strimwidth($summary, 0, 180, '…')],
          'count' => ($n = count($this->tree->children($child))) ? ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->formatPlural($n, '1 sub-article', '@count sub-articles'), '#attributes' => ['class' => ['dv-kb-card__count']]] : NULL,
        ], fn ($value) => $value !== NULL);
      }
      $build['dv_children'] = [
        '#type' => 'component',
        '#component' => 'divengine_core:section',
        '#props' => ['title' => (string) $this->t('In this section'), 'count' => (string) count($children)],
        '#slots' => ['content' => ['#type' => 'container', '#attributes' => ['class' => ['dv-kb-cards']], 'items' => $items]],
        '#weight' => 40,
      ];
    }

    // Previous and next among the siblings, for reading straight through.
    $siblings = array_values($this->tree->articles($article->get('kb_parent')->isEmpty()
      ? ['kb_parent' => NULL, 'kb_development' => $article->get('kb_development')->target_id]
      : ['kb_parent' => $article->get('kb_parent')->target_id]));
    $position = array_search((int) $article->id(), array_map(fn ($a) => (int) $a->id(), $siblings), TRUE);
    if ($position !== FALSE && count($siblings) > 1) {
      $build['dv_pager'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-kb-pager']],
        'previous' => $position > 0 ? Link::fromTextAndUrl('← ' . $siblings[$position - 1]->label(), $siblings[$position - 1]->toUrl())->toRenderable() + ['#attributes' => ['class' => ['dv-kb-pager__prev']]] : [],
        'next' => $position < count($siblings) - 1 ? Link::fromTextAndUrl($siblings[$position + 1]->label() . ' →', $siblings[$position + 1]->toUrl())->toRenderable() + ['#attributes' => ['class' => ['dv-kb-pager__next']]] : [],
        '#weight' => 45,
      ];
    }
    return $build;
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

}

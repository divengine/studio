<?php

declare(strict_types=1);

namespace Drupal\divengine_articles\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_articles\ArticleTree;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The knowledge base: every article tree, and the documentation tab of a
 * Development.
 */
class KnowledgeController extends ControllerBase {

  protected ArticleTree $tree;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->tree = $container->get('divengine_articles.tree');
    return $instance;
  }

  public function index(Request $request): array {
    $build = $this->page();
    $search = trim((string) $request->query->get('search', ''));
    $build['search'] = [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => ['method' => 'get', 'class' => ['dv-kb-search'], 'role' => 'search'],
      'input' => ['#type' => 'html_tag', '#tag' => 'input', '#attributes' => ['type' => 'search', 'name' => 'search', 'value' => $search, 'placeholder' => (string) $this->t('Search articles by title or code'), 'aria-label' => (string) $this->t('Search articles'), 'class' => ['form-text']]],
      'submit' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Search'), '#attributes' => ['type' => 'submit', 'class' => ['button']]],
    ];
    $build['#cache']['contexts'][] = 'url.query_args:search';
    if ($search !== '') {
      $found = array_filter($this->tree->articles(), fn ($a) => mb_stripos($a->label() . ' ' . $a->get('kb_code')->value, $search) !== FALSE);
      $items = array_map(fn ($a) => ['#markup' => Link::fromTextAndUrl($a->label(), $a->toUrl())->toString() . ' <small>' . $a->get('kb_code')->value . ($a->get('kb_development')->entity ? ' · ' . $a->get('kb_development')->entity->label() : '') . '</small>'], $found);
      $build['results'] = $this->section($this->t('Results'), count($items), $items ? ['#theme' => 'item_list', '#items' => array_values($items)] : ['#markup' => '<p>' . $this->t('No article matches.') . '</p>']);
      return $build;
    }
    $general = $this->tree->articles(['kb_parent' => NULL, 'kb_development' => NULL]);
    $build['general'] = $this->section($this->t('General knowledge'), count($general), $general ? $this->tree->tree($general) : ['#markup' => '<p>' . $this->t('Knowledge everyone can read: guides, conventions, how the team works.') . '</p>'], $this->addButton());
    $developments = $this->entityTypeManager->getStorage('development');
    foreach ($developments->loadMultiple($developments->getQuery()->accessCheck(TRUE)->sort('name')->execute()) as $development) {
      $roots = $this->tree->articles(['kb_parent' => NULL, 'kb_development' => $development->id()]);
      if ($roots) {
        $build['development_' . $development->id()] = $this->section($development->label(), count($roots), $this->tree->tree($roots), $this->addButton(['development' => $development->id()]));
      }
    }
    return $build;
  }

  public function development(DevelopmentInterface $development): array {
    $build = $this->page();
    $build['intro'] = ['#markup' => '<p class="dv-intro">' . $this->t('The documentation of this Development: only its members can read it. Write <code>[[UC-0002]]</code> or any other code to link a record; embed diagrams with the Diagram button.') . '</p>'];
    $roots = $this->tree->articles(['kb_parent' => NULL, 'kb_development' => $development->id()]);
    $build['articles'] = $this->section($this->t('Articles'), count($roots), $roots ? $this->tree->tree($roots) : ['#markup' => '<p>' . $this->t('No article yet. Start with an overview of the Development.') . '</p>'], $this->addButton(['development' => $development->id()]));
    return $build;
  }

  public function developmentTitle(DevelopmentInterface $development): string {
    return (string) $this->t('@development: knowledge', ['@development' => $development->label()]);
  }

  protected function addButton(array $query = []): ?array {
    if (!$this->entityTypeManager->getAccessControlHandler('node')->createAccess('knowledge_article')) {
      return NULL;
    }
    return Link::fromTextAndUrl($this->t('New article'), Url::fromRoute('node.add', ['node_type' => 'knowledge_article'], ['query' => $query]))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small', 'button--primary']]];
  }

  protected function page(): array {
    return [
      '#attached' => ['library' => ['divengine_articles/articles']],
      '#cache' => ['tags' => ['node_list:knowledge_article', 'development_list'], 'contexts' => ['user']],
    ];
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

}

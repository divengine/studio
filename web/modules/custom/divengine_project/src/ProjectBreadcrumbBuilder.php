<?php

declare(strict_types=1);

namespace Drupal\divengine_project;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;

/**
 * Breadcrumbs of projects and tasks.
 *
 * Home › Projects › ancestor projects › the project › parent tasks.
 */
class ProjectBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    return $this->record($route_match) !== NULL;
  }

  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $record = $this->record($route_match);
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route', 'user']);
    $breadcrumb->addCacheTags(['project_list', 'project_task_list']);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $breadcrumb->addLink(Link::createFromRoute($this->t('Projects'), 'divengine_project.projects'));

    $tasks = [];
    $project = $record instanceof Project ? $record : $record->getProject();
    if ($record instanceof ProjectTask) {
      // Parent tasks, outermost first; the task itself is the page title.
      for ($task = $record->get('parent')->entity, $seen = []; $task && !isset($seen[$task->id()]); $task = $task->get('parent')->entity) {
        $seen[$task->id()] = TRUE;
        array_unshift($tasks, $task);
      }
    }
    $projects = [];
    for ($node = $project, $seen = []; $node && !isset($seen[$node->id()]); $node = $node->get('parent')->entity) {
      $seen[$node->id()] = TRUE;
      array_unshift($projects, $node);
    }
    // On the project page itself, the project is the title.
    $is_project_page = $record instanceof Project && $route_match->getRouteName() === 'entity.project.canonical';
    if ($is_project_page) {
      array_pop($projects);
    }
    foreach (array_merge($projects, $tasks) as $entity) {
      if ($entity->access('view')) {
        $breadcrumb->addLink($entity->toLink());
      }
    }
    return $breadcrumb;
  }

  protected function record(RouteMatchInterface $route_match): Project|ProjectTask|null {
    foreach ($route_match->getParameters() as $parameter) {
      if ($parameter instanceof Project || $parameter instanceof ProjectTask) {
        return $parameter;
      }
    }
    return NULL;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\divengine_core\PageElementsTrait;
use Drupal\divengine_codedocs\CodeDocIndex;
use Drupal\divengine_codedocs\CodeDocsPages;
use Drupal\divengine_codedocs\Entity\CodeDocSnapshot;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists of documentation sets and the page of a symbol; sets and snapshots
 * are rendered by their view builder with the same pages service.
 */
class CodeDocsController extends ControllerBase {

  use PageElementsTrait;

  protected CodeDocsPages $pages;

  protected CodeDocIndex $index;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->pages = $container->get('divengine_codedocs.pages');
    $instance->index = $container->get('divengine_codedocs.index');
    return $instance;
  }

  protected function page(): array {
    return ['#attached' => ['library' => ['divengine_codedocs/docs']], '#cache' => ['tags' => ['codedoc_set_list', 'codedoc_snapshot_list']]];
  }

  public function sets(?DevelopmentInterface $development = NULL): array {
    $storage = $this->entityTypeManager()->getStorage('codedoc_set');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('name');
    if ($development) {
      $query->condition('development', $development->id());
    }
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $set) {
      if ($set->access('view')) {
        $latest = $set->getLatestSnapshot();
        $rows[] = [['data' => $set->toLink()->toRenderable()], $set->getIdentifier(), $set->get('language')->value ?? '', ['data' => $latest ? $latest->toLink()->toRenderable() : ['#plain_text' => (string) $this->t('No snapshots yet')]]];
      }
    }
    $add = $this->entityTypeManager()->getAccessControlHandler('codedoc_set')->createAccess()
      ? $this->button($this->t('Register documentation'), Url::fromRoute('entity.codedoc_set.add_form', [], ['query' => $development ? ['development' => $development->id()] : []]), TRUE) : NULL;
    return $this->page() + ['list' => $this->section($this->t('Code docs'), count($rows), ['#type' => 'table', '#header' => [$this->t('Documentation'), $this->t('Identifier'), $this->t('Language'), $this->t('Latest snapshot')], '#rows' => $rows, '#empty' => $this->t('Register a codebase and publish its extracted documentation.')], $add)];
  }

  public function development(DevelopmentInterface $development): array {
    return $this->sets($development);
  }

  public function symbol(CodeDocSnapshot $codedoc_snapshot, int $symbol): array {
    return $this->pages->symbol($codedoc_snapshot, $symbol);
  }

  public function symbolTitle(CodeDocSnapshot $codedoc_snapshot, int $symbol): string {
    return (string) ($this->index->symbol((int) $codedoc_snapshot->id(), $symbol)['name'] ?? $this->t('Symbol'));
  }

}

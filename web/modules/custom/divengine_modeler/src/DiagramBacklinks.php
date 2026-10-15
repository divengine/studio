<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Renders "diagrams referencing this record" for any entity.
 */
class DiagramBacklinks {

  use StringTranslationTrait;

  public function __construct(
    protected LinkIndex $linkIndex,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * The viewable diagrams linking to an entity.
   *
   * @return \Drupal\divengine_modeler\Entity\DiagramInterface[]
   */
  public function load(ContentEntityInterface $entity, ?AccountInterface $account = NULL): array {
    $ids = $this->linkIndex->diagramsLinkingTo($entity->getEntityTypeId(), (string) $entity->id());
    $diagrams = $ids ? $this->entityTypeManager->getStorage('diagram')->loadMultiple($ids) : [];
    return array_filter($diagrams, fn ($diagram) => $diagram->access('view', $account ?? $this->currentUser));
  }

  public function build(ContentEntityInterface $entity): array {
    $diagrams = $this->load($entity);
    $build = [
      '#cache' => [
        'contexts' => ['user'],
        'tags' => array_merge(['diagram_list'], $entity->getCacheTags()),
      ],
    ];
    if (!$diagrams) {
      return $build;
    }
    $items = [];
    foreach ($diagrams as $diagram) {
      $items[] = $diagram->toLink()->toRenderable();
    }
    return $build + [
      '#theme' => 'item_list',
      '#title' => $this->t('Diagrams referencing this record'),
      '#items' => $items,
      '#attributes' => ['class' => ['divengine-diagram-backlinks']],
    ];
  }

}

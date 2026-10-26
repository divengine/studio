<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * The "Traces" panel of a record: what it is linked to, read as sentences.
 *
 * Outgoing links read "<this> <relation> <target>"; incoming ones use the
 * inverse label ("is realized by"). Ends the viewer may not see are named
 * "(restricted)". The panel offers to add a trace from this record.
 */
class TracePanel {

  use StringTranslationTrait;

  /**
   * Entity types that can be ends of a trace link unless a module adds more.
   */
  const END_TYPES = ['artifact', 'diagram', 'node'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Entity types a trace link may connect.
   *
   * Modules add theirs with hook_divengine_trace_end_types_alter().
   *
   * @return string[]
   */
  public function endTypes(): array {
    $types = static::END_TYPES;
    $this->moduleHandler->alter('divengine_trace_end_types', $types);
    return array_values(array_filter(array_unique($types), fn (string $id) => $this->entityTypeManager->hasDefinition($id)));
  }

  /**
   * Builds the panel of one record.
   */
  public function build(ContentEntityInterface $entity, ?string $add_type = NULL): array {
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $source = $query->andConditionGroup()
      ->condition('source_entity_type', $entity->getEntityTypeId())
      ->condition('source_entity_id', $entity->id());
    $target = $query->andConditionGroup()
      ->condition('target_entity_type', $entity->getEntityTypeId())
      ->condition('target_entity_id', $entity->id());
    $ids = $query->condition($query->orConditionGroup()->condition($source)->condition($target))
      ->sort('link_type')
      ->execute();

    $rows = [];
    $cacheability = \Drupal::service('divengine_traceability.visibility')->queryCacheability($this->currentUser);
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkInterface $link */
    foreach ($storage->loadMultiple($ids) as $link) {
      $access = $link->access('view', $this->currentUser, TRUE);
      $cacheability->addCacheableDependency($access)->addCacheableDependency($link);
      if (!$access->isAllowed()) {
        continue;
      }
      $outgoing = $link->getSourceEntityType() === $entity->getEntityTypeId() && (string) $link->getSourceEntityId() === (string) $entity->id();
      $type = $link->getLinkType();
      $relation = $type ? ($outgoing ? $type->label() : ($type->getInverseLabel() ?: $type->label())) : $link->get('link_type')->value;
      $other = $outgoing ? $link->getTargetEntity() : $link->getSourceEntity();
      $other_cell = ['#plain_text' => (string) $this->t('(restricted)')];
      if ($other && $other->access('view', $this->currentUser)) {
        $other_cell = $other->hasLinkTemplate('canonical') ? $other->toLink()->toRenderable() : ['#plain_text' => (string) $other->label()];
      }
      $rows[] = [
        ['data' => ['#plain_text' => (string) $relation]],
        ['data' => $other_cell],
        ['data' => ['#plain_text' => (string) ($link->get('rationale')->value ?? '')]],
        ['data' => $link->access('view') ? Link::fromTextAndUrl($this->t('Details'), $link->toUrl())->toRenderable() : []],
      ];
    }

    $actions = [];
    $add = Url::fromRoute('entity.trace_link.add_form', [], ['query' => array_filter([
      'source' => $entity->getEntityTypeId() . '/' . $entity->id(),
      'type' => $add_type,
      'destination' => $entity->toUrl()->toString(),
    ])]);
    if ($add->access($this->currentUser)) {
      $actions['add'] = Link::fromTextAndUrl($this->t('Add trace'), $add)->toRenderable()
        + ['#attributes' => ['class' => ['button', 'button--small']]];
    }
    // Modules offer other ways to trace the record, e.g. to external work.
    $this->moduleHandler->alter('divengine_trace_panel_actions', $actions, $entity);

    $build = [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $this->t('Traces'), 'count' => (string) count($rows)],
      '#slots' => array_filter([
        'content' => [
          '#type' => 'table',
          '#header' => [$this->t('Relation'), $this->t('Record'), $this->t('Rationale'), ''],
          '#rows' => $rows,
          '#empty' => $this->t('Not traced yet. Traces are recorded explicitly, never inferred.'),
        ],
        'actions' => $actions ?: NULL,
      ]),
      '#cache' => [
        'tags' => ['trace_link_list'],
        'contexts' => ['user.permissions', 'user'],
      ],
    ];
    $cacheability->applyTo($build);
    return $build;
  }


  /**
   * "Mentioned in": the records whose texts mention this one with
   * [[CODE]], that the viewer may see. A comment shows as the discussion
   * of its record.
   */
  public function mentions(ContentEntityInterface $entity): array {
    $rows = [];
    foreach (\Drupal::service('divengine_traceability.reference_index')->mentionsOf($entity) as $source) {
      $place = $source;
      $where = $source->getEntityType()->getBundleEntityType() && ($bundle = $source->get($source->getEntityType()->getKey('bundle'))->entity)
        ? (string) $bundle->label()
        : (string) $source->getEntityType()->getSingularLabel();
      if ($source->getEntityTypeId() === 'comment' && ($host = $source->getCommentedEntity())) {
        $place = $host;
        $where = (string) $this->t('discussion of a @type', ['@type' => $host->getEntityType()->getSingularLabel()]);
      }
      if (!$source->access('view', $this->currentUser) || !$place->access('view', $this->currentUser) || !$place->hasLinkTemplate('canonical')) {
        continue;
      }
      $rows[$place->getEntityTypeId() . ':' . $place->id() . ':' . $where] = [
        ['data' => $place->toLink()->toRenderable()],
        ['data' => ['#plain_text' => $where]],
      ];
    }
    if (!$rows) {
      return [];
    }
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $this->t('Mentioned in'), 'count' => (string) count($rows), 'description' => (string) $this->t('Texts that link here with its code.')],
      '#slots' => ['content' => ['#type' => 'table', '#header' => [$this->t('Record'), $this->t('Where')], '#rows' => array_values($rows)]],
      '#cache' => ['tags' => ['comment_list', 'node_list'], 'contexts' => ['user']],
    ];
  }

}

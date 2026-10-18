<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a baseline: the exact revision of every record it holds and
 * whether the record changed afterwards.
 */
class BaselineViewBuilder extends EntityViewBuilder {

  protected BaselineRecorder $baselines;

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->baselines = $container->get('divengine_process.baselines');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $baseline) {
      $rows = [];
      $changed = 0;
      foreach ($this->baselines->items($baseline) as $item) {
        $storage = $this->entityTypeManager->getStorage($item['entity_type']);
        $current = $storage->load($item['entity_id']);
        $revision = $storage->loadRevision($item['revision_id']);
        if (!$revision || !$revision->access('view')) {
          continue;
        }
        $same = $current && (int) $current->getRevisionId() === (int) $item['revision_id'];
        $changed += $same ? 0 : 1;
        $exact = $revision->hasLinkTemplate('revision')
          ? Link::fromTextAndUrl($this->t('Revision @id', ['@id' => $item['revision_id']]), $revision->toUrl('revision'))->toRenderable()
          : ['#plain_text' => (string) $this->t('Revision @id', ['@id' => $item['revision_id']])];
        $kind = $item['entity_type'] === 'artifact'
          ? (string) $revision->get('type')->entity?->label()
          : (string) $this->entityTypeManager->getDefinition($item['entity_type'])->getSingularLabel();
        $rows[] = [
          ['data' => ['#plain_text' => $kind]],
          ['data' => ['#plain_text' => trim(($revision->hasField('artifact_key') ? $revision->get('artifact_key')->value . ' ' : '') . $revision->label())]],
          ['data' => $exact],
          ['data' => [
            '#type' => 'component',
            '#component' => 'divengine_core:badge',
            '#props' => $same
              ? ['label' => (string) $this->t('Unchanged'), 'tone' => 'success']
              : ['label' => (string) $this->t('Changed since'), 'tone' => 'warning'],
          ]],
          ['data' => $current && $current->access('view') && $current->hasLinkTemplate('canonical') ? Link::fromTextAndUrl($this->t('Current'), $current->toUrl())->toRenderable() : []],
        ];
      }
      $build[$id]['dv_status'] = [
        '#type' => 'component',
        '#component' => 'divengine_core:status-bar',
        '#props' => [
          'code' => (string) $baseline->get('code')->value,
          'facts' => array_values(array_filter([
            ['label' => (string) $this->t('Development'), 'value' => (string) $baseline->get('development')->entity?->label()],
            $baseline->get('milestone')->entity ? ['label' => (string) $this->t('Milestone'), 'value' => (string) $baseline->get('milestone')->entity->label()] : NULL,
            ['label' => (string) $this->t('Records'), 'value' => (string) count($rows)],
            ['label' => (string) $this->t('Changed since'), 'value' => (string) $changed],
          ])),
        ],
        '#weight' => -100,
      ];
      $build[$id]['dv_items'] = [
        '#type' => 'component',
        '#component' => 'divengine_core:section',
        '#props' => ['title' => (string) $this->t('Records and their exact revisions'), 'count' => (string) count($rows)],
        '#slots' => [
          'content' => [
            '#type' => 'table',
            '#header' => [$this->t('Kind'), $this->t('Record'), $this->t('Exact revision'), $this->t('Since then'), ''],
            '#rows' => $rows,
            '#empty' => $this->t('Nothing was baselined when this baseline was taken.'),
          ],
        ],
        '#weight' => 50,
      ];
      $build[$id]['#cache']['tags'][] = 'artifact_list';
      $build[$id]['#cache']['tags'][] = 'trace_link_list';
    }
  }

}

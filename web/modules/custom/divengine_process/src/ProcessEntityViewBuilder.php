<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\ChangeRequest;
use Drupal\divengine_process\Entity\ProcessActivity;
use Drupal\divengine_process\Lifecycle\LifecycleManager;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Page of a process record with a lifecycle (activity, change request).
 *
 * A status bar with the code, state, key facts and the transitions the
 * user may apply, the warnings that explain what is missing, then the
 * fields.
 */
class ProcessEntityViewBuilder extends EntityViewBuilder {

  protected LifecycleManager $lifecycle;

  protected TraceabilityGaps $gaps;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->lifecycle = $container->get('divengine_process.lifecycle');
    $instance->gaps = $container->get('divengine_process.gaps');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $entity) {
      if ($entity instanceof StatefulInterface) {
        $build[$id]['dv_status'] = $this->statusBar($entity) + ['#weight' => -100];
        if ($warnings = $this->warnings($entity)) {
          $build[$id]['dv_warnings'] = [
            '#theme' => 'status_messages',
            '#message_list' => ['warning' => $warnings],
            '#status_headings' => ['warning' => $this->t('Warning message')],
            '#weight' => -90,
          ];
        }
      }
    }
  }

  protected function statusBar(StatefulInterface $entity): array {
    $facts = [];
    $development = $entity->get('development')->entity;
    if ($development) {
      $facts[] = ['label' => (string) $this->t('Development'), 'value' => (string) $development->label()];
    }
    foreach (['phase', 'iteration', 'target_iteration', 'discipline', 'uid', 'responsible'] as $field) {
      if ($entity->hasField($field) && ($target = $entity->get($field)->entity)) {
        $facts[] = ['label' => (string) $entity->getFieldDefinition($field)->getLabel(), 'value' => (string) $target->label()];
      }
    }

    $actions = [];
    if ($entity->access('update')) {
      foreach ($this->lifecycle->available($entity) as $id => $transition) {
        $actions[] = Link::fromTextAndUrl($transition->label, $this->lifecycle->url($entity, $id, $entity->toUrl()))->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', !$actions ? 'button--primary' : NULL])]];
      }
      $actions[] = Link::fromTextAndUrl($this->t('Edit'), $entity->toUrl('edit-form'))->toRenderable()
        + ['#attributes' => ['class' => ['button', 'button--small']]];
    }

    $state = $entity->getState();
    $tone = match ($state) {
      'completed', 'verified', 'closed', 'implemented', 'scheduled', 'reached', 'active', 'done' => 'success',
      'blocked', 'rejected' => 'danger',
      'skipped', 'accepted', 'triaged' => 'warning',
      'in_progress', 'ready' => 'info',
      default => 'neutral',
    };
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => [
        'code' => $entity->hasField('code') ? (string) $entity->get('code')->value : '',
        'facts' => $facts,
      ],
      '#slots' => array_filter([
        'badge' => [
          '#type' => 'component',
          '#component' => 'divengine_core:badge',
          '#props' => ['label' => (string) ($entity::stateLabels()[$state] ?? $state), 'tone' => $tone],
        ],
        'actions' => $actions ? [
          '#type' => 'container',
          '#attributes' => ['class' => ['divengine-process-actions']],
          'links' => $actions,
        ] : NULL,
      ]),
    ];
  }

  /**
   * What the record is missing, in the words the user acts on.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   */
  protected function warnings(StatefulInterface $entity): array {
    $warnings = [];
    if ($entity instanceof ProcessActivity) {
      if ($entity->getState() === 'blocked') {
        $warnings[] = $this->t('Blocked: @reason', ['@reason' => (string) $entity->get('block_reason')->value]);
      }
      if (!in_array($entity->getState(), ['completed', 'skipped'], TRUE) && ($missing = $entity->missingOutputs())) {
        $warnings[] = $this->t('Expected outputs not produced yet: @missing.', ['@missing' => implode(', ', array_map(fn ($d) => $d->label(), $missing))]);
      }
    }
    if ($entity instanceof ChangeRequest && $entity->getState() === 'accepted') {
      $warnings[] = $this->t('Accepted but not scheduled: choose its target iteration.');
    }
    if ($entity instanceof ChangeRequest && in_array($entity->getState(), ['accepted', 'scheduled'], TRUE) && !$this->gaps->hasImpact($entity)) {
      $url = Url::fromRoute('entity.trace_link.add_form', [], ['query' => [
        'source' => 'change_request/' . $entity->id(),
        'type' => 'impacts',
        'destination' => $entity->toUrl()->toString(),
      ]]);
      $warnings[] = $this->t('No impact analysis: <a href=":url">record what this change impacts</a>.', [':url' => $url->toString()]);
    }
    return $warnings;
  }

}

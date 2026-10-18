<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "My work": what the current user has to do across their Developments.
 *
 * Open activities they own or are assigned to, the iterations in progress
 * and the change requests waiting for a decision. Only what they may see.
 */
class MyWorkController extends ControllerBase {

  const OPEN_ACTIVITY = ['planned', 'ready', 'in_progress', 'blocked'];

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  public function page(): array {
    $uid = (int) $this->currentUser()->id();
    $build = [
      '#cache' => [
        'tags' => ['process_activity_list', 'iteration_list', 'change_request_list', 'development_list'],
        'contexts' => ['user'],
      ],
    ];

    $activities = $this->entityTypeManager->getStorage('process_activity');
    $query = $activities->getQuery()->accessCheck(TRUE)->condition('state', static::OPEN_ACTIVITY, 'IN');
    $query->condition($query->orConditionGroup()->condition('uid', $uid)->condition('assignees', $uid));
    $rows = [];
    foreach ($activities->loadMultiple($query->sort('date_deadline')->sort('id')->execute()) as $activity) {
      $rows[] = [
        ['data' => $activity->toLink()->toRenderable()],
        ['data' => ['#plain_text' => (string) $activity->get('development')->entity?->label()]],
        ['data' => ['#plain_text' => (string) $activity->get('iteration')->entity?->label()]],
        ['data' => $this->stateBadge($activity)],
        ['data' => ['#plain_text' => (string) ($activity->get('date_deadline')->value ?? '')]],
      ];
    }
    $build['activities'] = $this->section($this->t('My activities'), $rows,
      [$this->t('Activity'), $this->t('Development'), $this->t('Iteration'), $this->t('State'), $this->t('Deadline')],
      $this->t('Nothing assigned to you. Activities you own or are assigned to appear here.'));

    $iterations = $this->entityTypeManager->getStorage('iteration');
    $rows = [];
    foreach ($iterations->loadMultiple($iterations->getQuery()->accessCheck(TRUE)->condition('state', 'active')->sort('date_end')->execute()) as $iteration) {
      $development = $iteration->get('development')->entity;
      $rows[] = [
        ['data' => ['#plain_text' => $iteration->label()]],
        ['data' => $development ? $development->toLink()->toRenderable() : []],
        ['data' => ['#plain_text' => (string) $iteration->get('phase')->entity?->label()]],
        ['data' => ['#plain_text' => trim(($iteration->get('date_start')->value ?? '…') . ' → ' . ($iteration->get('date_end')->value ?? '…'))]],
      ];
    }
    $build['iterations'] = $this->section($this->t('Current iterations'), $rows,
      [$this->t('Iteration'), $this->t('Development'), $this->t('Phase'), $this->t('Dates')],
      $this->t('No iteration in progress.'));

    $changes = $this->entityTypeManager->getStorage('change_request');
    $rows = [];
    foreach ($changes->loadMultiple($changes->getQuery()->accessCheck(TRUE)->condition('state', ['submitted', 'triaged'], 'IN')->condition('status', 1)->sort('code')->execute()) as $change) {
      $rows[] = [
        ['data' => $change->toLink()->toRenderable()],
        ['data' => ['#plain_text' => (string) $change->get('development')->entity?->label()]],
        ['data' => ['#plain_text' => (string) (Vocabulary::priorities()[$change->get('priority')->value] ?? '')]],
        ['data' => $this->stateBadge($change)],
      ];
    }
    $build['changes'] = $this->section($this->t('Change requests to decide'), $rows,
      [$this->t('Change request'), $this->t('Development'), $this->t('Priority'), $this->t('State')],
      $this->t('No change request waiting for a decision.'));
    return $build;
  }

  protected function stateBadge(StatefulInterface $entity): array {
    $state = $entity->getState();
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:badge',
      '#props' => [
        'label' => (string) ($entity::stateLabels()[$state] ?? $state),
        'tone' => match ($state) {
          'blocked' => 'danger',
          'in_progress', 'ready', 'active' => 'info',
          'triaged' => 'warning',
          default => 'neutral',
        },
      ],
    ];
  }

  protected function section($title, array $rows, array $header, $empty): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => (string) count($rows)],
      '#slots' => [
        'content' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $empty,
        ],
      ],
    ];
  }

}

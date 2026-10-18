<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Lifecycle\StatefulInterface;

/**
 * Shows a Development with its phases, milestones, iterations, roles and
 * process snapshot, and the actions that move it forward.
 */
class DevelopmentViewBuilder extends EntityViewBuilder {

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $development) {
      $build[$id]['process'] = $this->buildProcess($development);
      $build[$id]['#cache']['tags'] = array_merge($build[$id]['#cache']['tags'] ?? [], [
        'development_phase_list',
        'iteration_list',
        'milestone_list',
        'role_assignment_list',
        'config:development_case_list',
      ]);
      $build[$id]['#cache']['contexts'][] = 'user';
    }
  }

  /**
   * The process sections of one Development.
   */
  protected function buildProcess(DevelopmentInterface $development): array {
    /** @var \Drupal\divengine_process\DevelopmentProcess $process */
    $process = \Drupal::service('divengine_process.development');
    $can_edit = $development->access('update');

    $section = [
      '#weight' => 100,
    ];

    $actions = $this->transitionLinks($development);
    if ($can_edit) {
      if ($development->getCase()) {
        $actions[] = Link::fromTextAndUrl($this->t('Apply Development Case'), Url::fromRoute('divengine_process.apply_case', ['development' => $development->id()]))->toRenderable();
      }
      $actions[] = Link::fromTextAndUrl($this->t('Add missing standard phases'), Url::fromRoute('divengine_process.ensure_phases', ['development' => $development->id()]))->toRenderable();
    }
    $section['actions'] = $this->buttons($actions, -10);

    if ($development->isCaseOutdated()) {
      $section['outdated'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [$this->t('The Development Case changed after it was applied. This Development keeps its snapshot until you re-apply the case.')]],
        '#weight' => -9,
      ];
    }

    $date = fn (EntityInterface $e, string $field): string => (string) ($e->get($field)->value ?? '');

    $rows = [];
    foreach ($process->phases($development) as $phase) {
      $rows[] = [
        $phase->label(),
        Vocabulary::phaseStates()[$phase->get('state')->value] ?? '',
        $date($phase, 'date_start'),
        $date($phase, 'date_end'),
        $phase->access('update') ? Link::fromTextAndUrl($this->t('Edit'), $phase->toUrl('edit-form'))->toString() : '',
      ];
    }
    $section['phases'] = $this->table($this->t('Phases'), [$this->t('Phase'), $this->t('State'), $this->t('Start'), $this->t('End'), ''], $rows, 0);

    $rows = [];
    foreach ($process->children($development, 'milestone') as $milestone) {
      /** @var \Drupal\divengine_process\Entity\Milestone $milestone */
      $rows[] = [
        $milestone->label(),
        $milestone->get('phase')->entity?->label() ?? '',
        $date($milestone, 'date_planned'),
        $date($milestone, 'date_reached'),
        $milestone->getStateLabel(),
        \Drupal::service('renderer')->render($this->buttons($this->transitionLinks($milestone, TRUE), 0)),
      ];
    }
    $section['milestones'] = $this->table($this->t('Milestones'), [$this->t('Milestone'), $this->t('Phase'), $this->t('Planned'), $this->t('Reached'), $this->t('State'), ''], $rows, 10);

    $rows = [];
    foreach ($process->children($development, 'iteration') as $iteration) {
      /** @var \Drupal\divengine_process\Entity\Iteration $iteration */
      $links = $this->transitionLinks($iteration);
      if ($iteration->access('update')) {
        $links[] = Link::fromTextAndUrl($this->t('Edit'), $iteration->toUrl('edit-form'))->toRenderable();
      }
      $rows[] = [
        $iteration->label(),
        $iteration->get('phase')->entity?->label() ?? '',
        $iteration->getStateLabel(),
        $date($iteration, 'date_start'),
        $date($iteration, 'date_end'),
        \Drupal::service('renderer')->render($this->buttons($links, 0)),
      ];
    }
    $section['iterations'] = $this->table($this->t('Iterations'), [$this->t('Iteration'), $this->t('Phase'), $this->t('State'), $this->t('Start'), $this->t('End'), ''], $rows, 20);
    if ($can_edit) {
      $section['iterations_add'] = $this->buttons([
        Link::fromTextAndUrl($this->t('Add iteration'), Url::fromRoute('entity.iteration.add_form', [], ['query' => ['development' => $development->id()]]))->toRenderable(),
      ], 21);
    }

    $rows = [];
    foreach ($process->children($development, 'role_assignment') as $assignment) {
      $links = [];
      if ($assignment->access('update')) {
        $links[] = Link::fromTextAndUrl($this->t('Edit'), $assignment->toUrl('edit-form'))->toRenderable();
      }
      if ($assignment->access('delete')) {
        $links[] = Link::fromTextAndUrl($this->t('Remove'), $assignment->toUrl('delete-form'))->toRenderable();
      }
      $rows[] = [
        $assignment->get('process_role')->entity?->label() ?? '',
        $assignment->get('user')->entity?->getDisplayName() ?? '',
        $assignment->get('is_primary')->value ? $this->t('Primary') : '',
        $date($assignment, 'date_from'),
        $date($assignment, 'date_to'),
        \Drupal::service('renderer')->render($this->buttons($links, 0)),
      ];
    }
    $section['roles'] = $this->table($this->t('Process roles'), [$this->t('Role'), $this->t('User'), '', $this->t('From'), $this->t('To'), ''], $rows, 30);
    if ($can_edit) {
      $section['roles_add'] = $this->buttons([
        Link::fromTextAndUrl($this->t('Assign a role'), Url::fromRoute('entity.role_assignment.add_form', [], ['query' => ['development' => $development->id()]]))->toRenderable(),
      ], 31);
    }

    $section['policy'] = $this->policy($development, 40);
    return $section;
  }

  /**
   * The process snapshot: case, applied version and lines.
   */
  protected function policy(DevelopmentInterface $development, int $weight): array {
    $case = $development->getCase();
    $applied = $development->get('case_applied')->value;
    $summary = $case
      ? $this->t('Case %case, version @version applied on @date (current version: @current).', [
        '%case' => $case->label(),
        '@version' => $development->getCaseVersion() ?? '-',
        '@date' => $applied ? \Drupal::service('date.formatter')->format((int) $applied, 'short') : '-',
        '@current' => $case->getVersion(),
      ])
      : $this->t('No Development Case.');

    $types = Vocabulary::caseElementTypes();
    $levels = Vocabulary::levels();
    $milestones = Vocabulary::milestoneTypes();
    $entity_types = Vocabulary::caseElementEntityTypes();
    $rows = [];
    foreach ($development->getPolicy() as $line) {
      $label = $line['target'];
      if (isset($entity_types[$line['element_type']]) && ($target = \Drupal::entityTypeManager()->getStorage($entity_types[$line['element_type']])->load($line['target']))) {
        $label = $target->label();
      }
      $rows[] = [
        $types[$line['element_type']] ?? $line['element_type'],
        $label,
        $line['milestone_type'] ? ($milestones[$line['milestone_type']] ?? $line['milestone_type']) : $this->t('Every milestone'),
        $levels[$line['level']] ?? $line['level'],
        $line['note'],
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Process (Development Case snapshot)'),
      '#weight' => $weight,
      'summary' => ['#markup' => '<p>' . $summary . '</p>'],
      'lines' => [
        '#type' => 'table',
        '#header' => [$this->t('Element type'), $this->t('Element'), $this->t('Milestone'), $this->t('Level'), $this->t('Note')],
        '#rows' => $rows,
        '#empty' => $this->t('No policy: apply a Development Case.'),
      ],
    ];
  }

  /**
   * Links to the transitions the user may apply now.
   *
   * @return array[]
   */
  protected function transitionLinks(StatefulInterface $entity, bool $small = FALSE): array {
    if (!$entity->access('update')) {
      return [];
    }
    $links = [];
    foreach (\Drupal::service('divengine_process.lifecycle')->available($entity) as $id => $transition) {
      $url = Url::fromRoute('divengine_process.transition', [
        'entity_type' => $entity->getEntityTypeId(),
        'entity' => $entity->id(),
        'transition' => $id,
      ]);
      $links[] = Link::fromTextAndUrl($transition->label, $url)->toRenderable();
    }
    return $links;
  }

  /**
   * Renders links as a row of small buttons.
   */
  protected function buttons(array $links, int $weight): array {
    foreach ($links as &$link) {
      $link['#attributes']['class'][] = 'button';
      $link['#attributes']['class'][] = 'button--small';
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['divengine-process-actions']],
      '#weight' => $weight,
      'links' => $links,
    ];
  }

  protected function table($caption, array $header, array $rows, int $weight): array {
    foreach ($rows as &$row) {
      foreach ($row as &$cell) {
        // Links, rendered buttons and translations are safe markup; values
        // typed by users are plain text.
        if ($cell instanceof MarkupInterface) {
          $cell = ['data' => ['#markup' => $cell]];
        }
        elseif (is_string($cell)) {
          $cell = ['data' => ['#plain_text' => $cell]];
        }
      }
    }
    return [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('None.'),
      '#weight' => $weight,
    ];
  }

}

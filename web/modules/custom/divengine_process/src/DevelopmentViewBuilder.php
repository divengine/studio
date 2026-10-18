<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Lifecycle\LifecycleManager;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Readiness\ReadinessEvaluator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows a Development as a workspace page.
 *
 * Status bar, lifecycle stepper and fact cards first, then the fields
 * (objective, notes, members), then its iterations, team, milestones,
 * phases and process snapshot. Everything is built from the Studio
 * components of divengine_core, so any theme can restyle it.
 */
class DevelopmentViewBuilder extends EntityViewBuilder {

  protected LifecycleManager $lifecycle;

  protected DevelopmentProcess $process;

  protected DateFormatterInterface $dateFormatter;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected ReadinessEvaluator $readiness;

  protected TraceabilityGaps $gaps;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->lifecycle = $container->get('divengine_process.lifecycle');
    $instance->process = $container->get('divengine_process.development');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->readiness = $container->get('divengine_process.readiness');
    $instance->gaps = $container->get('divengine_process.gaps');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $development) {
      $build[$id] += $this->buildWorkspace($development);
      $build[$id]['#cache']['tags'] = array_merge($build[$id]['#cache']['tags'] ?? [], [
        'development_phase_list',
        'iteration_list',
        'milestone_list',
        'role_assignment_list',
        'artifact_list',
        'process_activity_list',
        'change_request_list',
        'trace_link_list',
        'config:development_case_list',
      ]);
      $build[$id]['#cache']['contexts'][] = 'user';
    }
  }

  /**
   * The workspace sections of one Development.
   */
  protected function buildWorkspace(DevelopmentInterface $development): array {
    $can_edit = $development->access('update');
    $phases = $this->process->phases($development);
    $milestones = $this->process->children($development, 'milestone');
    $iterations = $this->process->children($development, 'iteration');
    $assignments = $this->process->children($development, 'role_assignment');

    $build['status'] = $this->statusBar($development, $can_edit) + ['#weight' => -100];
    $build['lifecycle'] = $this->stepper($development, $phases, $milestones) + ['#weight' => -90];
    $build['cards'] = $this->cards($development, $milestones, $assignments) + ['#weight' => -80];
    $build['facts'] = $this->facts($development) + ['#weight' => -60];

    if ($development->isCaseOutdated()) {
      $build['outdated'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [$this->t('The Development Case changed after it was applied. This Development keeps its snapshot until you re-apply the case.')]],
        '#status_headings' => ['warning' => $this->t('Warning message')],
        '#weight' => -70,
      ];
    }

    $build['columns'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-columns']],
      '#weight' => 100,
      'iterations' => $this->iterations($development, $iterations, $can_edit),
      'team' => $this->team($development, $assignments, $can_edit),
    ];
    $build['milestones'] = $this->milestones($milestones) + ['#weight' => 110];
    $build['phases'] = $this->phases($phases) + ['#weight' => 120];
    $build['policy'] = $this->policy($development) + ['#weight' => 130];
    return $build;
  }

  protected function statusBar(DevelopmentInterface $development, bool $can_edit): array {
    $facts = [];
    $add = function (TranslatableMarkup $label, ?string $value) use (&$facts): void {
      if ($value !== NULL && $value !== '') {
        $facts[] = ['label' => (string) $label, 'value' => $value];
      }
    };
    $add($this->t('Responsible'), $development->get('responsible')->entity?->getDisplayName());
    $add($this->t('Phase'), $development->get('current_phase')->entity?->label());
    $add($this->t('Iteration'), $development->get('current_iteration')->entity?->label());
    $start = $development->get('date_start')->value;
    $end = $development->get('date_end')->value;
    if ($start || $end) {
      $add($this->t('Dates'), ($start ?: '…') . ' → ' . ($end ?: '…'));
    }

    $actions = $this->transitionLinks($development, TRUE);
    if ($can_edit) {
      $actions[] = $this->button($this->t('Edit'), $development->toUrl('edit-form'));
    }
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => ['code' => $development->getCode(), 'facts' => $facts],
      '#slots' => array_filter([
        'badge' => $this->badge($development),
        'actions' => $actions ? $this->actionsContainer($actions) : NULL,
      ]),
    ];
  }

  /**
   * @param \Drupal\divengine_process\Entity\DevelopmentPhase[] $phases
   * @param \Drupal\divengine_process\Entity\Milestone[] $milestones
   */
  protected function stepper(DevelopmentInterface $development, array $phases, array $milestones): array {
    $current = (string) $development->get('current_phase')->target_id;
    $closing = [];
    foreach ($milestones as $milestone) {
      $closing[(string) $milestone->get('phase')->target_id] = $milestone;
    }
    $steps = [];
    $passed_current = FALSE;
    foreach ($phases as $id => $phase) {
      $is_current = (string) $id === $current;
      // Phases before the current one are behind; a phase marked done is
      // done wherever it is.
      $state = match (TRUE) {
        $is_current => 'current',
        $phase->get('state')->value === 'done', !$passed_current && $current !== '' => 'done',
        default => 'upcoming',
      };
      if ($is_current) {
        $passed_current = TRUE;
      }
      $note = '';
      if ($milestone = $closing[(string) $id] ?? NULL) {
        $note = $milestone->getState() === 'reached'
          ? (string) $this->t('@milestone reached', ['@milestone' => $milestone->label()])
          : $milestone->label();
      }
      $steps[] = ['label' => $phase->label(), 'state' => $state, 'note' => $note];
    }
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:stepper',
      '#props' => ['steps' => $steps],
    ];
  }

  /**
   * @param \Drupal\divengine_process\Entity\Milestone[] $milestones
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $assignments
   */
  protected function cards(DevelopmentInterface $development, array $milestones, array $assignments): array {
    $cards = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];

    $missing = $this->readiness->missingCount($this->readiness->evaluate($development));
    $cards['readiness'] = $this->card(
      $this->t('Readiness'),
      $missing ? (string) $this->formatPlural($missing, '1 required criterion missing', '@count required criteria missing') : (string) $this->t('Required criteria met'),
      (string) $this->t('Advisory: see what is missing'),
      $missing ? 'danger' : 'success',
      Url::fromRoute('divengine_process.development_readiness', ['development' => $development->id()])->toString(),
    );

    $next = NULL;
    foreach ($milestones as $milestone) {
      if ($milestone->getState() !== 'reached') {
        $next = $milestone;
        break;
      }
    }
    $cards['milestone'] = $this->card(
      $this->t('Next milestone'),
      $next ? $next->label() : (string) $this->t('All reached'),
      $next && $next->get('date_planned')->value ? (string) $this->t('Planned for @date', ['@date' => $next->get('date_planned')->value]) : '',
      $next ? 'info' : 'success',
    );

    $iteration = $development->get('current_iteration')->entity;
    $cards['iteration'] = $this->card(
      $this->t('Current iteration'),
      $iteration ? $iteration->label() : (string) $this->t('None started'),
      $iteration ? (string) $this->t('In @phase', ['@phase' => $iteration->get('phase')->entity?->label() ?? '']) : (string) $this->t('Plan one and start it.'),
      $iteration ? 'info' : 'warning',
    );

    $people = [];
    foreach ($assignments as $assignment) {
      $people[$assignment->get('user')->target_id] = TRUE;
    }
    $cards['team'] = $this->card(
      $this->t('Team'),
      (string) $this->formatPlural(count($people), '1 person', '@count people'),
      (string) $this->formatPlural(count($assignments), '1 role assignment', '@count role assignments'),
    );

    $case = $development->getCase();
    $cards['process'] = $this->card(
      $this->t('Process'),
      $case ? $case->label() : (string) $this->t('No case'),
      $development->isCaseOutdated()
        ? (string) $this->t('Case changed: re-apply it')
        : ($case ? (string) $this->t('Version @version', ['@version' => $development->getCaseVersion()]) : ''),
      $development->isCaseOutdated() ? 'warning' : 'neutral',
    );
    return $cards;
  }

  protected function iterations(DevelopmentInterface $development, array $iterations, bool $can_edit): array {
    $rows = [];
    foreach ($iterations as $iteration) {
      /** @var \Drupal\divengine_process\Entity\Iteration $iteration */
      $actions = $this->transitionLinks($iteration);
      if ($iteration->access('update')) {
        $actions[] = $this->button($this->t('Edit'), $iteration->toUrl('edit-form'));
      }
      $rows[] = [
        ['data' => ['#plain_text' => $iteration->label()]],
        ['data' => ['#plain_text' => $iteration->get('phase')->entity?->label() ?? '']],
        ['data' => $this->badge($iteration)],
        ['data' => $this->actionsContainer($actions)],
      ];
    }
    $actions = $can_edit
      ? [$this->button($this->t('Add iteration'), Url::fromRoute('entity.iteration.add_form', [], ['query' => ['development' => $development->id()]]), TRUE)]
      : [];
    return $this->section($this->t('Iterations'), count($iterations), $this->table([$this->t('Iteration'), $this->t('Phase'), $this->t('State'), ''], $rows, $this->t('No iteration yet: plan the first one.')), $actions);
  }

  protected function team(DevelopmentInterface $development, array $assignments, bool $can_edit): array {
    $rows = [];
    foreach ($assignments as $assignment) {
      $actions = [];
      if ($assignment->access('update')) {
        $actions[] = $this->button($this->t('Edit'), $assignment->toUrl('edit-form'));
      }
      if ($assignment->access('delete')) {
        $actions[] = $this->button($this->t('Remove'), $assignment->toUrl('delete-form'));
      }
      $rows[] = [
        ['data' => ['#plain_text' => $assignment->get('process_role')->entity?->label() ?? '']],
        ['data' => ['#plain_text' => $assignment->get('user')->entity?->getDisplayName() ?? '']],
        ['data' => $assignment->get('is_primary')->value ? $this->badgeElement($this->t('Primary'), 'info') : []],
        ['data' => $this->actionsContainer($actions)],
      ];
    }
    $actions = $can_edit
      ? [$this->button($this->t('Assign a role'), Url::fromRoute('entity.role_assignment.add_form', [], ['query' => ['development' => $development->id()]]), TRUE)]
      : [];
    return $this->section($this->t('Team'), count($assignments), $this->table([$this->t('Role'), $this->t('Person'), '', ''], $rows, $this->t('Nobody plays a process role yet.')), $actions);
  }

  /**
   * @param \Drupal\divengine_process\Entity\Milestone[] $milestones
   */
  protected function milestones(array $milestones): array {
    $rows = [];
    foreach ($milestones as $milestone) {
      $actions = $this->transitionLinks($milestone);
      if ($milestone->access('update')) {
        $actions[] = $this->button($this->t('Edit'), $milestone->toUrl('edit-form'));
      }
      $missing = $this->readiness->missingCount($this->readiness->evaluate($milestone->getDevelopment(), $milestone->getMilestoneType()));
      $rows[] = [
        ['data' => ['#plain_text' => $milestone->label()]],
        ['data' => ['#plain_text' => $milestone->get('phase')->entity?->label() ?? '']],
        ['data' => ['#plain_text' => (string) ($milestone->get('date_planned')->value ?? '')]],
        ['data' => ['#plain_text' => (string) ($milestone->get('date_reached')->value ?? '')]],
        ['data' => $missing ? $this->badgeElement($this->formatPlural($missing, '1 missing', '@count missing'), 'danger') : $this->badgeElement($this->t('Ready'), 'success')],
        ['data' => $this->badge($milestone)],
        ['data' => $this->actionsContainer($actions)],
      ];
    }
    return $this->section($this->t('Milestones'), count($milestones), $this->table([$this->t('Milestone'), $this->t('Closes'), $this->t('Planned'), $this->t('Reached'), $this->t('Readiness'), $this->t('State'), ''], $rows, $this->t('No milestone.')));
  }

  protected function phases(array $phases): array {
    $rows = [];
    foreach ($phases as $phase) {
      $rows[] = [
        ['data' => ['#plain_text' => $phase->label()]],
        ['data' => $this->badgeElement(Vocabulary::phaseStates()[$phase->get('state')->value] ?? '', $phase->get('state')->value === 'active' ? 'info' : 'neutral')],
        ['data' => ['#plain_text' => (string) ($phase->get('date_start')->value ?? '')]],
        ['data' => ['#plain_text' => (string) ($phase->get('date_end')->value ?? '')]],
        ['data' => $phase->access('update') ? $this->actionsContainer([$this->button($this->t('Edit'), $phase->toUrl('edit-form'))]) : []],
      ];
    }
    return $this->section($this->t('Phases'), count($phases), $this->table([$this->t('Phase'), $this->t('State'), $this->t('Start'), $this->t('End'), ''], $rows, $this->t('No phase.')));
  }

  /**
   * The process snapshot: case, applied version and lines.
   */
  protected function policy(DevelopmentInterface $development): array {
    $case = $development->getCase();
    $applied = $development->get('case_applied')->value;
    $types = Vocabulary::caseElementTypes();
    $levels = Vocabulary::levels();
    $milestone_types = Vocabulary::milestoneTypes();
    $entity_types = Vocabulary::caseElementEntityTypes();
    $rows = [];
    foreach ($development->getPolicy() as $line) {
      $label = $line['target'];
      if (isset($entity_types[$line['element_type']]) && ($target = $this->entityTypeManager->getStorage($entity_types[$line['element_type']])->load($line['target']))) {
        $label = $target->label();
      }
      $rows[] = [
        ['data' => ['#plain_text' => (string) ($types[$line['element_type']] ?? $line['element_type'])]],
        ['data' => ['#plain_text' => (string) $label]],
        ['data' => ['#plain_text' => (string) ($line['milestone_type'] ? ($milestone_types[$line['milestone_type']] ?? $line['milestone_type']) : $this->t('Every milestone'))]],
        ['data' => $this->badgeElement($levels[$line['level']] ?? $line['level'], $line['level'] === 'required' ? 'info' : 'neutral')],
      ];
    }

    $actions = [];
    if ($case && $development->access('update')) {
      $actions[] = $this->button($this->t('Apply Development Case'), Url::fromRoute('divengine_process.apply_case', ['development' => $development->id()]));
      $actions[] = $this->button($this->t('Add missing phases'), Url::fromRoute('divengine_process.ensure_phases', ['development' => $development->id()]));
    }
    $summary = $case
      ? $this->t('%case, version @version, applied on @date.', [
        '%case' => $case->label(),
        '@version' => $development->getCaseVersion() ?? '-',
        '@date' => $applied ? $this->dateFormatter->format((int) $applied, 'short') : '-',
      ])
      : $this->t('No Development Case: choose one when editing the Development.');

    return $this->section($this->t('Process'), count($rows), [
      'summary' => ['#markup' => '<p>' . $summary . '</p>'],
      'lines' => [
        '#type' => 'details',
        '#title' => $this->t('Tailoring snapshot'),
        'table' => $this->table([$this->t('Element type'), $this->t('Element'), $this->t('Milestone'), $this->t('Level')], $rows, $this->t('No policy: apply a Development Case.')),
      ],
    ], $actions);
  }

  /**
   * Buttons for the transitions the user may apply now.
   *
   * @return array[]
   */
  protected function transitionLinks(StatefulInterface $entity, bool $primary_first = FALSE): array {
    if (!$entity->access('update')) {
      return [];
    }
    $return = $entity->hasField('development') ? $entity->get('development')->entity?->toUrl() : $entity->toUrl();
    $links = [];
    foreach ($this->lifecycle->available($entity) as $id => $transition) {
      $links[] = $this->button($transition->label, $this->lifecycle->url($entity, $id, $return), $primary_first && !$links);
    }
    return $links;
  }

  /**
   * The state of a record as a badge.
   */
  protected function badge(StatefulInterface $entity): array {
    $state = $entity->getState();
    $tone = match ($state) {
      'active', 'reached' => 'success',
      'on_hold' => 'warning',
      'cancelled' => 'danger',
      'closed', 'done' => 'info',
      default => 'neutral',
    };
    return $this->badgeElement($entity::stateLabels()[$state] ?? $state, $tone);
  }

  protected function badgeElement(string|\Stringable $label, string $tone = 'neutral'): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:badge',
      '#props' => ['label' => (string) $label, 'tone' => $tone],
    ];
  }

  protected function card(string|\Stringable $label, string $value, string $hint = '', string $tone = 'neutral', string $url = ''): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:stat-card',
      '#props' => array_filter(['label' => (string) $label, 'value' => $value, 'hint' => $hint, 'tone' => $tone, 'url' => $url]),
    ];
  }

  /**
   * The Overview panels: facts about the work, the model and the changes.
   */
  protected function facts(DevelopmentInterface $development): array {
    $tab = fn (string $route) => Url::fromRoute($route, ['development' => $development->id()])->toString();
    $state = fn ($artifact) => (string) $artifact->get('moderation_state')->value;
    $current = fn (string $bundle) => array_filter($this->gaps->artifacts($development, $bundle), fn ($a) => $state($a) !== 'deprecated');
    $count_in = fn (array $records, array $states) => count(array_filter($records, fn ($a) => in_array($state($a), $states, TRUE)));
    $grid = ['#type' => 'container', '#attributes' => ['class' => ['dv-facts']]];

    // Activities.
    $activities = $this->process->children($development, 'process_activity');
    $rows = [
      ['label' => (string) $this->t('In progress'), 'value' => (string) count(array_filter($activities, fn ($a) => $a->getState() === 'in_progress'))],
      ['label' => (string) $this->t('Blocked'), 'value' => (string) count(array_filter($activities, fn ($a) => $a->getState() === 'blocked')), 'tone' => 'danger'],
    ];
    foreach (array_slice(array_filter($activities, fn ($a) => $a->getState() === 'blocked'), 0, 5) as $blocked) {
      $rows[] = ['label' => $blocked->label(), 'value' => (string) $blocked->get('block_reason')->value, 'url' => $blocked->toUrl()->toString(), 'tone' => 'danger'];
    }
    $grid['activities'] = $this->panel($this->t('Activities'), $this->hideZero($rows), $tab('divengine_process.development_process'));

    // Missing required work products, over the whole Development.
    $rows = [];
    foreach ($this->readiness->evaluate($development) as $item) {
      if ($item['code'] === 'work_products_baselined') {
        foreach ($item['reasons'] as $reason) {
          $rows[] = ['label' => html_entity_decode($reason, ENT_QUOTES)];
        }
      }
    }
    $grid['work_products'] = $this->panel($this->t('Missing required work products'), $rows, $tab('divengine_process.development_process'));

    // Business model.
    $buc = $current('business_use_case');
    $rules = $current('business_rule');
    $grid['business'] = $this->panel($this->t('Business model'), $this->hideZero([
      ['label' => (string) $this->t('Business actors'), 'value' => (string) count($this->gaps->artifacts($development, 'business_actor'))],
      ['label' => (string) $this->t('Business workers'), 'value' => (string) count($this->gaps->artifacts($development, 'business_worker'))],
      ['label' => (string) $this->t('Business use cases'), 'value' => (string) count($buc)],
      ['label' => (string) $this->t('Business use cases not reviewed'), 'value' => (string) $count_in($buc, ['draft', 'outlined', 'detailed']), 'tone' => 'warning'],
      ['label' => (string) $this->t('Business rules'), 'value' => (string) count($rules)],
      ['label' => (string) $this->t('Business rules not approved'), 'value' => (string) $count_in($rules, ['draft', 'proposed']), 'tone' => 'warning'],
    ]), $tab('divengine_process.development_business'));

    // System use cases by state.
    $use_cases = $this->gaps->artifacts($development, 'use_case');
    $rows = [];
    foreach (['draft' => $this->t('Identified'), 'outlined' => $this->t('Outlined'), 'detailed' => $this->t('Detailed'), 'reviewed' => $this->t('Reviewed'), 'published' => $this->t('Baselined'), 'deprecated' => $this->t('Deprecated')] as $key => $label) {
      $rows[] = ['label' => (string) $label, 'value' => (string) $count_in($use_cases, [$key])];
    }
    $grid['use_cases'] = $this->panel($this->t('System use cases by state'), $this->hideZero($rows), $tab('divengine_process.development_requirements'));

    // Change requests.
    $changes = array_filter($this->process->children($development, 'change_request'), fn ($c) => $c->get('status')->value);
    $in = fn (array $states) => (string) count(array_filter($changes, fn ($c) => in_array($c->getState(), $states, TRUE)));
    $grid['changes'] = $this->panel($this->t('Change requests'), $this->hideZero([
      ['label' => (string) $this->t('Open'), 'value' => $in(['submitted', 'triaged', 'accepted', 'scheduled', 'implemented'])],
      ['label' => (string) $this->t('To decide'), 'value' => $in(['submitted', 'triaged']), 'tone' => 'warning'],
      ['label' => (string) $this->t('Accepted, not scheduled'), 'value' => $in(['accepted']), 'tone' => 'warning'],
    ]), $tab('divengine_process.development_changes'));

    // Traceability gaps.
    $grid['gaps'] = $this->panel($this->t('Traceability gaps'), $this->hideZero([
      ['label' => (string) $this->t('Approved requirements not refined downstream'), 'value' => (string) count($this->gaps->requirementsNotRefined($development)), 'tone' => 'danger'],
      ['label' => (string) $this->t('Change requests without impact analysis'), 'value' => (string) count($this->gaps->changeRequestsWithoutImpact($development)), 'tone' => 'danger'],
    ]), $tab('divengine_process.development_requirements'));

    return $grid;
  }

  /**
   * Leaves out rows whose value is zero; label-only rows stay.
   */
  protected function hideZero(array $rows): array {
    return array_values(array_filter($rows, fn (array $row) => !isset($row['value']) || ($row['value'] !== '0' && $row['value'] !== '')));
  }

  protected function panel(string|\Stringable $title, array $rows, string $more_url): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title],
      '#slots' => [
        'content' => [
          '#type' => 'component',
          '#component' => 'divengine_core:fact-list',
          '#props' => ['rows' => $rows, 'empty' => (string) $this->t('Nothing to report.')],
        ],
        'actions' => Link::fromTextAndUrl($this->t('Open'), Url::fromUserInput($more_url))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]],
      ],
    ];
  }

  protected function section(string|\Stringable $title, int $count, array $content, array $actions = []): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => (string) $count],
      '#slots' => array_filter([
        'content' => $content,
        'actions' => $actions ? $this->actionsContainer($actions) : NULL,
      ]),
    ];
  }

  protected function table(array $header, array $rows, string|\Stringable $empty): array {
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $empty,
    ];
  }

  protected function button(string|\Stringable $label, Url $url, bool $primary = FALSE): array {
    $classes = ['button', 'button--small'];
    if ($primary) {
      $classes[] = 'button--primary';
    }
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => $classes]];
  }

  protected function actionsContainer(array $actions): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['divengine-process-actions']],
      'actions' => $actions,
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_qa\QaMetrics;
use Drupal\divengine_qa\QaPermissions;
use Drupal\divengine_qa\QaVocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * QA lists: the Quality tab of a Development, all plans and my runs.
 */
class QaPagesController extends ControllerBase {

  protected QaMetrics $metrics;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->metrics = $container->get('divengine_qa.metrics');
    return $instance;
  }

  public function developmentTitle(DevelopmentInterface $development): string {
    return (string) $this->t('@development: Quality', ['@development' => $development->label()]);
  }

  /**
   * The test plans and runs of a Development.
   */
  public function development(DevelopmentInterface $development): array {
    $plans = $this->plansQuery()->condition('development', $development->id())->execute();
    $plans = $this->entityTypeManager->getStorage('qa_test_plan')->loadMultiple($plans);
    $runs = $this->runsQuery()->condition('development', $development->id())->sort('created', 'DESC')->execute();
    $runs = $this->entityTypeManager->getStorage('qa_test_run')->loadMultiple($runs);

    $states = $this->metrics->executionStates(array_keys(array_filter($runs, fn ($run) => $run->getState() !== 'cancelled')));
    $figures = QaMetrics::calculate($states);
    $build = $this->cacheable();
    $build['intro'] = ['#markup' => '<p class="dv-intro">' . $this->t('Test plans describe what to verify; each test run executes a frozen copy of the Ready test cases and records the results.') . '</p>'];
    $build['cards'] = $this->cards([
      [$this->t('Test Plans'), (string) count($plans), (string) $this->t('@active Active', ['@active' => count(array_filter($plans, fn ($p) => $p->getState() === 'active'))]), 'neutral'],
      [$this->t('Runs In Progress'), (string) count(array_filter($runs, fn ($r) => $r->getState() === 'in_progress')), '', 'info'],
      [$this->t('Executions'), (string) $figures['total'], (string) $this->t('@pending pending', ['@pending' => $figures['pending']]), 'neutral'],
      [$this->t('Pass Rate'), number_format($figures['pass_rate'], 1) . '%', (string) $this->t('Cancelled runs not counted'), $figures['pass_rate'] >= 80 ? 'success' : ($figures['pass_rate'] >= 50 ? 'warning' : 'danger')],
    ]);
    $add = QaPermissions::isLead($this->currentUser()) && $development->access('update')
      ? $this->button($this->t('New Test Plan'), Url::fromRoute('entity.qa_test_plan.add_form', [], ['query' => ['development' => $development->id()]]), TRUE)
      : NULL;
    $build['plans'] = $this->section($this->t('Test Plans'), count($plans), $this->planTable($plans, FALSE), $add);
    $build['runs'] = $this->section($this->t('Test Runs'), count($runs), $this->runTable($runs));
    return $build;
  }

  /**
   * Every test plan the user may see, filtered by state.
   */
  public function plans(Request $request): array {
    $state = $request->query->get('state', 'open');
    $query = $this->plansQuery();
    if ($state === 'open') {
      $query->condition('state', 'closed', '<>');
    }
    elseif (isset(QaVocabulary::planStates()[$state])) {
      $query->condition('state', $state);
    }
    $plans = $this->entityTypeManager->getStorage('qa_test_plan')->loadMultiple($query->execute());
    $build = $this->cacheable();
    $build['#cache']['contexts'][] = 'url.query_args:state';
    $filters = ['open' => $this->t('Open'), 'all' => $this->t('All')] + QaVocabulary::planStates();
    $links = [];
    foreach ($filters as $key => $label) {
      $links[] = $this->button($label, Url::fromRoute('divengine_qa.plans', [], ['query' => ['state' => $key]]), $key === $state);
    }
    $build['filters'] = ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions', 'dv-filters']], 'links' => $links];
    $build['plans'] = $this->section($this->t('Test Plans'), count($plans), $this->planTable($plans, TRUE));
    return $build;
  }

  /**
   * The runs assigned to the user: open first, then the latest finished.
   */
  public function myRuns(): array {
    $uid = (int) $this->currentUser()->id();
    $storage = $this->entityTypeManager->getStorage('qa_test_run');
    $open = $storage->loadMultiple($this->runsQuery()->condition('tester', $uid)->condition('state', ['draft', 'in_progress'], 'IN')->sort('created', 'DESC')->execute());
    $done = $storage->loadMultiple($this->runsQuery()->condition('tester', $uid)->condition('state', ['completed', 'cancelled'], 'IN')->sort('changed', 'DESC')->range(0, 20)->execute());
    $build = $this->cacheable();
    $add = QaPermissions::isTester($this->currentUser())
      ? $this->button($this->t('New Test Run'), Url::fromRoute('divengine_qa.my_run'), TRUE)
      : NULL;
    $build['open'] = $this->section($this->t('To execute'), count($open), $this->runTable($open, $this->t('No open run is assigned to you.')), $add);
    $build['done'] = $this->section($this->t('Recently finished'), count($done), $this->runTable($done, $this->t('No finished run yet.')));
    return $build;
  }

  protected function plansQuery() {
    return $this->entityTypeManager->getStorage('qa_test_plan')->getQuery()->accessCheck(TRUE)->sort('created', 'DESC')->sort('id', 'DESC');
  }

  protected function runsQuery() {
    return $this->entityTypeManager->getStorage('qa_test_run')->getQuery()->accessCheck(TRUE);
  }

  protected function planTable(array $plans, bool $with_development): array {
    $rows = [];
    foreach ($plans as $plan) {
      $figures = $this->metrics->plan($plan);
      $row = [
        ['data' => ['#plain_text' => (string) $plan->get('code')->value]],
        ['data' => $plan->toLink($plan->get('name')->value)->toRenderable()],
      ];
      if ($with_development) {
        $row[] = ['data' => ['#plain_text' => (string) $plan->get('development')->entity?->label()]];
      }
      $rows[] = array_merge($row, [
        ['data' => ['#plain_text' => (string) $plan->get('environment')->entity?->label()]],
        ['data' => ['#plain_text' => (string) $plan->get('stage')->entity?->label()]],
        ['data' => $this->badge($plan->getStateLabel(), QaVocabulary::tone($plan->getState()))],
        ['data' => ['#plain_text' => $figures['ready_cases'] . ' / ' . $figures['cases']]],
        ['data' => ['#plain_text' => (string) $figures['runs']]],
        ['data' => ['#plain_text' => number_format($figures['pass_rate'], 1) . '%']],
      ]);
    }
    $header = [$this->t('Reference'), $this->t('Test Plan')];
    if ($with_development) {
      $header[] = $this->t('Development');
    }
    $header = array_merge($header, [$this->t('Environment'), $this->t('Stage'), $this->t('Status'), $this->t('Ready Cases'), $this->t('Runs'), $this->t('Pass Rate')]);
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $this->t('No test plan yet.')];
  }

  protected function runTable(array $runs, $empty = NULL): array {
    $rows = [];
    foreach ($runs as $run) {
      $rows[] = [
        ['data' => ['#plain_text' => (string) $run->get('code')->value]],
        ['data' => $run->toLink($run->get('name')->value)->toRenderable()],
        ['data' => ['#plain_text' => (string) $run->get('plan')->entity?->label()]],
        ['data' => ['#plain_text' => (string) $run->get('tester')->entity?->getDisplayName()]],
        ['data' => $this->badge($run->getStateLabel(), QaVocabulary::tone($run->getState()))],
        ['data' => ['#plain_text' => number_format((float) $run->get('execution_progress')->value, 1) . '%']],
        ['data' => ['#plain_text' => number_format((float) $run->get('pass_rate')->value, 1) . '%']],
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [$this->t('Reference'), $this->t('Run'), $this->t('Test Plan'), $this->t('Tester'), $this->t('Status'), $this->t('Progress'), $this->t('Pass Rate')],
      '#rows' => $rows,
      '#empty' => $empty ?? $this->t('No test run yet.'),
    ];
  }

  protected function cacheable(): array {
    return ['#attached' => ['library' => ['divengine_qa/qa']], '#cache' => [
      'tags' => ['qa_test_plan_list', 'qa_test_run_list', 'qa_test_case_list', 'qa_test_execution_list', 'development_list'],
      'contexts' => ['user'],
    ]];
  }

  protected function cards(array $cards): array {
    $build = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];
    foreach ($cards as $i => [$label, $value, $hint, $tone]) {
      $build[$i] = ['#type' => 'component', '#component' => 'divengine_core:stat-card', '#props' => array_filter(['label' => (string) $label, 'value' => $value, 'hint' => $hint, 'tone' => $tone], fn ($v) => $v !== NULL && $v !== '')];
    }
    return $build;
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function badge($label, string $tone): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => (string) $label, 'tone' => $tone]];
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_process\DevelopmentProcess;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessEvaluator;
use Drupal\divengine_process\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Readiness tab: what is met and what is missing, overall and per
 * milestone. Advisory: nothing here blocks a transition.
 */
class ReadinessController extends ControllerBase {

  public function __construct(
    protected ReadinessEvaluator $readiness,
    protected DevelopmentProcess $process,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('divengine_process.readiness'),
      $container->get('divengine_process.development'),
    );
  }

  public function page(DevelopmentInterface $development): array {
    $build = [
      '#cache' => [
        'tags' => ['artifact_list', 'process_activity_list', 'change_request_list', 'iteration_list', 'role_assignment_list', 'trace_link_list', 'milestone_list'],
        'contexts' => ['user'],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Readiness explains, criterion by criterion, what the Development Case asks for and what is still missing. It is advisory: reaching a milestone is a human decision and nothing here blocks it.'),
        '#attributes' => ['class' => ['dv-intro']],
      ],
    ];
    $overall = $this->readiness->evaluate($development);
    $build['overall'] = $this->section((string) $this->t('Whole Development'), $overall);
    foreach ($this->process->children($development, 'milestone') as $milestone) {
      $items = $this->readiness->evaluate($development, $milestone->getMilestoneType());
      $title = (string) $this->t('@milestone milestone', ['@milestone' => $milestone->label()]);
      if ($milestone->getState() === 'reached') {
        $title .= ' · ' . $this->t('reached');
      }
      $build['milestone_' . $milestone->id()] = $this->section($title, $items);
    }
    return $build;
  }

  public function title(DevelopmentInterface $development): string {
    return (string) $this->t('@development: readiness', ['@development' => $development->label()]);
  }

  /**
   * One evaluation as a section with a row per criterion.
   */
  protected function section(string $title, array $items): array {
    $levels = Vocabulary::levels();
    $rows = [];
    foreach ($items as $item) {
      [$status, $tone] = match (TRUE) {
        $item['met'] => [$this->t('Met'), 'success'],
        $item['level'] === 'required' => [$this->t('Missing'), 'danger'],
        default => [$this->t('Not yet'), 'warning'],
      };
      $rows[] = [
        ['data' => $this->badge((string) $status, $tone)],
        ['data' => ['#plain_text' => $item['label']]],
        ['data' => $this->badge((string) ($levels[$item['level']] ?? $item['level']), $item['level'] === 'required' ? 'info' : 'neutral')],
        ['data' => $item['reasons'] ? [
          '#theme' => 'item_list',
          // Reasons are already escaped by t().
          '#items' => array_map(fn (string $reason) => ['#markup' => $reason], $item['reasons']),
        ] : []],
      ];
    }
    $missing = $this->readiness->missingCount($items);
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => [
        'title' => $title,
        'count' => $missing ? (string) $this->formatPlural($missing, '1 required criterion not met', '@count required criteria not met') : (string) $this->t('all required criteria met'),
      ],
      '#slots' => [
        'content' => [
          '#type' => 'table',
          '#header' => ['', $this->t('Criterion'), $this->t('Level'), $this->t('What is missing')],
          '#rows' => $rows,
          '#empty' => $this->t('No readiness criterion applies: add readiness lines to the Development Case.'),
        ],
      ],
    ];
  }

  protected function badge(string $label, string $tone): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:badge',
      '#props' => ['label' => $label, 'tone' => $tone],
    ];
  }

}

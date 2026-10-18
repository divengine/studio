<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The tabs of a Development: business model, requirements, process, changes.
 *
 * Each tab lists one section per kind of record, with its list and an "Add"
 * button that files the new record under the Development and comes back.
 */
class DevelopmentSectionController extends ControllerBase {

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Shows the sections of one tab.
   */
  public function section(DevelopmentInterface $development, string $section): array {
    $build = [
      '#cache' => [
        'tags' => ['artifact_list', 'process_activity_list', 'change_request_list', 'baseline_list', 'config:artifact_type_list'],
        'contexts' => ['user', 'url.path'],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->intro($section),
        '#attributes' => ['class' => ['dv-intro']],
      ],
    ];
    $destination = Url::fromRoute('<current>')->toString();
    $can_add = $development->access('update');

    foreach ($this->sections($section) as $key => $definition) {
      $count = $this->entityTypeManager->getStorage($definition['entity_type'])->getQuery()
        ->accessCheck(TRUE)
        ->condition('development', $development->id());
      if (isset($definition['bundle'])) {
        $count->condition('type', $definition['bundle']);
      }
      $parameters = array_map(fn ($value) => $value ?? $development->id(), $definition['add_parameters'] ?? []);
      $add = Url::fromRoute($definition['add_route'], $parameters, [
        'query' => ['development' => $development->id(), 'destination' => $destination],
      ]);
      $actions = NULL;
      if ($can_add && $add->access()) {
        $actions = Link::fromTextAndUrl($definition['add_label'], $add)->toRenderable()
          + ['#attributes' => ['class' => ['button', 'button--small', 'button--primary']]];
      }
      $build[$key] = [
        '#type' => 'component',
        '#component' => 'divengine_core:section',
        '#props' => ['title' => (string) $definition['title'], 'count' => (string) $count->count()->execute()],
        '#slots' => array_filter([
          'content' => [
            'description' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $definition['description'],
              '#attributes' => ['class' => ['dv-section__description']],
            ],
            'list' => [
              '#type' => 'view',
              '#name' => $definition['view'][0],
              '#display_id' => $definition['view'][1],
              '#arguments' => [$development->id()],
            ],
          ],
          'actions' => $actions,
        ]),
      ];
    }
    return $build;
  }

  public function title(DevelopmentInterface $development, string $section): string {
    return (string) $this->t('@development: @section', [
      '@development' => $development->label(),
      '@section' => mb_strtolower((string) $this->tabLabel($section)),
    ]);
  }

  /**
   * The sections of a tab, keyed by machine name.
   *
   * @return array<string, array<string, mixed>>
   */
  protected function sections(string $tab): array {
    $sections = [];
    foreach (array_keys(array_filter(Vocabulary::scopedArtifactTypes(), fn ($s) => $s === $tab)) as $bundle) {
      $type = $this->entityTypeManager->getStorage('artifact_type')->load($bundle);
      if (!$type) {
        continue;
      }
      $sections[$bundle] = [
        'entity_type' => 'artifact',
        'bundle' => $bundle,
        'title' => $this->artifactSectionTitle($bundle),
        'description' => (string) $type->get('description'),
        'view' => ['divengine_process_records', $bundle],
        'add_route' => 'entity.artifact.add_form',
        'add_parameters' => ['artifact_type' => $bundle],
        'add_label' => $this->t('Add @type', ['@type' => mb_strtolower((string) $type->label())]),
      ];
    }
    if ($tab === 'process') {
      $sections = [
        'activities' => [
          'entity_type' => 'process_activity',
          'title' => $this->t('Activities'),
          'description' => $this->t('The work actually done in this Development, iteration by iteration. Picking a definition proposes the discipline, the responsible role and the outputs to produce.'),
          'view' => ['divengine_process_activities', 'embed_1'],
          'add_route' => 'entity.process_activity.add_form',
          'add_label' => $this->t('Plan an activity'),
        ],
      ] + $sections;
    }
    if ($tab === 'baselines') {
      $sections['baselines'] = [
        'entity_type' => 'baseline',
        'title' => $this->t('Baselines'),
        'description' => $this->t('Each baseline records the exact revisions of the baselined records and their traces, so that state can always be reproduced. Reaching a milestone takes one.'),
        'view' => ['divengine_process_baselines', 'embed_1'],
        'add_route' => 'divengine_process.take_baseline',
        'add_parameters' => ['development' => NULL],
        'add_label' => $this->t('Take a baseline'),
      ];
    }
    if ($tab === 'changes') {
      $sections['change_requests'] = [
        'entity_type' => 'change_request',
        'title' => $this->t('Change requests'),
        'description' => $this->t('Changes to what was already agreed: triaged, decided with a rationale, scheduled into an iteration, implemented, verified and closed.'),
        'view' => ['divengine_process_change_requests', 'embed_1'],
        'add_route' => 'entity.change_request.add_form',
        'add_label' => $this->t('Submit a change request'),
      ];
    }
    return $sections;
  }

  protected function intro(string $tab): TranslatableMarkup {
    return match ($tab) {
      'business' => $this->t('The business as it is and as it should be, whether or not software exists: who takes part, what the business must accomplish and the rules it follows.'),
      'requirements' => $this->t('What the software must do: the people and systems that use it, how they use it and the requirements it must satisfy.'),
      'process' => $this->t('How the Development is carried out: the activities of each iteration and the work products they produce.'),
      'baselines' => $this->t('Traces point to records; baselines point to the exact revisions that were in force. A revision held by a baseline is never deleted.'),
      default => $this->t('Every change to what was already agreed goes through a decision with a rationale, and its impact is traced.'),
    };
  }

  protected function tabLabel(string $tab): TranslatableMarkup {
    return match ($tab) {
      'business' => $this->t('Business model'),
      'requirements' => $this->t('Requirements'),
      'process' => $this->t('Process'),
      'baselines' => $this->t('Baselines'),
      default => $this->t('Changes'),
    };
  }

  protected function artifactSectionTitle(string $bundle): TranslatableMarkup {
    return match ($bundle) {
      'business_use_case' => $this->t('Business use cases'),
      'business_rule' => $this->t('Business rules'),
      'business_actor' => $this->t('Business actors'),
      'business_worker' => $this->t('Business workers'),
      'requirement' => $this->t('Requirements'),
      'use_case' => $this->t('System use cases'),
      'system_actor' => $this->t('System actors'),
      'work_product' => $this->t('Work products'),
      default => $this->t('Records'),
    };
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Development's model sections: Business model and Requirements.
 *
 * Each record type gets a section with its list and an "Add" button that
 * attaches the new record to the Development and returns here.
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
        'tags' => ['artifact_list', 'config:artifact_type_list'],
        'contexts' => ['user', 'url.path'],
      ],
    ];
    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $section === 'business'
        ? $this->t('The business as it is and as it should be, whether or not software exists: who takes part, what the business must accomplish and the rules it follows.')
        : $this->t('What the software must do: the people and systems that use it, how they use it and the requirements it must satisfy.'),
      '#attributes' => ['class' => ['dv-intro']],
    ];
    $destination = Url::fromRoute('<current>')->toString();
    $can_add = $development->access('update');
    foreach (array_keys(array_filter(Vocabulary::scopedArtifactTypes(), fn ($s) => $s === $section)) as $bundle) {
      $type = $this->entityTypeManager->getStorage('artifact_type')->load($bundle);
      if (!$type) {
        continue;
      }
      $count = $this->entityTypeManager->getStorage('artifact')->getQuery()
        ->accessCheck(TRUE)
        ->condition('development', $development->id())
        ->condition('type', $bundle)
        ->count()
        ->execute();
      $actions = [];
      $add = Url::fromRoute('entity.artifact.add_form', ['artifact_type' => $bundle], [
        'query' => ['development' => $development->id(), 'destination' => $destination],
      ]);
      if ($can_add && $add->access()) {
        $actions = Link::fromTextAndUrl($this->t('Add @type', ['@type' => mb_strtolower((string) $type->label())]), $add)->toRenderable()
          + ['#attributes' => ['class' => ['button', 'button--small', 'button--primary']]];
      }
      $build[$bundle] = [
        '#type' => 'component',
        '#component' => 'divengine_core:section',
        '#props' => ['title' => (string) $this->sectionTitle($bundle), 'count' => (string) $count],
        '#slots' => array_filter([
          'content' => [
            'description' => ['#markup' => '<p class="dv-section__description">' . $type->get('description') . '</p>'],
            'list' => [
              '#type' => 'view',
              '#name' => 'divengine_process_records',
              '#display_id' => $bundle,
              '#arguments' => [$development->id()],
            ],
          ],
          'actions' => $actions ?: NULL,
        ]),
      ];
    }
    return $build;
  }

  public function title(DevelopmentInterface $development, string $section): string {
    return (string) ($section === 'business'
      ? $this->t('@development: business model', ['@development' => $development->label()])
      : $this->t('@development: requirements', ['@development' => $development->label()]));
  }

  protected function sectionTitle(string $bundle) {
    return match ($bundle) {
      'business_use_case' => $this->t('Business use cases'),
      'business_rule' => $this->t('Business rules'),
      'business_actor' => $this->t('Business actors'),
      'business_worker' => $this->t('Business workers'),
      'requirement' => $this->t('Requirements'),
      'use_case' => $this->t('System use cases'),
      'system_actor' => $this->t('System actors'),
      default => $bundle,
    };
  }

}

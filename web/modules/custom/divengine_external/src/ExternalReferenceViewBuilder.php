<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Url;

/**
 * The page of an external reference: what it is, where it opens, and the
 * records it is traced to (the Traces panel).
 */
class ExternalReferenceViewBuilder extends EntityViewBuilder {

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    $providers = \Drupal::service('plugin.manager.divengine_external_provider');
    /** @var \Drupal\divengine_external\Entity\ExternalReference $reference */
    foreach ($entities as $id => $reference) {
      foreach (array_keys($reference->getFieldDefinitions()) as $field_name) {
        unset($build[$id][$field_name]);
      }
      $kind = $providers->kindLabel((string) $reference->get('provider')->value, (string) $reference->get('kind')->value);
      $actions = [
        // Opens the external work; the link was normalized when registered
        // and only http(s) links are stored.
        'open' => [
          '#type' => 'link',
          '#title' => $this->t('Open'),
          '#url' => Url::fromUri($reference->getUrl()),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--small'], 'rel' => 'noopener noreferrer nofollow', 'target' => '_blank'],
        ],
      ];
      if ($reference->access('update')) {
        $actions['edit'] = ['#type' => 'link', '#title' => $this->t('Edit'), '#url' => $reference->toUrl('edit-form'), '#attributes' => ['class' => ['button', 'button--small']]];
      }
      $facts = array_filter([
        (string) $this->t('Kind') => $kind,
        (string) $this->t('Development') => $reference->getDevelopment()?->label(),
        (string) $this->t('Link') => $reference->getUrl(),
        (string) $this->t('Registered by') => $reference->getOwner()?->getDisplayName(),
      ]);
      $build[$id]['status'] = [
        '#type' => 'component',
        '#component' => 'divengine_core:status-bar',
        '#props' => [
          'code' => $kind,
          'facts' => array_map(fn ($label, $value) => ['label' => $label, 'value' => (string) $value], array_keys($facts), $facts),
        ],
        '#slots' => ['actions' => ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'actions' => $actions]],
        '#weight' => -100,
      ];
      $build[$id]['notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Studio stores this reference, not the external work: its title, state and discussion are on the provider. Records approved in Studio stay the source of truth.'),
        '#attributes' => ['class' => ['description']],
        '#weight' => -90,
      ];
      $notes = trim((string) $reference->get('notes')->value);
      if ($notes !== '') {
        $build[$id]['notes'] = [
          '#type' => 'component',
          '#component' => 'divengine_core:section',
          '#props' => ['title' => (string) $this->t('Notes')],
          '#slots' => ['content' => ['#plain_text' => $notes]],
          '#weight' => -80,
        ];
      }
    }
  }

}

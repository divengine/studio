<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Url;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\ExternalObservation;

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
          'code' => (string) $reference->get('code')->value,
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
      $build[$id]['observed'] = $this->observed($reference) + ['#weight' => -85];
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

  /**
   * What the provider last reported, and the Refresh button.
   *
   * Shown as observed provider state, with its date, apart from Studio's
   * own data.
   */
  protected function observed(ExternalReference $reference): array {
    $observations = \Drupal::service('divengine_external.observations');
    $last = $observations->get($reference);
    $enabled = $observations->isEnabled($reference);
    if (!$last && !$enabled) {
      return [];
    }
    $provider = (string) \Drupal::service('plugin.manager.divengine_external_provider')->getDefinition((string) $reference->get('provider')->value)['label'];
    $formatter = \Drupal::service('date.formatter');
    $facts = [];
    if ($last && $last->status === ExternalObservation::OK) {
      $facts = array_filter([
        (string) $this->t('Title') => $last->title,
        (string) $this->t('State') => $last->state,
        (string) $this->t('Changed on @provider', ['@provider' => $provider]) => $last->updated ? $formatter->format((int) $last->updated, 'short') : NULL,
      ]);
    }
    $lines = [];
    foreach ($facts as $label => $value) {
      $lines[] = ['#type' => 'html_tag', '#tag' => 'div', 'label' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $label . ': '], 'value' => ['#plain_text' => (string) $value]];
    }
    if ($last && $last->note !== '') {
      $lines[] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('@note', ['@note' => $last->note])];
    }
    $lines[] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['description']], '#value' => $last
      ? $this->t('Observed on @provider on @date. This is the state on @provider, not a Studio decision.', ['@provider' => $provider, '@date' => $formatter->format((int) $last->observed, 'short')])
      : $this->t('Not read from @provider yet.', ['@provider' => $provider]),
    ];
    $actions = NULL;
    if ($enabled && $reference->access('update')) {
      $actions = [
        '#type' => 'link',
        '#title' => $this->t('Refresh from @provider', ['@provider' => $provider]),
        '#url' => Url::fromRoute('divengine_external.refresh', ['external_reference' => $reference->id()]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $this->t('Observed on @provider', ['@provider' => $provider])],
      '#slots' => array_filter(['content' => $lines, 'actions' => $actions]),
      '#cache' => ['tags' => ['config:divengine_external.settings']],
    ];
  }

}

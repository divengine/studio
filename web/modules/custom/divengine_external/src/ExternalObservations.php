<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\divengine_external\Entity\ExternalReference;

/**
 * The state of external work as its provider last reported it.
 *
 * Only asked for on request (the Refresh button), never in the background,
 * and only when the site allows it. Each reference is asked at most once a
 * minute; while a provider asks Studio to wait, nobody asks it. The answer
 * is stored apart from the reference: it is the provider's state, not a
 * Studio record, and creates no revision.
 */
class ExternalObservations {

  /**
   * Seconds before the same reference may be asked about again.
   */
  const MIN_INTERVAL = 60;

  public function __construct(
    protected Connection $database,
    protected ExternalProviderManager $providers,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Whether the site lets Studio ask the reference's provider.
   */
  public function isEnabled(ExternalReference $reference): bool {
    $provider = (string) $reference->get('provider')->value;
    return $provider === 'github'
      && (bool) $this->configFactory->get('divengine_external.settings')->get('observe_github')
      && $this->providers->hasDefinition($provider)
      && $this->providers->createInstance($provider) instanceof ObservableProviderInterface;
  }

  /**
   * The last observation stored for a reference, if any.
   *
   * @return object|null
   *   A row with status, title, state, updated, note, observed, observed_by.
   */
  public function get(ExternalReference $reference): ?object {
    $row = $this->database->select('divengine_external_observation', 'o')
      ->fields('o')
      ->condition('reference_id', $reference->id())
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * When the reference may be asked about again; NULL when it may now.
   */
  public function nextAllowed(ExternalReference $reference): ?int {
    $now = $this->time->getCurrentTime();
    $next = 0;
    $last = $this->get($reference);
    if ($last) {
      $next = (int) $last->observed + static::MIN_INTERVAL;
    }
    $next = max($next, (int) $this->state->get($this->waitKey($reference), 0));
    return $next > $now ? $next : NULL;
  }

  /**
   * Asks the provider now and stores its answer.
   *
   * @return \Drupal\divengine_external\ExternalObservation|null
   *   The answer, or NULL when the provider was not asked: lookups are off,
   *   the provider cannot be asked, or it is too early.
   */
  public function refresh(ExternalReference $reference, AccountInterface $account): ?ExternalObservation {
    if (!$this->isEnabled($reference) || $this->nextAllowed($reference) !== NULL) {
      return NULL;
    }
    /** @var \Drupal\divengine_external\ObservableProviderInterface $provider */
    $provider = $this->providers->createInstance((string) $reference->get('provider')->value);
    $observation = $provider->observe($reference);
    if ($observation->status === ExternalObservation::RATE_LIMITED) {
      $this->state->set($this->waitKey($reference), $observation->retryAfter);
    }
    $this->database->merge('divengine_external_observation')
      ->key('reference_id', $reference->id())
      ->fields([
        'status' => $observation->status,
        'title' => $observation->title,
        'state' => $observation->state,
        'updated' => $observation->updated,
        'note' => mb_substr($observation->note, 0, 255),
        'observed' => $this->time->getCurrentTime(),
        'observed_by' => (int) $account->id(),
      ])
      ->execute();
    $this->cacheTagsInvalidator->invalidateTags($reference->getCacheTags());
    return $observation;
  }

  /**
   * Forgets what was observed about deleted references.
   *
   * @param int[] $ids
   *   Reference IDs.
   */
  public function forget(array $ids): void {
    if ($ids) {
      $this->database->delete('divengine_external_observation')->condition('reference_id', $ids, 'IN')->execute();
    }
  }

  /**
   * The state key holding until when the reference's provider asked to wait.
   */
  protected function waitKey(ExternalReference $reference): string {
    return 'divengine_external.wait.' . $reference->get('provider')->value;
  }

}

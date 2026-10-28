<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\ExternalObservation;
use Drupal\divengine_external\ExternalObservations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Asks the provider about external work, on request.
 */
class ExternalReferenceController extends ControllerBase {

  protected ExternalObservations $observations;

  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->observations = $container->get('divengine_external.observations');
    return $instance;
  }

  public function refresh(ExternalReference $external_reference): RedirectResponse {
    $observation = $this->observations->refresh($external_reference, $this->currentUser());
    match ($observation?->status) {
      NULL => $this->messenger()->addWarning($this->observations->isEnabled($external_reference)
        ? $this->t('It was asked about recently. Try again in a minute.')
        : $this->t('Studio does not ask this provider about external work.')),
      ExternalObservation::OK => $this->messenger()->addStatus($this->t('Read the current state from the provider.')),
      default => $this->messenger()->addWarning($observation->note),
    };
    return new RedirectResponse($external_reference->toUrl()->toString());
  }

}

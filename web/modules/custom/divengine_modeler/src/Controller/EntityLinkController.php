<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_modeler\EntityReferenceResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoints of the entity picker and of links opened from diagrams.
 */
class EntityLinkController extends ControllerBase {

  public function __construct(
    protected EntityReferenceResolver $resolver,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_modeler.entity_resolver'));
  }

  /**
   * Searches link targets by label.
   */
  public function search(Request $request): JsonResponse {
    $text = mb_substr((string) $request->query->get('q', ''), 0, 128);
    return new JsonResponse(['results' => $this->resolver->search($text, $this->currentUser())]);
  }

  /**
   * Resolves a pasted URL, path or entity URI.
   */
  public function resolve(Request $request): JsonResponse {
    $value = mb_substr((string) $request->query->get('value', ''), 0, 2048);
    $entity = $this->resolver->resolve($value, $request->getHttpHost());
    if (!$entity || !$entity->access('view')) {
      return new JsonResponse(['errors' => [(string) $this->t('No record you can view matches "@value".', ['@value' => $value])]], 404);
    }
    return new JsonResponse(['result' => $this->resolver->describe($entity)]);
  }

  /**
   * Opens the target of an element link.
   */
  public function go(Request $request): RedirectResponse {
    $entity = $this->resolver->load((string) $request->query->get('uri', ''));
    if (!$entity || !$entity->hasLinkTemplate('canonical') || !$entity->access('view')) {
      throw new NotFoundHttpException();
    }
    return new RedirectResponse($entity->toUrl()->setAbsolute()->toString(), 302);
  }

}

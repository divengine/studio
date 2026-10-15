<?php

declare(strict_types=1);

namespace Drupal\divengine_diagram_embed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoints of the CKEditor 5 diagram button: search and lookup by UUID.
 */
class DiagramEmbedController extends ControllerBase {

  const LIMIT = 20;

  /**
   * Diagrams the user can view whose label contains the text.
   */
  public function search(Request $request): JsonResponse {
    $text = trim(mb_substr((string) $request->query->get('q', ''), 0, 128));
    $storage = $this->entityTypeManager()->getStorage('diagram');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC')->range(0, static::LIMIT * 5);
    if ($text !== '') {
      $query->condition('label', $text, 'CONTAINS');
    }
    $results = [];
    foreach ($storage->loadMultiple($query->execute()) as $diagram) {
      if (count($results) === static::LIMIT) {
        break;
      }
      if ($diagram->access('view')) {
        $results[] = $this->describe($diagram);
      }
    }
    return new JsonResponse(['results' => $results]);
  }

  /**
   * One diagram by UUID, as the editor shows it inside the text.
   */
  public function lookup(Request $request): JsonResponse {
    $uuid = (string) $request->query->get('uuid', '');
    $diagrams = $uuid === '' ? [] : $this->entityTypeManager()->getStorage('diagram')->loadByProperties(['uuid' => $uuid]);
    $diagram = reset($diagrams);
    if (!$diagram instanceof DiagramInterface) {
      return new JsonResponse(['state' => 'missing'], 404);
    }
    if (!$diagram->access('view')) {
      return new JsonResponse(['state' => 'restricted'], 403);
    }
    return new JsonResponse(['result' => $this->describe($diagram)]);
  }

  /**
   * What the editor needs to show a diagram.
   */
  protected function describe(DiagramInterface $diagram): array {
    $has_preview = $diagram->getPreviewSvg() !== '';
    return [
      'uuid' => $diagram->uuid(),
      'label' => $diagram->label(),
      'type' => (string) ($diagram->get('type')->entity?->label() ?? $diagram->bundle()),
      'url' => $diagram->toUrl()->toString(),
      'previewUrl' => $has_preview ? Url::fromRoute('divengine_modeler.preview', [
        'diagram' => $diagram->id(),
        'file' => $diagram->getPreviewHash() . '.svg',
      ], ['query' => ['fit' => 1]])->toString() : NULL,
    ];
  }

}

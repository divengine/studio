<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\divengine_modeler\DiagramModelSaver;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use Drupal\divengine_modeler\Exception\InvalidModelException;
use Drupal\divengine_modeler\PreviewBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The modeler page, its save endpoint and the preview endpoints.
 */
class DiagramModelerController extends ControllerBase {

  /**
   * Largest accepted request body: source, SVG and bindings together.
   */
  const MAX_REQUEST_BYTES = 8_000_000;

  public function __construct(
    protected DiagramModelSaver $saver,
    protected PreviewBuilder $previewBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('divengine_modeler.model_saver'),
      $container->get('divengine_modeler.preview_builder'),
    );
  }

  public function editorTitle(DiagramInterface $diagram): string {
    return (string) $this->t('Modeler: @label', ['@label' => $diagram->label()]);
  }

  /**
   * The full-screen editor of the diagram's latest revision.
   */
  public function editor(DiagramInterface $diagram): array {
    $notation = $diagram->getNotation();
    $id = (int) $diagram->id();
    $settings = [
      'diagramId' => $id,
      'revisionId' => (int) $diagram->getRevisionId(),
      'label' => $diagram->label(),
      'notation' => $notation->getPluginId(),
      'notationLabel' => $notation->label(),
      'family' => $notation->family(),
      'fileExtension' => $notation->fileExtension(),
      'source' => $diagram->getSource(),
      'bindings' => $diagram->getBindings() ?: new \stdClass(),
      'state' => $diagram->hasField('moderation_state') ? $diagram->get('moderation_state')->value : NULL,
      'urls' => [
        'save' => Url::fromRoute('divengine_modeler.save_model', ['diagram' => $id])->toString(),
        'canonical' => $diagram->toUrl()->toString(),
        'token' => Url::fromRoute('system.csrftoken')->toString(),
        'search' => Url::fromRoute('divengine_modeler.entity_search')->toString(),
        'resolve' => Url::fromRoute('divengine_modeler.entity_resolve')->toString(),
        'go' => Url::fromRoute('divengine_modeler.entity_go')->toString(),
      ],
      'settings' => $notation->clientSettings($diagram),
    ];
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['divengine-modeler', 'divengine-modeler--' . Html::getClass($notation->family())],
        'data-divengine-modeler' => $id,
      ],
      'fallback' => [
        '#type' => 'html_tag',
        '#tag' => 'noscript',
        '#value' => $this->t('The modeler needs JavaScript.'),
      ],
      '#attached' => [
        'library' => [$notation->editorLibrary()],
        'drupalSettings' => ['divengineModeler' => ['editors' => [$id => $settings]]],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $diagram->getCacheTags(),
      ],
    ];
  }

  /**
   * Saves a model sent by the editor as a new revision.
   */
  public function save(Request $request, DiagramInterface $diagram): JsonResponse {
    $payload = $this->decode($request);
    if ($payload === NULL || !is_string($payload['source'] ?? NULL)) {
      return new JsonResponse(['errors' => [(string) $this->t('The request must be a JSON object with a "source" string.')]], 400);
    }
    $base = (int) ($payload['baseRevisionId'] ?? 0);
    if ($base && $base !== (int) $diagram->getRevisionId() && empty($payload['force'])) {
      return new JsonResponse([
        'errors' => [(string) $this->t('Someone saved this diagram after you opened it. Reload to see their version, or save again to replace it.')],
        'conflict' => TRUE,
        'revisionId' => (int) $diagram->getRevisionId(),
      ], 409);
    }
    $svg = is_string($payload['svg'] ?? NULL) ? $payload['svg'] : NULL;
    $bindings = is_array($payload['bindings'] ?? NULL) ? $payload['bindings'] : [];
    $log = is_string($payload['log'] ?? NULL) ? trim($payload['log']) : '';
    try {
      $saved = $this->saver->save($diagram, $payload['source'], $svg, $bindings, $log, $this->currentUser());
    }
    catch (InvalidModelException $e) {
      return new JsonResponse(['errors' => $e->getErrors()], 422);
    }
    return new JsonResponse([
      'status' => 'saved',
      'revisionId' => (int) $saved->getRevisionId(),
      'state' => $saved->hasField('moderation_state') ? $saved->get('moderation_state')->value : NULL,
      'previewFresh' => $saved->hasFreshPreview(),
      'message' => (string) $this->t('Saved as revision @id.', ['@id' => $saved->getRevisionId()]),
    ]);
  }

  /**
   * Stores a preview rendered off-screen by a viewer who may edit.
   */
  public function storePreview(Request $request, DiagramInterface $diagram): JsonResponse {
    $payload = $this->decode($request);
    if (!is_string($payload['svg'] ?? NULL) || !is_string($payload['hash'] ?? NULL)) {
      return new JsonResponse(['errors' => [(string) $this->t('The request must contain "svg" and "hash" strings.')]], 400);
    }
    try {
      $this->saver->storePreview($diagram, $payload['svg'], $payload['hash']);
    }
    catch (InvalidModelException $e) {
      return new JsonResponse(['errors' => $e->getErrors()], 422);
    }
    return new JsonResponse([
      'status' => 'stored',
      'url' => Url::fromRoute('divengine_modeler.preview', [
        'diagram' => $diagram->id(),
        'file' => $diagram->getPreviewHash() . '.svg',
      ])->toString(),
    ]);
  }

  /**
   * Serves the stored preview, isolated from the site.
   */
  public function preview(Request $request, DiagramInterface $diagram, string $file): Response {
    $hash = substr($file, 0, -4);
    if ($diagram->getPreviewSvg() === '' || !hash_equals($diagram->getPreviewHash(), $hash)) {
      throw new NotFoundHttpException();
    }
    $svg = $this->previewBuilder->build($diagram, $this->currentUser());
    if ($svg === '') {
      throw new NotFoundHttpException();
    }
    $response = new Response($svg, 200, [
      'Content-Type' => 'image/svg+xml; charset=utf-8',
      // Inline styles are how engines draw; nothing else may load or run.
      'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox allow-top-navigation-by-user-activation allow-popups",
      'X-Content-Type-Options' => 'nosniff',
    ]);
    $response->setPrivate();
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('must-revalidate');
    if ($request->query->get('download')) {
      $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $diagram->label()) ?: 'diagram';
      $response->headers->set('Content-Disposition', 'attachment; filename="' . trim($name, '-') . '.svg"');
    }
    return $response;
  }

  /**
   * Decodes a JSON request body within the size limit.
   */
  protected function decode(Request $request): ?array {
    $content = $request->getContent();
    if (strlen($content) > static::MAX_REQUEST_BYTES) {
      return NULL;
    }
    try {
      $payload = json_decode($content, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($payload) ? $payload : NULL;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\trace_access_test\Controller;

use Drupal\mcp\Plugin\Mcp\JsonApi;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Test transport; exercises the shipped plugin with the HTTP session account. */
class McpReadController {

  public function read(string $uuid): JsonResponse {
    $reader = JsonApi::create(\Drupal::getContainer(), [], 'jsonapi', []);
    $arguments = ['resource_type' => 'trace_link--trace_link'];
    if ($uuid !== 'all') {
      $arguments['uuid'] = $uuid;
    }
    try {
      return new JsonResponse($reader->executeTool('jsonapi_read', $arguments));
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Read refused.'], 403);
    }
  }

}

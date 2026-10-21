<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_codedocs\Contract\ContractException;
use Drupal\divengine_codedocs\Entity\CodeDocSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** HTTP ingestion and reads for extractors and agents. */
class CodeDocsApiController extends ControllerBase {

  public function write(Request $request, string $operation): JsonResponse {
    return $this->respond(function () use ($request, $operation) {
      $body = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($body) || array_is_list($body)) {
        throw new ContractException('Send a JSON object.');
      }
      $importer = \Drupal::service('divengine_codedocs.importer');
      $result = match ($operation) {
        'import' => $importer->import($body, (string) $request->query->get('format', 'auto'), $request->query->getBoolean('force')),
        'begin' => ['status' => 'uploading', 'snapshot' => $importer->begin($body['header'] ?? [], $body['chunks'] ?? 0, $body['format'] ?? 'auto')],
        'chunk' => $importer->append($this->id($body), $this->sequence($body), $body['data'] ?? []),
        'finalize' => $importer->finish($this->id($body), (bool) ($body['force'] ?? FALSE)),
        'discard' => $this->discard($this->id($body)),
        default => throw new ContractException('Unknown operation.'),
      };
      if (isset($result['snapshot'])) {
        $s = $result['snapshot'];
        $result['snapshot'] = ['id' => (int) $s->id(), 'key' => $s->label(), 'content_hash' => $s->get('content_hash')->value, 'url' => $s->isReady() ? $s->toUrl()->toString() : NULL];
      }
      return $result;
    });
  }

  protected function id(array $body): int {
    if (!is_int($body['snapshot'] ?? NULL) || $body['snapshot'] < 1) {
      throw new ContractException('snapshot must be a positive integer.');
    }
    return $body['snapshot'];
  }

  protected function sequence(array $body): int {
    if (!is_int($body['sequence'] ?? NULL)) {
      throw new ContractException('sequence must be an integer.');
    }
    return $body['sequence'];
  }

  protected function discard(int $id): array {
    \Drupal::service('divengine_codedocs.importer')->discard($id);
    return ['status' => 'discarded'];
  }

  public function read(Request $request, string $operation): JsonResponse {
    return $this->respond(function () use ($request, $operation) {
      $storage = $this->entityTypeManager()->getStorage('codedoc_set');
      if ($operation === 'sets') {
        $sets = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->sort('name')->execute());
        return array_values(array_map(fn ($s) => ['id' => (int) $s->id(), 'identifier' => $s->getIdentifier(), 'name' => $s->label(), 'latest' => $s->getLatestSnapshot()?->id()], array_filter($sets, fn ($s) => $s->access('view'))));
      }
      $set = \Drupal::service('divengine_codedocs.importer')->setFor((string) $request->query->get('project', ''));
      // A missing set is answered as a hidden one.
      if (!$set || !$set->access('view')) {
        throw new AccessDeniedHttpException('Documentation set unavailable.');
      }
      if ($operation === 'snapshots') {
        $ss = $this->entityTypeManager()->getStorage('codedoc_snapshot');
        $items = $ss->loadMultiple($ss->getQuery()->accessCheck(TRUE)->condition('doc_set', $set->id())->condition('state', 'ready')->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 100)->execute());
        return array_values(array_map(fn ($s) => ['id' => (int) $s->id(), 'key' => $s->label(), 'commit' => $s->get('commit_sha')->value, 'content_hash' => $s->get('content_hash')->value, 'coverage' => $s->coverage()], array_filter($items, fn ($s) => $s->access('view'))));
      }
      $snapshot = $request->query->get('snapshot') ? $this->snapshot($request->query->getInt('snapshot')) : $set->getLatestSnapshot();
      if (!$snapshot || (string) $snapshot->get('doc_set')->target_id !== (string) $set->id()) {
        throw new ContractException('No ready snapshot in this documentation set.');
      }
      if (!$snapshot->access('view')) {
        throw new AccessDeniedHttpException();
      }
      $index = \Drupal::service('divengine_codedocs.index');
      $sid = (int) $snapshot->id();
      $offset = max(0, $request->query->getInt('offset'));
      if ($operation === 'diff') {
        $from = $this->snapshot($request->query->getInt('from'));
        if ((string) $from->get('doc_set')->target_id !== (string) $set->id()) {
          throw new ContractException('Compare snapshots of the same documentation set.');
        }
        return $index->diff((int) $from->id(), $sid);
      }
      return match ($operation) {
        'units' => $index->rows('unit', $sid, [], 200, $offset),
        'symbols' => $index->search($sid, (string) $request->query->get('q', ''), $offset),
        'symbol' => $index->symbol($sid, $request->query->getInt('symbol')) ?? throw new ContractException('Symbol not found.'),
        'refs' => $index->rows('ref', $sid, [], 200, $offset),
        'endpoints' => $index->rows('endpoint', $sid, [], 200, $offset),
        default => throw new ContractException('Unknown operation.'),
      };
    });
  }

  protected function snapshot(int $id): CodeDocSnapshot {
    $s = $this->entityTypeManager()->getStorage('codedoc_snapshot')->load($id);
    if (!$s instanceof CodeDocSnapshot || !$s->isReady() || !$s->access('view')) {
      throw new AccessDeniedHttpException('Snapshot unavailable.');
    }
    return $s;
  }

  protected function respond(callable $callback): JsonResponse {
    try {
      $response = new JsonResponse($callback());
    }
    catch (ContractException | \JsonException | \TypeError $e) {
      $response = new JsonResponse(['message' => $e instanceof \TypeError ? 'Invalid payload structure or field type.' : $e->getMessage()], 422);
    }
    catch (AccessDeniedHttpException $e) {
      $response = new JsonResponse(['message' => $e->getMessage() ?: 'Access denied.'], 403);
    }
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}

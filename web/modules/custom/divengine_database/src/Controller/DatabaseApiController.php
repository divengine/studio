<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_database\Contract\AdapterManager;
use Drupal\divengine_database\Contract\ContractException;
use Drupal\divengine_database\Entity\SchemaSnapshot;
use Drupal\divengine_database\SchemaDiff;
use Drupal\divengine_database\SchemaFacts;
use Drupal\divengine_database\SnapshotImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON of the explorer and evolution pages, and the import endpoint that
 * analyzers push snapshots to.
 *
 * Every read checks that the user may see the snapshots involved. Errors
 * are JSON too: {message}.
 */
class DatabaseApiController extends ControllerBase {

  protected SchemaFacts $facts;

  protected SnapshotImporter $importer;

  protected AdapterManager $adapters;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->facts = $container->get('divengine_database.facts');
    $instance->importer = $container->get('divengine_database.importer');
    $instance->adapters = $container->get('divengine_database.adapters');
    return $instance;
  }

  public function index(Request $request): JsonResponse {
    return $this->respond(fn () => $this->facts->graphIndex($this->snapshot($request->query->get('snapshot'))));
  }

  public function details(Request $request): JsonResponse {
    return $this->respond(fn () => $this->facts->tableDetails($this->snapshot($request->query->get('snapshot')), $this->ids($request)));
  }

  public function search(Request $request): JsonResponse {
    return $this->respond(fn () => $this->facts->search($this->snapshot($request->query->get('snapshot')), (string) $request->query->get('q', ''), (int) $request->query->get('limit', 50)));
  }

  /**
   * Ready snapshots the user may see, by database, newest first, each with
   * its predecessor.
   */
  public function snapshots(): JsonResponse {
    return $this->respond(function () {
      $storage = $this->entityTypeManager->getStorage('schema_snapshot');
      $snapshots = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->condition('state', 'ready')->sort('captured_at', 'DESC')->sort('id', 'DESC')->execute());
      $groups = [];
      foreach ($snapshots as $snapshot) {
        $database = $snapshot->getDatabase();
        if ($database && $snapshot->access('view')) {
          $groups[$database->id()] ??= ['database' => $database->label(), 'code' => $database->get('code')->value, 'snapshots' => []];
          $groups[$database->id()]['snapshots'][] = $snapshot;
        }
      }
      uasort($groups, fn ($a, $b) => strcmp($a['database'], $b['database']));
      $out = [];
      foreach ($groups as $group) {
        $items = [];
        foreach ($group['snapshots'] as $i => $snapshot) {
          $items[] = [
            'id' => (int) $snapshot->id(),
            'key' => $snapshot->label(),
            'captured_at' => $snapshot->get('captured_at')->value ? gmdate('c', (int) $snapshot->get('captured_at')->value) : NULL,
            'source_revision' => $snapshot->get('source_revision')->value ?: NULL,
            'tables' => $snapshot->count('table_count') + $snapshot->count('view_count'),
            'columns' => $snapshot->count('column_count'),
            'previous_id' => isset($group['snapshots'][$i + 1]) ? (int) $group['snapshots'][$i + 1]->id() : NULL,
          ];
        }
        $out[] = ['database' => $group['database'], 'code' => $group['code'], 'snapshots' => $items];
      }
      return $out;
    });
  }

  public function diff(Request $request): JsonResponse {
    return $this->respond(fn () => $this->compute($request, (bool) $request->query->get('unchanged'))[2]);
  }

  public function diffGraph(Request $request): JsonResponse {
    return $this->respond(function () use ($request) {
      [$a, $b, $diff] = $this->compute($request);
      $graph = SchemaDiff::unionGraph($a, $b, $diff);
      $graph['snapshot']['diff']['cross_database'] = $diff['cross_database'];
      return $graph;
    });
  }

  public function diffDetails(Request $request): JsonResponse {
    return $this->respond(function () use ($request) {
      [$a, $b, $diff] = $this->compute($request);
      return SchemaDiff::unionDetails($a, $b, $diff, array_slice($this->ids($request), 0, SchemaFacts::MAX_DETAIL_TABLES));
    });
  }

  /**
   * Receives a snapshot from an analyzer.
   *
   * Analyzer accounts (HTTP Basic, "Import database snapshots") push into
   * the databases of the Developments they are members of.
   *
   * The body is the Divengine contract (JSON) or any format an adapter
   * knows (SchemaSpy XML). Query: database (code, by default the payload's
   * identifier), revision, format, force.
   */
  public function import(Request $request): JsonResponse {
    try {
      $content = (string) $request->getContent();
      if ($content === '') {
        throw new ContractException('Send the snapshot in the request body.');
      }
      $payload = $this->adapters->toContract($content, (string) $request->query->get('format', 'auto'));
      $this->importer->validate($payload);
      $code = (string) $request->query->get('database', '');
      if ($code !== '') {
        $found = $this->entityTypeManager->getStorage('schema_database')->loadByProperties(['code' => mb_strtoupper($code)]);
        $database = reset($found) ?: throw new ContractException(sprintf('There is no database %s.', $code));
      }
      else {
        $database = $this->importer->databaseFor((string) ($payload['database']['code'] ?? ''));
      }
      if (!$this->importer->mayImport($database, $this->currentUser())) {
        return new JsonResponse(['message' => sprintf('You cannot import into %s: managers need to see its Development, analyzer accounts must be members of it.', $database->label())], 403);
      }
      $result = $this->importer->import($payload, $database, ['revision' => $request->query->get('revision'), 'force' => (bool) $request->query->get('force')]);
    }
    catch (ContractException $e) {
      return new JsonResponse(['message' => $e->getMessage()], 422);
    }
    $snapshot = $result['snapshot'];
    return new JsonResponse([
      'status' => $result['status'],
      'message' => $result['message'],
      'snapshot' => ['id' => (int) $snapshot->id(), 'key' => $snapshot->label(), 'url' => $snapshot->toUrl('canonical', ['absolute' => TRUE])->toString()],
      'counts' => array_map(fn ($counter) => $snapshot->count($counter), array_combine(array_keys(SchemaSnapshot::COUNTERS), array_keys(SchemaSnapshot::COUNTERS))),
    ], $result['status'] === 'imported' ? 201 : 200);
  }

  /**
   * Inputs and diff of the pair FROM -> TO of the request.
   */
  protected function compute(Request $request, bool $include_unchanged = FALSE): array {
    $from = $this->snapshot($request->query->get('from'));
    $to = $this->snapshot($request->query->get('to'));
    if ($from->id() === $to->id()) {
      throw new ContractException('Choose two different snapshots to compare.');
    }
    $engine_from = mb_strtolower((string) $from->getDatabase()?->get('engine')->value);
    $engine_to = mb_strtolower((string) $to->getDatabase()?->get('engine')->value);
    if ($engine_from !== $engine_to) {
      throw new ContractException(sprintf('Snapshots of different engines cannot be compared (%s vs %s).', $engine_from, $engine_to));
    }
    $a = $this->facts->diffInput($from);
    $b = $this->facts->diffInput($to);
    $diff = SchemaDiff::diff($a, $b, $include_unchanged);
    $cross = $from->get('database')->target_id !== $to->get('database')->target_id;
    if ($cross) {
      array_unshift($diff['notes'], sprintf('Cross-database comparison (%s vs %s): identity is name-based, differences may come from different databases rather than evolution.', $from->getDatabase()?->label(), $to->getDatabase()?->label()));
    }
    $diff['cross_database'] = $cross;
    return [$a, $b, $diff];
  }

  /**
   * A ready snapshot the user may see.
   */
  protected function snapshot(mixed $id): SchemaSnapshot {
    $snapshot = is_numeric($id) ? $this->entityTypeManager->getStorage('schema_snapshot')->load((int) $id) : NULL;
    if (!$snapshot instanceof SchemaSnapshot || !$snapshot->access('view')) {
      throw new ContractException('That snapshot does not exist or is not visible to you.');
    }
    if (!$snapshot->isReady()) {
      throw new ContractException(sprintf('%s is not ready.', $snapshot->label()));
    }
    return $snapshot;
  }

  protected function ids(Request $request): array {
    return array_values(array_filter(array_map('intval', explode(',', (string) $request->query->get('ids', '')))));
  }

  protected function respond(callable $build): JsonResponse {
    try {
      $response = new JsonResponse($build());
    }
    catch (ContractException $e) {
      $response = new JsonResponse(['message' => $e->getMessage()], 422);
    }
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $response;
  }

}

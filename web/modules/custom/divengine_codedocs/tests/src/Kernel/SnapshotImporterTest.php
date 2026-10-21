<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_codedocs\Kernel;

use Drupal\divengine_codedocs\Contract\ContractException;
use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Drupal\divengine_codedocs\Entity\CodeDocSnapshot;
use Drupal\divengine_process\Entity\Development;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The importer on the site's database: whole and chunked uploads,
 * identity, immutability and who may publish.
 */
#[RunTestsInSeparateProcesses]
class SnapshotImporterTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_source', 'divengine_codedocs'];

  protected Development $development;

  protected CodeDocSet $set;

  protected UserInterface $manager;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('trace_link');
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    foreach (['development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline', 'source_repository', 'source_ref', 'source_snapshot', 'codedoc_set', 'codedoc_snapshot'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installSchema('divengine_codedocs', ['divengine_codedoc_unit', 'divengine_codedoc_symbol', 'divengine_codedoc_param', 'divengine_codedoc_tag', 'divengine_codedoc_ref', 'divengine_codedoc_endpoint', 'divengine_codedoc_batch']);
    $this->installConfig(['divengine_artifact', 'divengine_traceability', 'divengine_process']);
    // Take uid 1, which bypasses permission checks.
    $this->createUser();
    $this->manager = $this->createUser(['use unified process', 'manage code docs', 'use code docs']);
    $this->setCurrentUser($this->manager);
    $this->development = Development::create(['name' => 'Shop']);
    $this->development->save();
    $this->set = CodeDocSet::create(['development' => $this->development->id(), 'name' => 'Shop backend', 'identifier' => 'shop-php']);
    $this->set->save();
  }

  protected function importer(): \Drupal\divengine_codedocs\SnapshotImporter {
    return \Drupal::service('divengine_codedocs.importer');
  }

  protected function document(string $commit = 'demo-v1'): array {
    $document = json_decode(file_get_contents(__DIR__ . '/../../fixtures/contract.json'), TRUE);
    $document['commit'] = $commit;
    return $document;
  }

  public function testWholeImportResolvesTheModel(): void {
    $result = $this->importer()->import($this->document());
    $this->assertSame('imported', $result['status']);
    $snapshot = $result['snapshot'];
    $this->assertTrue($snapshot->isReady());
    $this->assertSame(2, $snapshot->count('unit_count'));
    $this->assertSame(2, $snapshot->count('symbol_count'));
    $this->assertSame(1, $snapshot->count('member_count'));

    $index = \Drupal::service('divengine_codedocs.index');
    $find = $index->search((int) $snapshot->id(), 'find')[0];
    $shop = $index->search((int) $snapshot->id(), 'Shop')[0];
    $this->assertSame((int) $shop['id'], (int) $find['parent_id'], 'Nested members name their parent.');
    $symbol = $index->symbol((int) $snapshot->id(), (int) $find['id']);
    $this->assertSame('Product identifier.', $symbol['params'][0]['doc']);
    $this->assertSame('return', $symbol['tags'][0]['kind']);
    $product = $index->search((int) $snapshot->id(), 'Product')[0];
    $this->assertSame((int) $product['id'], (int) $symbol['refs'][0]['target_symbol_id'], 'References resolve inside the snapshot.');
    $this->assertSame((int) $find['id'], (int) $index->symbol((int) $snapshot->id(), (int) $product['id'])['incoming'][0]['symbol_id']);
  }

  public function testIdentityIdempotencyAndUnchanged(): void {
    $first = $this->importer()->import($this->document());
    $again = $this->importer()->import($this->document());
    $this->assertSame('idempotent', $again['status']);
    $this->assertSame($first['snapshot']->id(), $again['snapshot']->id());

    $same = $this->importer()->import($this->document('demo-v2'));
    $this->assertSame('unchanged', $same['status'], 'A new revision with the same documentation is not a new snapshot.');

    $changed = $this->document('demo-v1');
    $changed['units'][1]['symbols'][0]['doc'] = ['summary' => 'Something sold.'];
    try {
      $this->importer()->import($changed);
      $this->fail('The same revision with other content must be refused.');
    }
    catch (ContractException $e) {
      $this->assertStringContainsString('different documentation', $e->getMessage());
    }
    $this->assertCount(1, \Drupal::entityTypeManager()->getStorage('codedoc_snapshot')->loadByProperties(['state' => 'ready']));
  }

  public function testChunkedUploadIsInvisibleUntilComplete(): void {
    $document = $this->document();
    $units = $document['units'];
    unset($document['units']);
    $upload = $this->importer()->begin($document, 2);
    $this->assertSame('uploading', $upload->get('state')->value);

    $this->importer()->append((int) $upload->id(), 1, ['units' => [$units[1]]]);
    // Retrying a chunk with the same content is fine; other content is not.
    $this->importer()->append((int) $upload->id(), 1, ['units' => [$units[1]]]);
    try {
      $this->importer()->append((int) $upload->id(), 1, ['units' => [$units[0]]]);
      $this->fail('A chunk cannot change once uploaded.');
    }
    catch (ContractException) {
    }
    try {
      $this->importer()->finish((int) $upload->id());
      $this->fail('An incomplete upload cannot be finalized.');
    }
    catch (ContractException $e) {
      $this->assertStringContainsString('received 1 of 2', $e->getMessage());
    }
    $this->assertNull($this->set->getLatestSnapshot(), 'Nothing is readable while uploading.');

    $this->importer()->append((int) $upload->id(), 0, ['units' => [$units[0]]]);
    $result = $this->importer()->finish((int) $upload->id());
    $this->assertSame('imported', $result['status']);
    $this->assertSame((string) $upload->id(), (string) $this->set->getLatestSnapshot()->id());
    try {
      $this->importer()->append((int) $upload->id(), 0, ['units' => [$units[0]]]);
      $this->fail('Ready snapshots are immutable.');
    }
    catch (ContractException) {
    }
  }

  public function testOnlyMembersPublish(): void {
    $publisher = $this->createUser(['publish code docs']);
    $this->setCurrentUser($publisher);
    try {
      $this->importer()->import($this->document());
      $this->fail('A publisher outside the Development cannot publish.');
    }
    catch (AccessDeniedHttpException) {
    }
    $development = Development::load($this->development->id());
    $development->get('members')->appendItem($publisher->id());
    $development->save();
    $this->assertContains((string) $publisher->id(), array_map('strval', array_column(Development::load($development->id())->get('members')->getValue(), 'target_id')));
    // A publisher sees nothing, yet publishes into the Developments it is a member of.
    $this->assertSame('imported', $this->importer()->import($this->document())['status']);
    $this->assertFalse($this->set->access('view', $publisher));

    $this->setCurrentUser($this->createUser(['publish code docs']));
    $upload = CodeDocSnapshot::load($this->set->getLatestSnapshot()->id());
    try {
      $this->importer()->discard((int) $upload->id());
      $this->fail('Nobody else may touch a ready snapshot.');
    }
    catch (AccessDeniedHttpException | ContractException) {
    }
  }

  public function testUndocumentedSymbolsAreBounded(): void {
    $document = $this->document();
    // Five more public methods without documentation.
    foreach (range(1, 5) as $n) {
      $document['units'][0]['symbols'][0]['members'][] = ['key' => "Shop::undocumented$n()", 'kind' => 'method', 'name' => "undocumented$n", 'visibility' => 'public', 'signature' => "undocumented$n(): void", 'line' => 100 + $n];
    }
    $this->importer()->import($document);
    $sid = (int) CodeDocSet::load($this->set->id())->getLatestSnapshot()->id();
    $index = \Drupal::service('divengine_codedocs.index');
    $total = array_sum(array_map(fn ($unit) => $unit['documentable'] - $unit['documented'], $index->units($sid)));
    $this->assertGreaterThan(1, $total, 'The fixture has several undocumented symbols.');
    $this->assertCount($total, $index->undocumented($sid));
    $this->assertCount(2, $index->undocumented($sid, 2));
    $this->assertSame(array_slice($index->undocumented($sid), 0, 2), $index->undocumented($sid, 2), 'The first ones, in the same order.');
  }

  public function testJavadocAndTypeScriptOutputNormalize(): void {
    $adapters = \Drupal::service('divengine_codedocs.adapters');
    foreach (['javadoc', 'tsdoc'] as $format) {
      $document = json_decode(file_get_contents(__DIR__ . '/../../fixtures/' . $format . '.json'), TRUE);
      $adapter = $adapters->resolve($document);
      $this->assertSame($format, $adapter->id());
      $units = $adapter->units($document, $document);
      $this->assertNotEmpty($units);
      foreach ($units as $unit) {
        $this->assertNotSame('', (string) $unit['key']);
        foreach ($unit['symbols'] ?? [] as $symbol) {
          $this->assertNotSame('', (string) $symbol['key']);
        }
      }
    }
  }

}

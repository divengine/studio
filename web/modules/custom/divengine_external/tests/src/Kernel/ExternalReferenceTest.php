<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_external\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\ExternalReferenceException;
use Drupal\divengine_external\ExternalReferences;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * External references: identity, linking, privacy and history (#69).
 */
#[RunTestsInSeparateProcesses]
class ExternalReferenceTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user'];

  protected UserInterface $member;

  protected UserInterface $outsider;

  protected Development $alpha;

  protected Development $beta;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user']);
    $this->container->get('module_installer')->install(['divengine_external']);
    // User 1 bypasses access.
    $this->createUser();
    $permissions = ['use unified process', 'view artifact', 'view trace links', 'create trace links', 'link external work'];
    $this->member = $this->createUser($permissions);
    $this->outsider = $this->createUser($permissions);
    $this->alpha = Development::create(['name' => 'Alpha', 'members' => [$this->member->id()]]);
    $this->alpha->save();
    $this->beta = Development::create(['name' => 'Beta', 'members' => [$this->outsider->id()]]);
    $this->beta->save();
    $this->setCurrentUser($this->member);
  }

  protected function references(): ExternalReferences {
    return $this->container->get('divengine_external.references');
  }

  protected function requirement(string $title, Development $development): Artifact {
    $requirement = Artifact::create(['type' => 'requirement', 'title' => $title, 'development' => $development->id()]);
    $requirement->save();
    return $requirement;
  }

  public function testRequirementTracesToGitHubWorkWithoutDuplicates(): void {
    $requirement = $this->requirement('Legacy behaviour is preserved', $this->alpha);
    $references = $this->references();

    [$issue, $created] = $references->resolve($this->alpha->id(), 'https://github.com/rafageist/ancora-goh/issues/70', $this->member);
    $this->assertTrue($created);
    $references->link($requirement, $issue, 'implements', TRUE, $this->member);
    [$pr98] = $references->resolve($this->alpha->id(), 'https://github.com/rafageist/ancora-goh/pull/98', $this->member);
    $references->link($requirement, $pr98, 'verifies', TRUE, $this->member, 'Characterization tests');
    [$pr99] = $references->resolve($this->alpha->id(), 'https://github.com/rafageist/ancora-goh/pull/99', $this->member);
    $references->link($requirement, $pr99, 'implements', TRUE, $this->member);

    // Another way of writing the same link reuses the reference, and the
    // same trace is not recorded twice.
    [$again, $created] = $references->resolve($this->alpha->id(), 'https://GITHUB.com/RafaGeist/ancora-goh/pull/98/commits', $this->member, 'Another label');
    $this->assertFalse($created);
    $this->assertSame($pr98->id(), $again->id());
    $this->assertSame('rafageist/ancora-goh#98', $again->label(), 'Reusing does not relabel.');
    [, $linked] = $references->link($requirement, $again, 'verifies', TRUE, $this->member);
    $this->assertFalse($linked);
    $this->assertCount(3, ExternalReference::loadMultiple());

    // The requirement's traces lead to the external work.
    $traces = $this->container->get('entity_type.manager')->getStorage('trace_link')->loadByProperties(['target_entity_type' => 'artifact', 'target_entity_id' => $requirement->id()]);
    $ends = array_map(fn (TraceLink $link) => $link->get('link_type')->value . ' ' . $link->getSourceEntity()->getUrl(), $traces);
    sort($ends);
    $this->assertSame([
      'implements https://github.com/rafageist/ancora-goh/issues/70',
      'implements https://github.com/rafageist/ancora-goh/pull/99',
      'verifies https://github.com/rafageist/ancora-goh/pull/98',
    ], $ends);

    // The same work in another Development is another reference.
    $this->setCurrentUser($this->outsider);
    [$other, $created] = $references->resolve($this->beta->id(), 'https://github.com/rafageist/ancora-goh/pull/98', $this->outsider);
    $this->assertTrue($created);
    $this->assertNotSame($pr98->id(), $other->id());
  }

  public function testDuplicatesAreRefusedForEveryWriter(): void {
    $reference = ExternalReference::create(['development' => $this->alpha->id(), 'url' => 'https://github.com/a/b/pull/1']);
    $this->assertCount(0, $reference->validate());
    $reference->save();
    $this->assertSame('a/b#1', $reference->label());

    $duplicate = ExternalReference::create(['development' => $this->alpha->id(), 'url' => 'https://github.com/A/B/pull/1/files']);
    $messages = array_map(fn ($v) => strip_tags((string) $v->getMessage()), iterator_to_array($duplicate->validate()));
    $this->assertContains('This work is already referenced in the Development as a/b#1.', $messages);
    $this->expectException(EntityStorageException::class);
    $duplicate->save();
  }

  public function testIdentityNeverChangesAndEditsAreRevisions(): void {
    $reference = ExternalReference::create(['development' => $this->alpha->id(), 'url' => 'https://github.com/a/b/issues/4']);
    $reference->save();
    $first = (int) $reference->getRevisionId();

    $reference->set('label', 'Login fails on Safari')->set('notes', 'Seen in release 2.')->save();
    $this->assertGreaterThan($first, (int) $reference->getRevisionId());
    $storage = $this->container->get('entity_type.manager')->getStorage('external_reference');
    $this->assertSame('a/b#4', $storage->loadRevision($first)->label(), 'The earlier label stays in the history.');
    $this->assertSame((string) $this->member->id(), (string) $storage->loadRevision($reference->getRevisionId())->getRevisionUserId());

    foreach (['url' => 'https://github.com/a/b/issues/5', 'remote_id' => '5', 'development' => $this->beta->id()] as $field => $value) {
      $changed = $storage->loadUnchanged($reference->id())->set($field, $value);
      $this->assertGreaterThan(0, $changed->validate()->count(), $field);
      try {
        $changed->save();
        $this->fail("$field changed.");
      }
      catch (EntityStorageException) {
      }
    }
  }

  public function testReferencesStayInsideTheirDevelopment(): void {
    [$reference] = $this->references()->resolve($this->alpha->id(), 'https://github.com/a/b/pull/7', $this->member);
    $this->references()->link($this->requirement('Visible requirement', $this->alpha), $reference, 'implements', TRUE, $this->member);

    // Somebody outside the Development neither sees the reference nor learns
    // that it exists.
    $this->assertFalse($reference->access('view', $this->outsider));
    $this->setCurrentUser($this->outsider);
    $storage = $this->container->get('entity_type.manager')->getStorage('external_reference');
    $this->assertSame(0, (int) $storage->getQuery()->accessCheck(TRUE)->count()->execute());
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('trace_link')->getQuery()->accessCheck(TRUE)->count()->execute());
    $this->assertSame([], array_keys($this->container->get('entity_type.manager')->getListBuilder('external_reference')->load()));

    // Registering work in a Development one does not see names nothing.
    try {
      $this->references()->resolve($this->alpha->id(), 'https://github.com/a/b/pull/7', $this->outsider);
      $this->fail('An outsider registered work in a hidden Development.');
    }
    catch (ExternalReferenceException $e) {
      $this->assertStringNotContainsString('Alpha', $e->getMessage());
      $this->assertStringNotContainsString('a/b#7', $e->getMessage());
    }

    // Records of another Development cannot be traced to it.
    $this->setCurrentUser($this->member);
    $this->beta->get('members')->appendItem($this->member->id());
    $this->beta->save();
    try {
      $this->references()->link($this->requirement('Beta requirement', Development::load($this->beta->id())), $reference, 'implements', TRUE, $this->member);
      $this->fail('A reference of Alpha was traced to a record of Beta.');
    }
    catch (ExternalReferenceException $e) {
      $this->assertSame('Traces between records of a Development stay inside that Development.', $e->getMessage());
    }
  }

  public function testWritersNeedThePermissionAndAnEditableDevelopment(): void {
    $reader = $this->createUser(['use unified process', 'view artifact', 'view trace links', 'create trace links']);
    $this->alpha->get('members')->appendItem($reader->id());
    $this->alpha->save();
    $this->setCurrentUser($reader);
    try {
      $this->references()->resolve($this->alpha->id(), 'https://github.com/a/b/pull/8', $reader);
      $this->fail('Registered without "Link external work".');
    }
    catch (ExternalReferenceException) {
    }
    $this->assertCount(0, ExternalReference::loadMultiple());
  }

  public function testReferencesInUseCannotBeDeleted(): void {
    [$reference] = $this->references()->resolve($this->alpha->id(), 'https://github.com/a/b/pull/9', $this->member);
    [$link] = $this->references()->link($this->requirement('Requirement', $this->alpha), $reference, 'implements', TRUE, $this->member);
    $this->assertFalse($reference->access('delete', $this->member));
    try {
      $reference->delete();
      $this->fail('A reference with trace links was deleted.');
    }
    catch (EntityStorageException) {
    }

    // The Development cannot be deleted while it references external work.
    $this->assertArrayHasKey('external_reference', $this->container->get('divengine_process.development_references')->dependents($this->alpha->id()));

    $this->setCurrentUser($this->createUser([], NULL, TRUE));
    $link->delete();
    $this->container->get('entity_type.manager')->getAccessControlHandler('external_reference')->resetCache();
    $reference = ExternalReference::load($reference->id());
    $this->assertTrue($reference->access('delete', $this->member));
    $reference->delete();
    $this->assertNull(ExternalReference::load($reference->id()));
  }

}

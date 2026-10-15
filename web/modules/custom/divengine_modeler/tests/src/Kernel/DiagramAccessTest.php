<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers diagram access: view, own/any and per-type permissions.
 *
 * Content moderation denies updates to users without a transition from the
 * current state, so editors also get "create new draft".
 */
#[RunTestsInSeparateProcesses]
class DiagramAccessTest extends ModelerKernelTestBase {

  const DRAFT = 'use diagram_lifecycle transition create_new_draft';

  public function testViewNeedsPermission(): void {
    $diagram = $this->createDiagram();
    $this->assertTrue($diagram->access('view', $this->createUser(['view diagrams'])));
    $this->assertFalse($diagram->access('view', $this->createUser([])));
  }

  public function testOwnAndAny(): void {
    $owner = $this->createUser(['edit own diagrams', 'delete own diagrams', static::DRAFT]);
    $other = $this->createUser(['edit own diagrams', 'delete own diagrams', static::DRAFT]);
    $editor = $this->createUser(['edit any diagram', static::DRAFT]);
    $diagram = $this->createDiagram(['uid' => $owner->id()]);

    $this->assertTrue($diagram->access('update', $owner));
    $this->assertTrue($diagram->access('delete', $owner));
    $this->assertFalse($diagram->access('update', $other));
    $this->assertFalse($diagram->access('update', $this->createUser(['edit any diagram'])), 'Editing needs a workflow transition.');
    $this->assertFalse($diagram->access('delete', $other));
    $this->assertTrue($diagram->access('update', $editor));
    $this->assertFalse($diagram->access('delete', $editor));
  }

  public function testPerTypePermissions(): void {
    $permissions = array_keys($this->container->get('user.permissions')->getPermissions());
    foreach (['create sketch diagram', 'edit own sketch diagram', 'edit any sketch diagram', 'delete own sketch diagram', 'delete any sketch diagram'] as $permission) {
      $this->assertContains($permission, $permissions);
    }
    $sketcher = $this->createUser(['edit any sketch diagram', 'create sketch diagram', static::DRAFT]);
    $diagram = $this->createDiagram();
    $this->assertTrue($diagram->access('update', $sketcher));
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('diagram');
    $this->assertTrue($handler->createAccess('sketch', $sketcher));
    $this->assertFalse($handler->createAccess('sketch', $this->createUser(['view diagrams'])));
  }

  public function testRevisionOperations(): void {
    $owner = $this->createUser(['edit own diagrams', 'view diagram revisions', 'revert diagram revisions', static::DRAFT]);
    $diagram = $this->createDiagram(['uid' => $owner->id()]);
    $first = $diagram->getRevisionId();
    $diagram->setNewRevision();
    $diagram->save();
    // Core never lets the default revision be reverted or deleted.
    $old = $this->container->get('entity_type.manager')->getStorage('diagram')->loadRevision($first);
    $this->assertTrue($diagram->access('view all revisions', $owner));
    $this->assertTrue($old->access('revert', $owner));
    $this->assertFalse($old->access('delete revision', $owner));
    $reverter = $this->createUser(['revert diagram revisions']);
    $this->assertFalse($old->access('revert', $reverter), 'Reverting needs edit access too.');
  }

}

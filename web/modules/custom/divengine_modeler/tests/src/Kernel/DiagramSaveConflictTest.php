<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use Drupal\divengine_modeler\Controller\DiagramModelerController;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Two people edit the same diagram (C10 scenario c, #13).
 *
 * The editor sends the revision it opened as baseRevisionId. A save based on
 * a revision that is no longer the latest is refused with 409 until the
 * person chooses to replace it; nothing anyone saved is lost, because every
 * save is a new revision.
 */
#[RunTestsInSeparateProcesses]
class DiagramSaveConflictTest extends ModelerKernelTestBase {

  /**
   * Sends a model to the save endpoint's controller.
   */
  protected function send(DiagramInterface $diagram, array $payload): JsonResponse {
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(DiagramModelerController::class);
    $request = Request::create('/diagram/' . $diagram->id() . '/model', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    // The route loads the latest revision.
    return $controller->save($request, $this->latest($diagram));
  }

  public function testStaleSaveIsRefusedAndNothingIsLost(): void {
    $diagram = $this->createDiagram();
    $opened = (int) $diagram->getRevisionId();

    // Ana and Ben open the same revision; Ana saves first.
    $ana = $this->send($diagram, ['source' => "SKETCH\nbox a Ana\n", 'baseRevisionId' => $opened, 'log' => 'Ana']);
    $this->assertSame(200, $ana->getStatusCode());
    $ana_revision = json_decode((string) $ana->getContent(), TRUE)['revisionId'];

    $ben = $this->send($diagram, ['source' => "SKETCH\nbox a Ben\n", 'baseRevisionId' => $opened, 'log' => 'Ben']);
    $this->assertSame(409, $ben->getStatusCode());
    $conflict = json_decode((string) $ben->getContent(), TRUE);
    $this->assertTrue($conflict['conflict']);
    $this->assertSame($ana_revision, $conflict['revisionId'], 'The answer names the revision to reload.');
    $this->assertSame("SKETCH\nbox a Ana\n", $this->latest($diagram)->getSource(), "Ben's save did not overwrite Ana's.");

    // Ben replaces it knowingly: a new revision, Ana's stays in the history.
    $forced = $this->send($diagram, ['source' => "SKETCH\nbox a Ben\n", 'baseRevisionId' => $opened, 'force' => TRUE, 'log' => 'Ben']);
    $this->assertSame(200, $forced->getStatusCode());
    $this->assertSame("SKETCH\nbox a Ben\n", $this->latest($diagram)->getSource());
    $storage = $this->container->get('entity_type.manager')->getStorage('diagram');
    $this->assertSame("SKETCH\nbox a Ana\n", $storage->loadRevision($ana_revision)->getSource());
    $this->assertSame('Ana', $storage->loadRevision($ana_revision)->getRevisionLogMessage());

    // Saving from the latest revision needs no confirmation.
    $latest = (int) $this->latest($diagram)->getRevisionId();
    $this->assertSame(200, $this->send($diagram, ['source' => "SKETCH\nbox a Both\n", 'baseRevisionId' => $latest])->getStatusCode());
  }

  public function testRefusedSaveWritesNoRevision(): void {
    $diagram = $this->createDiagram();
    $opened = (int) $diagram->getRevisionId();
    $this->send($diagram, ['source' => "SKETCH\nbox a First\n", 'baseRevisionId' => $opened]);
    $count = $this->revisions($diagram);
    $this->assertSame(409, $this->send($diagram, ['source' => "SKETCH\nbox a Stale\n", 'baseRevisionId' => $opened])->getStatusCode());
    $this->assertSame(400, $this->send($diagram, ['baseRevisionId' => $opened])->getStatusCode());
    $this->assertSame($count, $this->revisions($diagram));
  }

  /**
   * How many revisions the diagram has.
   */
  protected function revisions(DiagramInterface $diagram): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('diagram')->getQuery()
      ->accessCheck(FALSE)->allRevisions()->condition('id', $diagram->id())->count()->execute();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use Drupal\divengine_modeler\Exception\InvalidModelException;

/**
 * Saves what an editor sends: source, exported SVG and element bindings.
 */
class DiagramModelSaver {

  /**
   * States whose content is not edited in place: saving starts a new draft.
   */
  const REOPENING_STATES = ['published', 'archived'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SvgSafety $svgSafety,
    protected TimeInterface $time,
    protected ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * Writes a new revision of the diagram's working copy.
   *
   * @param \Drupal\divengine_modeler\Entity\DiagramInterface $diagram
   *   The diagram; its latest revision is the one edited.
   * @param string $source
   *   The new source.
   * @param string|null $svg
   *   The SVG exported by the engine, or NULL when the export failed. A
   *   missing export never blocks saving the source.
   * @param array $bindings
   *   Element bindings (text notations).
   * @param string $log
   *   The revision log message.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The author of the revision.
   *
   * @return \Drupal\divengine_modeler\Entity\DiagramInterface
   *   The saved revision.
   *
   * @throws \Drupal\divengine_modeler\Exception\InvalidModelException
   */
  public function save(DiagramInterface $diagram, string $source, ?string $svg, array $bindings, string $log, AccountInterface $account): DiagramInterface {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('diagram');
    $working = $storage->loadRevision($storage->getLatestRevisionId($diagram->id()));

    $working->setSource($source);
    $working->setBindings($bindings);
    if ($svg !== NULL && $svg !== '') {
      $errors = $this->svgSafety->check($svg);
      if ($errors) {
        throw new InvalidModelException($errors);
      }
      $working->setPreview($svg);
    }

    $working->setNewRevision(TRUE);
    $working->setRevisionUserId((int) $account->id());
    $working->setRevisionCreationTime($this->time->getRequestTime());
    $working->setRevisionLogMessage($log !== '' ? mb_substr($log, 0, 1000) : 'Saved from the modeler.');

    if ($this->moderationInformation->isModeratedEntity($working)) {
      $state = $working->get('moderation_state')->value;
      if (in_array($state, static::REOPENING_STATES, TRUE)) {
        $working->set('moderation_state', 'draft');
      }
    }

    $violations = $working->validate();
    if ($violations->count()) {
      $errors = [];
      foreach ($violations as $violation) {
        $errors[] = (string) $violation->getMessage();
      }
      throw new InvalidModelException(array_values(array_unique($errors)));
    }
    $working->save();
    return $working;
  }

  /**
   * Stores a preview rendered off-screen for the diagram's current source.
   *
   * The preview is attached to the given revision without creating a new one:
   * it is derived data, and the hash proves which source it shows.
   *
   * @throws \Drupal\divengine_modeler\Exception\InvalidModelException
   */
  public function storePreview(DiagramInterface $diagram, string $svg, string $hash): void {
    if (!hash_equals($diagram::hashSource($diagram->getSource()), $hash)) {
      throw new InvalidModelException(['The preview was rendered from another version of the source.']);
    }
    $errors = $this->svgSafety->check($svg);
    if ($errors) {
      throw new InvalidModelException($errors);
    }
    $diagram->setPreview($svg);
    $diagram->setNewRevision(FALSE);
    $diagram->setSyncing(TRUE);
    $diagram->save();
  }

}

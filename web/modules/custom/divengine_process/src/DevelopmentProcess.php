<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_process\Entity\DevelopmentCase;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Entity\ProcessPhaseType;

/**
 * Structure of a Development: its phases, milestones and process snapshot.
 */
class DevelopmentProcess {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Sets up a new Development: phases, milestones and its case snapshot.
   */
  public function initialize(DevelopmentInterface $development): void {
    $this->ensureStandardPhases($development, FALSE);
    if ($development->getCase()) {
      $this->applyCase($development, FALSE);
    }
    // Keep the creation revision; this only fills in what creation implies.
    $development->setNewRevision(FALSE);
    $development->save();
  }

  /**
   * Creates the missing phases and milestones; never changes existing ones.
   *
   * One phase per enabled phase type and one milestone per closing
   * milestone. The first phase becomes the current one when none is set.
   *
   * @param \Drupal\divengine_process\Entity\DevelopmentInterface $development
   *   The Development.
   * @param bool $save
   *   Whether to save the Development when its current phase is set.
   *
   * @return int
   *   The number of phases and milestones created.
   */
  public function ensureStandardPhases(DevelopmentInterface $development, bool $save = TRUE): int {
    $created = 0;
    $phase_storage = $this->entityTypeManager->getStorage('development_phase');
    $milestone_storage = $this->entityTypeManager->getStorage('milestone');

    $types = array_filter(
      $this->entityTypeManager->getStorage('process_phase_type')->loadMultiple(),
      fn (ProcessPhaseType $type): bool => $type->status(),
    );
    uasort($types, [ProcessPhaseType::class, 'sort']);

    $phases = [];
    foreach ($this->phases($development) as $phase) {
      $phases[$phase->get('phase_type')->target_id] = $phase;
    }
    foreach ($types as $id => $type) {
      if (!isset($phases[$id])) {
        $phases[$id] = $phase_storage->create([
          'development' => $development->id(),
          'phase_type' => $id,
          'weight' => $type->getWeight(),
        ]);
        $phases[$id]->save();
        $created++;
      }
    }

    if ($development->get('current_phase')->isEmpty() && $phases) {
      $ordered = $phases;
      uasort($ordered, fn ($a, $b): int => [(int) $a->get('weight')->value, (int) $a->id()] <=> [(int) $b->get('weight')->value, (int) $b->id()]);
      $development->set('current_phase', reset($ordered)->id());
      if ($save) {
        $development->save();
      }
    }

    $milestones = [];
    foreach ($milestone_storage->loadByProperties(['development' => $development->id()]) as $milestone) {
      $milestones[$milestone->get('milestone_type')->value] = TRUE;
    }
    foreach ($phases as $type_id => $phase) {
      $milestone_type = isset($types[$type_id]) ? $types[$type_id]->getMilestoneType() : '';
      if ($milestone_type !== '' && !isset($milestones[$milestone_type])) {
        $milestone_storage->create([
          'development' => $development->id(),
          'milestone_type' => $milestone_type,
          'phase' => $phase->id(),
          'weight' => $phase->get('weight')->value,
        ])->save();
        $milestones[$milestone_type] = TRUE;
        $created++;
      }
    }
    return $created;
  }

  /**
   * Replaces the Development's policy with the current lines of its case.
   *
   * Every line is copied, disabled ones included; evaluation ignores them.
   *
   * @param \Drupal\divengine_process\Entity\DevelopmentInterface $development
   *   The Development.
   * @param bool $save
   *   Whether to save the Development as a new revision.
   *
   * @throws \InvalidArgumentException
   *   When the Development has no case.
   */
  public function applyCase(DevelopmentInterface $development, bool $save = TRUE): void {
    $case = $development->getCase();
    if (!$case instanceof DevelopmentCase) {
      throw new \InvalidArgumentException((string) $this->t('Choose a Development Case to apply.'));
    }
    // One map item holds the whole snapshot; a list would become one item
    // per line.
    $development->set('policy', ['lines' => $case->getLines()]);
    $development->set('case_version', $case->getVersion());
    $development->set('case_applied', $this->time->getRequestTime());
    $message = (string) $this->t('Development Case @case (version @version) applied.', [
      '@case' => $case->label(),
      '@version' => $case->getVersion(),
    ]);
    $development->setRevisionLogMessage($message);
    if ($save) {
      $development->setNewRevision();
      $development->save();
    }
  }

  /**
   * Policy lines of an element type at the given levels.
   *
   * @param \Drupal\divengine_process\Entity\DevelopmentInterface $development
   *   The Development.
   * @param string $element_type
   *   discipline, activity_definition, work_product_definition or readiness.
   * @param string[] $levels
   *   Levels to keep; disabled lines are never returned by default.
   *
   * @return array<int, array<string, mixed>>
   */
  public function policyLines(DevelopmentInterface $development, string $element_type, array $levels = ['required', 'recommended', 'optional']): array {
    return array_values(array_filter(
      $development->getPolicy(),
      fn (array $line): bool => ($line['element_type'] ?? '') === $element_type && in_array($line['level'] ?? '', $levels, TRUE),
    ));
  }

  /**
   * The phases of a Development, in lifecycle order.
   *
   * @return \Drupal\divengine_process\Entity\DevelopmentPhase[]
   */
  public function phases(DevelopmentInterface $development): array {
    return $this->children($development, 'development_phase');
  }

  /**
   * Records of a Development, ordered by weight and ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function children(DevelopmentInterface $development, string $entity_type_id, bool $access_check = FALSE): array {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $query = $storage->getQuery()
      ->accessCheck($access_check)
      ->condition('development', $development->id());
    if (isset($this->entityFieldManager->getBaseFieldDefinitions($entity_type_id)['weight'])) {
      $query->sort('weight');
    }
    $query->sort($storage->getEntityType()->getKey('id'));
    return $storage->loadMultiple($query->execute());
  }

}

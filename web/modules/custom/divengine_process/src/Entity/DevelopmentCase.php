<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\DevelopmentCaseListBuilder;
use Drupal\divengine_process\Form\DevelopmentCaseForm;
use Drupal\divengine_process\ProcessConfigAccessControlHandler;
use Drupal\divengine_process\Vocabulary;

/**
 * A reusable tailoring of the process.
 *
 * Each line says how much the case asks for one discipline, activity
 * definition, work product definition or readiness criterion (required,
 * recommended, optional or disabled), optionally only for one milestone.
 * Developments never read the case at run time: they keep a snapshot of its
 * lines, taken when the case is applied, together with the case version.
 */
#[ConfigEntityType(
  id: 'development_case',
  label: new TranslatableMarkup('Development Case'),
  label_collection: new TranslatableMarkup('Development Cases'),
  label_singular: new TranslatableMarkup('Development Case'),
  label_plural: new TranslatableMarkup('Development Cases'),
  config_prefix: 'development_case',
  admin_permission: 'administer unified process',
  handlers: [
    'access' => ProcessConfigAccessControlHandler::class,
    'list_builder' => DevelopmentCaseListBuilder::class,
    'form' => [
      'add' => DevelopmentCaseForm::class,
      'edit' => DevelopmentCaseForm::class,
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'weight' => 'weight',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'weight',
    'status',
    'version',
    'is_default',
    'lines',
  ],
  links: [
    'add-form' => '/admin/divengine/process/cases/add',
    'edit-form' => '/admin/divengine/process/cases/{development_case}',
    'delete-form' => '/admin/divengine/process/cases/{development_case}/delete',
    'collection' => '/admin/divengine/process/cases',
  ],
)]
class DevelopmentCase extends ProcessConfigEntityBase {

  /**
   * Incremented by exactly one on every save that changes the lines.
   *
   * Only "applied version differs from current version" is meaningful.
   */
  protected int $version = 1;

  /**
   * Whether new Developments get this case when they do not choose one.
   */
  protected bool $is_default = FALSE;

  /**
   * Tailoring lines.
   *
   * Each line has: element_type, target (machine name of the discipline,
   * definition or readiness criterion), milestone_type ('' = every
   * milestone), level, note and weight.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $lines = [];

  public function getVersion(): int {
    return $this->version;
  }

  public function isDefault(): bool {
    return $this->is_default;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getLines(): array {
    return $this->lines;
  }

  /**
   * Replaces the lines, filling the optional keys.
   *
   * @param array<int, array<string, mixed>> $lines
   */
  public function setLines(array $lines): static {
    $this->lines = array_values(array_map(static fn (array $line): array => static::normalizeLine($line), $lines));
    return $this;
  }

  /**
   * Returns a line with every key present, in the stored order.
   *
   * @param array<string, mixed> $line
   *
   * @return array<string, mixed>
   */
  public static function normalizeLine(array $line): array {
    return [
      'element_type' => (string) ($line['element_type'] ?? ''),
      'target' => (string) ($line['target'] ?? ''),
      'milestone_type' => (string) ($line['milestone_type'] ?? ''),
      'level' => (string) ($line['level'] ?? 'recommended'),
      'note' => (string) ($line['note'] ?? ''),
      'weight' => (int) ($line['weight'] ?? 10),
    ];
  }

  /**
   * Explains what is wrong with the lines; empty when they are valid.
   *
   * Readiness criteria are only checked for being present: the criteria come
   * from plugins of this and other modules.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   */
  public function validateLines(): array {
    $errors = [];
    $entity_types = Vocabulary::caseElementEntityTypes();
    foreach ($this->lines as $delta => $line) {
      $line = static::normalizeLine($line);
      $args = ['@line' => $delta + 1];
      if (!isset(Vocabulary::caseElementTypes()[$line['element_type']])) {
        $errors[] = new TranslatableMarkup('Line @line: unknown element type.', $args);
        continue;
      }
      if (!isset(Vocabulary::levels()[$line['level']])) {
        $errors[] = new TranslatableMarkup('Line @line: unknown level.', $args);
      }
      if ($line['milestone_type'] !== '' && !isset(Vocabulary::milestoneTypes()[$line['milestone_type']])) {
        $errors[] = new TranslatableMarkup('Line @line: unknown milestone.', $args);
      }
      if ($line['target'] === '') {
        $errors[] = match ($line['element_type']) {
          'discipline' => new TranslatableMarkup('Line @line: a discipline line needs a discipline.', $args),
          'activity_definition' => new TranslatableMarkup('Line @line: an activity line needs an activity definition.', $args),
          'work_product_definition' => new TranslatableMarkup('Line @line: a work product line needs a work product definition.', $args),
          default => new TranslatableMarkup('Line @line: a readiness line needs a criterion.', $args),
        };
        continue;
      }
      $entity_type = $entity_types[$line['element_type']] ?? NULL;
      if ($entity_type && !$this->entityTypeManager()->getStorage($entity_type)->load($line['target'])) {
        $errors[] = new TranslatableMarkup('Line @line: %target does not exist.', $args + ['%target' => $line['target']]);
      }
    }
    return $errors;
  }

  /**
   * Returns the first enabled default case, by weight and label.
   */
  public static function loadDefault(): ?static {
    $cases = array_filter(
      \Drupal::entityTypeManager()->getStorage('development_case')->loadByProperties(['is_default' => TRUE, 'status' => TRUE]),
    );
    uasort($cases, [static::class, 'sort']);
    return $cases ? reset($cases) : NULL;
  }

  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    $this->setLines($this->lines);
    if ($this->isNew() || $this->isSyncing()) {
      $this->version = max(1, $this->version);
      return;
    }
    $original = $this->getOriginal();
    if ($original instanceof self && $original->getLines() !== $this->lines) {
      // One increment per save, however many lines the save touches.
      $this->version = $original->getVersion() + 1;
    }
  }

  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    if (!$this->is_default || $this->isSyncing()) {
      return;
    }
    // A single default: the newest one wins.
    foreach ($storage->loadByProperties(['is_default' => TRUE]) as $other) {
      if ($other->id() !== $this->id()) {
        $other->set('is_default', FALSE)->save();
      }
    }
  }

  public function calculateDependencies() {
    parent::calculateDependencies();
    $by_type = [];
    foreach ($this->lines as $line) {
      if ($entity_type = Vocabulary::caseElementEntityTypes()[$line['element_type'] ?? ''] ?? NULL) {
        $by_type[$entity_type][] = $line['target'] ?? '';
      }
    }
    foreach ($by_type as $entity_type => $ids) {
      $this->addReferenceDependencies($entity_type, array_unique($ids));
    }
    return $this;
  }

}

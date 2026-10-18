<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Access\ScopedAccessControlHandler;
use Drupal\divengine_process\Form\ScopedEntityForm;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Lifecycle\StatefulTrait;
use Drupal\divengine_process\Lifecycle\Transition;
use Drupal\divengine_process\ProcessEntityViewBuilder;
use Drupal\divengine_process\Vocabulary;

/**
 * A lifecycle milestone of one Development, normally closing a phase.
 *
 * Reaching it is a human decision: readiness explains what is missing but
 * never blocks it.
 */
#[ContentEntityType(
  id: 'milestone',
  label: new TranslatableMarkup('Milestone'),
  label_collection: new TranslatableMarkup('Milestones'),
  label_singular: new TranslatableMarkup('milestone'),
  label_plural: new TranslatableMarkup('milestones'),
  handlers: [
    'view_builder' => ProcessEntityViewBuilder::class,
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => ScopedAccessControlHandler::class,
    'form' => [
      'default' => ScopedEntityForm::class,
      'edit' => ScopedEntityForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'milestone',
  admin_permission: 'administer unified process',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'ProcessSameDevelopment' => ['fields' => ['phase']],
    'ProcessUniqueFields' => ['fields' => ['development', 'milestone_type'], 'message' => 'A Development has one milestone of each type.'],
  ],
  links: [
    'canonical' => '/milestone/{milestone}',
    'edit-form' => '/milestone/{milestone}/edit',
  ],
)]
class Milestone extends ContentEntityBase implements StatefulInterface {

  use DevelopmentScopedTrait;
  use StatefulTrait;

  public static function transitions(): array {
    return [
      'reach' => new Transition(
        'reach',
        new TranslatableMarkup('Mark reached'),
        ['planned'],
        'reached',
        effect: 'recordReachedDate',
        after: 'takeBaseline',
        confirm: new TranslatableMarkup('Readiness is advisory: unmet criteria are recorded, not enforced. Mark this milestone as reached?'),
      ),
      'reset' => new Transition('reset', new TranslatableMarkup('Back to planned'), ['reached'], 'planned', effect: 'clearReachedDate'),
    ];
  }

  public static function stateLabels(): array {
    return Vocabulary::milestoneStates();
  }

  public function label() {
    $type = (string) $this->get('milestone_type')->value;
    return (string) (Vocabulary::milestoneTypes()[$type] ?? $type);
  }

  public function getMilestoneType(): string {
    return (string) $this->get('milestone_type')->value;
  }

  /**
   * Records the date and, as an auditable note, any unmet requirement.
   *
   * Readiness is advisory: reaching is allowed, and what was missing when
   * it was reached stays in the notes.
   */
  public function recordReachedDate(): void {
    $today = date('Y-m-d', \Drupal::time()->getRequestTime());
    $this->set('date_reached', $today);
    $development = $this->getDevelopment();
    if (!$development) {
      return;
    }
    $readiness = \Drupal::service('divengine_process.readiness');
    $missing = $readiness->missingCount($readiness->evaluate($development, $this->getMilestoneType()));
    if ($missing) {
      $note = (string) \Drupal::translation()->formatPlural($missing, 'Reached on @date with 1 required readiness criterion not met.', 'Reached on @date with @count required readiness criteria not met.', ['@date' => $today]);
      $notes = trim((string) $this->get('notes')->value);
      $this->set('notes', $notes === '' ? $note : $notes . "\n" . $note);
    }
  }

  /**
   * Reaching a milestone takes a baseline of the Development.
   */
  public function takeBaseline(): void {
    if ($development = $this->getDevelopment()) {
      \Drupal::service('divengine_process.baselines')->take($development, $this->label(), $this);
    }
  }

  public function clearReachedDate(): void {
    $this->set('date_reached', NULL);
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::developmentFieldDefinition();
    $fields['milestone_type'] = ProcessFields::list(new TranslatableMarkup('Milestone'), 'lifecycle_objectives', -15, FALSE);
    $fields['weight'] = ProcessFields::weight();
    $fields['phase'] = ProcessFields::reference(new TranslatableMarkup('Phase'), 'development_phase', -10);
    $fields['state'] = ProcessFields::list(new TranslatableMarkup('State'), 'planned', -12, FALSE);
    $fields['date_planned'] = ProcessFields::date(new TranslatableMarkup('Planned date'), -5);
    $fields['date_reached'] = ProcessFields::date(new TranslatableMarkup('Reached on'), -4)
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['notes'] = ProcessFields::text(new TranslatableMarkup('Notes'), 5);
    return $fields;
  }

}

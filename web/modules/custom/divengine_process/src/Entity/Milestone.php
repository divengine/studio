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
    'canonical' => '/process/milestone/{milestone}',
    'edit-form' => '/process/milestone/{milestone}/edit',
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

  public function recordReachedDate(): void {
    $this->set('date_reached', date('Y-m-d', \Drupal::time()->getRequestTime()));
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

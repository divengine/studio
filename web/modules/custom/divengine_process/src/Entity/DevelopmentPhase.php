<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Access\ScopedAccessControlHandler;
use Drupal\divengine_process\Form\ScopedEntityForm;

/**
 * A lifecycle period of one Development, one per phase type.
 *
 * Phases are periods, not discipline stages. They are created with the
 * Development and their state is edited freely.
 */
#[ContentEntityType(
  id: 'development_phase',
  label: new TranslatableMarkup('Development phase'),
  label_collection: new TranslatableMarkup('Development phases'),
  label_singular: new TranslatableMarkup('development phase'),
  label_plural: new TranslatableMarkup('development phases'),
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
  base_table: 'development_phase',
  admin_permission: 'administer unified process',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  constraints: [
    'ProcessDateOrder' => ['message' => 'The end date of a phase is before its start date.'],
    'ProcessUniqueFields' => ['fields' => ['development', 'phase_type'], 'message' => 'A Development has one phase of each type.'],
  ],
  links: [
    'canonical' => '/phase/{development_phase}',
    'edit-form' => '/phase/{development_phase}/edit',
  ],
)]
class DevelopmentPhase extends ContentEntityBase {

  use DevelopmentScopedTrait;

  public function label() {
    return $this->get('phase_type')->entity?->label() ?? (string) new TranslatableMarkup('Phase');
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::developmentFieldDefinition();
    $fields['phase_type'] = ProcessFields::reference(new TranslatableMarkup('Phase'), 'process_phase_type', -15, TRUE)
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['weight'] = ProcessFields::weight();
    $fields['state'] = ProcessFields::list(new TranslatableMarkup('State'), 'planned', -10);
    $fields['date_start'] = ProcessFields::date(new TranslatableMarkup('Start date'), -5);
    $fields['date_end'] = ProcessFields::date(new TranslatableMarkup('End date'), -4);
    $fields['objective'] = ProcessFields::text(new TranslatableMarkup('Objective'), 0);
    $fields['notes'] = ProcessFields::text(new TranslatableMarkup('Notes'), 5);
    return $fields;
  }

}

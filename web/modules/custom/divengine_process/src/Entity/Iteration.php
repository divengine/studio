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
 * A cycle inside one phase of one Development.
 *
 * Starting an iteration makes it the Development's current iteration.
 */
#[ContentEntityType(
  id: 'iteration',
  label: new TranslatableMarkup('Iteration'),
  label_collection: new TranslatableMarkup('Iterations'),
  label_singular: new TranslatableMarkup('iteration'),
  label_plural: new TranslatableMarkup('iterations'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => ScopedAccessControlHandler::class,
    'form' => [
      'default' => ScopedEntityForm::class,
      'add' => ScopedEntityForm::class,
      'edit' => ScopedEntityForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'iteration',
  admin_permission: 'administer unified process',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  constraints: [
    'ProcessStateTransition' => [],
    'ProcessDevelopmentEditable' => [],
    'ProcessDateOrder' => [],
    'ProcessSameDevelopment' => ['fields' => ['phase']],
  ],
  links: [
    'canonical' => '/process/iteration/{iteration}',
    'add-form' => '/process/iteration/add',
    'edit-form' => '/process/iteration/{iteration}/edit',
    'delete-form' => '/process/iteration/{iteration}/delete',
  ],
)]
class Iteration extends ContentEntityBase implements StatefulInterface {

  use DevelopmentScopedTrait;
  use StatefulTrait;

  public static function transitions(): array {
    return [
      'start' => new Transition('start', new TranslatableMarkup('Start'), ['planned'], 'active', after: 'becomeCurrent'),
      'done' => new Transition('done', new TranslatableMarkup('Mark done'), ['active'], 'done'),
      'cancel' => new Transition('cancel', new TranslatableMarkup('Cancel'), ['planned', 'active'], 'cancelled'),
    ];
  }

  public static function stateLabels(): array {
    return Vocabulary::iterationStates();
  }

  /**
   * Makes this iteration the current one of its Development.
   */
  public function becomeCurrent(): void {
    $development = $this->getDevelopment();
    if ($development && (string) $development->get('current_iteration')->target_id !== (string) $this->id()) {
      $development->set('current_iteration', $this->id());
      $development->setNewRevision();
      $development->setRevisionLogMessage((string) new TranslatableMarkup('Iteration @name started.', ['@name' => $this->label()]));
      $development->save();
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::developmentFieldDefinition();
    $fields['phase'] = ProcessFields::reference(new TranslatableMarkup('Phase'), 'development_phase', -15, TRUE);
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Name'), -18, TRUE);
    $fields['weight'] = ProcessFields::weight();
    $fields['state'] = ProcessFields::list(new TranslatableMarkup('State'), 'planned', -12, FALSE);
    $fields['objective'] = ProcessFields::text(new TranslatableMarkup('Objective'), -10);
    $fields['date_start'] = ProcessFields::date(new TranslatableMarkup('Start date'), -5);
    $fields['date_end'] = ProcessFields::date(new TranslatableMarkup('End date'), -4);
    $fields['responsible'] = ProcessFields::user(new TranslatableMarkup('Responsible'), 0);
    return $fields;
  }

}

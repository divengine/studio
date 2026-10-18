<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Access\LightweightScopedAccessControlHandler;
use Drupal\divengine_process\Form\ScopedEntityForm;

/**
 * Who plays a process role in one Development.
 *
 * Methodology only: it grants no access (membership of the Development
 * does).
 */
#[ContentEntityType(
  id: 'role_assignment',
  label: new TranslatableMarkup('Role assignment'),
  label_collection: new TranslatableMarkup('Role assignments'),
  label_singular: new TranslatableMarkup('role assignment'),
  label_plural: new TranslatableMarkup('role assignments'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => LightweightScopedAccessControlHandler::class,
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
  base_table: 'role_assignment',
  admin_permission: 'administer unified process',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  constraints: [
    'ProcessDevelopmentEditable' => [],
    'ProcessDateOrder' => ['start' => 'date_from', 'end' => 'date_to', 'message' => 'A role assignment cannot end before it starts.'],
    'ProcessUniqueFields' => ['fields' => ['development', 'process_role', 'user'], 'message' => 'This user already plays this role in the Development.'],
  ],
  links: [
    'canonical' => '/process/role-assignment/{role_assignment}',
    'add-form' => '/process/role-assignment/add',
    'edit-form' => '/process/role-assignment/{role_assignment}/edit',
    'delete-form' => '/process/role-assignment/{role_assignment}/delete',
  ],
)]
class RoleAssignment extends ContentEntityBase {

  use DevelopmentScopedTrait;

  public function label() {
    $role = $this->get('process_role')->entity?->label() ?? '';
    $user = $this->get('user')->entity?->getDisplayName() ?? '';
    return trim("$role: $user", ': ');
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['development'] = static::developmentFieldDefinition();
    $fields['process_role'] = ProcessFields::reference(new TranslatableMarkup('Process role'), 'process_role', -15, TRUE);
    $fields['user'] = ProcessFields::user(new TranslatableMarkup('User'), -14)->setRequired(TRUE);
    $fields['date_from'] = ProcessFields::date(new TranslatableMarkup('From'), -10);
    $fields['date_to'] = ProcessFields::date(new TranslatableMarkup('To'), -9);
    $fields['is_primary'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Primary'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE], 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE);
    $fields['notes'] = ProcessFields::text(new TranslatableMarkup('Notes'), 0);
    return $fields;
  }

}

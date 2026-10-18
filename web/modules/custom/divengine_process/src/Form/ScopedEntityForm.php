<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for records of a Development (iterations, role assignments…).
 *
 * Opened from a Development (?development=ID), the record is attached to it
 * and the Development field is not shown; references to phases are limited
 * to that Development's phases. Saving returns to the Development.
 */
class ScopedEntityForm extends ContentEntityForm {

  protected EntityFieldManagerInterface $entityFieldManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->entity;
    if ($entity->isNew() && $entity->get('development')->isEmpty() && ($id = $this->getRequest()->query->get('development'))) {
      $development = $this->entityTypeManager->getStorage('development')->load($id);
      if ($development && $development->access('update')) {
        $entity->set('development', $development->id());
      }
    }
    $form = parent::form($form, $form_state);

    $development_id = $entity->get('development')->target_id;
    if ($development_id) {
      $form['development']['#access'] = FALSE;
      // Select lists of a Development's records only offer that
      // Development's records.
      foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
        $target_type = $definition->getType() === 'entity_reference' ? $definition->getSetting('target_type') : NULL;
        if (!$target_type || $field_name === 'development' || !isset($form[$field_name]['widget']['#options'])
          || !$this->entityTypeManager->getDefinition($target_type)->entityClassImplements(FieldableEntityInterface::class)) {
          continue;
        }
        $target_definitions = $this->entityFieldManager->getBaseFieldDefinitions($target_type);
        if (!isset($target_definitions['development'])) {
          continue;
        }
        $ids = $this->entityTypeManager->getStorage($target_type)->getQuery()
          ->accessCheck(FALSE)
          ->condition('development', $development_id)
          ->execute();
        $form[$field_name]['widget']['#options'] = array_intersect_key(
          $form[$field_name]['widget']['#options'],
          ['_none' => TRUE] + array_flip($ids),
        );
      }
    }
    EntityFormLayout::apply($form, $entity, ['uid', 'status', 'responsible', 'assignees']);
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created %label.', $args)
      : $this->t('Saved %label.', $args));
    $development = $this->entity->get('development')->entity;
    $form_state->setRedirectUrl($development ? $development->toUrl() : $this->entity->toUrl());
    return $result;
  }

}

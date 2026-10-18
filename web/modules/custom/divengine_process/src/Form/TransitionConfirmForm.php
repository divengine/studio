<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\divengine_process\Lifecycle\LifecycleManager;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Lifecycle\Transition;
use Drupal\divengine_process\Lifecycle\TransitionException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Applies a transition that asks for confirmation or for a value.
 *
 * A transition with a field (block reason, decision rationale, target
 * iteration) shows that field here, so the user gives it while applying
 * the transition instead of editing the record first.
 */
class TransitionConfirmForm extends ConfirmFormBase {

  protected StatefulInterface $entity;

  protected Transition $transition;

  public function __construct(
    protected LifecycleManager $lifecycle,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('divengine_process.lifecycle'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'divengine_process_transition_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?StatefulInterface $entity = NULL, string $transition = ''): array {
    $this->entity = $entity;
    $this->transition = $entity::transitions()[$transition];
    $form = parent::buildForm($form, $form_state);

    if ($field_name = $this->transition->field) {
      $definition = $entity->getFieldDefinition($field_name);
      $element = [
        '#title' => $definition->getLabel(),
        '#description' => $definition->getDescription(),
        '#required' => TRUE,
        '#weight' => -10,
      ];
      if ($definition->getType() === 'entity_reference') {
        $storage = $this->entityTypeManager->getStorage($definition->getSetting('target_type'));
        $ids = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('development', $entity->get('development')->target_id)
          ->sort('weight')
          ->execute();
        $options = array_map(fn ($target) => $target->label(), $storage->loadMultiple($ids));
        $element += [
          '#type' => 'select',
          '#options' => $options,
          '#empty_option' => $this->t('- Choose -'),
          '#default_value' => $entity->get($field_name)->target_id,
        ];
        if (!$options) {
          $element['#description'] = $this->t('This Development has no iteration yet: add one first.');
        }
      }
      else {
        $element += [
          '#type' => 'textarea',
          '#rows' => 4,
          '#default_value' => $entity->get($field_name)->value,
        ];
      }
      $form['value'] = $element;
    }
    return $form;
  }

  public function getQuestion() {
    return $this->transition->confirm ?? $this->t('@transition %label?', [
      '@transition' => $this->transition->label,
      '%label' => $this->entity->label(),
    ]);
  }

  public function getDescription() {
    return $this->transition->confirm ? parent::getDescription() : '';
  }

  public function getConfirmText() {
    return $this->transition->label;
  }

  public function getCancelUrl(): Url {
    return $this->entity->toUrl();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($field_name = $this->transition->field) {
      $this->entity->set($field_name, $form_state->getValue('value'));
    }
    try {
      $this->lifecycle->apply($this->entity, $this->transition->id);
      $this->messenger()->addStatus($this->t('%label: @state.', [
        '%label' => $this->entity->label(),
        '@state' => $this->entity::stateLabels()[$this->entity->getState()] ?? $this->entity->getState(),
      ]));
    }
    catch (TransitionException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}

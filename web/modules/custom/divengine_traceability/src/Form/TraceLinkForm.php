<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding and editing trace links.
 *
 * Each end is stored as an (entity type, id) pair, so it gets a type selector
 * and one autocomplete per type; every other field renders through its widget.
 * New links can be prefilled from the query string, for example
 * ?source=diagram/4&target=artifact/7. Validation is the ValidTraceLink
 * entity constraint.
 */
class TraceLinkForm extends ContentEntityForm {

  /**
   * Entity types offered as ends, when they exist on the site.
   */
  const END_TYPES = ['artifact', 'diagram', 'node'];

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkInterface $link */
    $link = $this->entity;

    foreach (['source' => -20, 'target' => -15] as $end => $weight) {
      [$type, $entity] = $this->currentEnd($end);
      $types = $this->endTypes($type);
      $form[$end] = [
        '#type' => 'fieldset',
        '#title' => $end === 'source' ? $this->t('Source') : $this->t('Target'),
        '#weight' => $weight,
      ];
      $form[$end][$end . '_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Kind'),
        '#options' => $types,
        '#default_value' => $type && isset($types[$type]) ? $type : array_key_first($types),
        '#parents' => [$end . '_type'],
      ];
      foreach ($types as $type_id => $label) {
        $form[$end][$end . '_' . $type_id] = [
          '#type' => 'entity_autocomplete',
          '#title' => $label,
          '#target_type' => $type_id,
          '#default_value' => $type === $type_id ? $entity : NULL,
          '#parents' => [$end . '_entity', $type_id],
          '#states' => [
            'visible' => [':input[name="' . $end . '_type"]' => ['value' => $type_id]],
          ],
        ];
      }
    }
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach (['source', 'target'] as $end) {
      $type = $form_state->getValue($end . '_type');
      $value = $form_state->getValue([$end . '_entity', $type]);
      if ($value === NULL || $value === '') {
        $form_state->setErrorByName($end . '_entity][' . $type, $end === 'source'
          ? $this->t('Choose the source of the trace link.')
          : $this->t('Choose the target of the trace link.'));
      }
    }
    return parent::validateForm($form, $form_state);
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    foreach (['source', 'target'] as $end) {
      $type = $form_state->getValue($end . '_type');
      $value = $type ? $form_state->getValue([$end . '_entity', $type]) : NULL;
      if ($value !== NULL && $value !== '') {
        $entity->set($end . '_entity_type', $type);
        $entity->set($end . '_entity_id', (int) (is_object($value) ? $value->id() : $value));
      }
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the trace link %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

  /**
   * The kinds an end can be, keyed by entity type ID.
   *
   * @param string $current
   *   The kind already stored, kept even if it is not a default kind.
   */
  protected function endTypes(string $current = ''): array {
    $types = [];
    foreach (array_unique(array_filter([...static::END_TYPES, $current])) as $id) {
      if ($definition = $this->entityTypeManager->getDefinition($id, FALSE)) {
        $types[$id] = $definition->getSingularLabel();
      }
    }
    return array_map('ucfirst', array_map('strval', $types));
  }

  /**
   * The stored end, or for new links the one given in the query string.
   *
   * @return array{0: string, 1: ?\Drupal\Core\Entity\EntityInterface}
   */
  protected function currentEnd(string $end): array {
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkInterface $link */
    $link = $this->entity;
    if (!$link->isNew()) {
      return $end === 'source'
        ? [$link->getSourceEntityType(), $link->getSourceEntity()]
        : [$link->getTargetEntityType(), $link->getTargetEntity()];
    }
    $requested = (string) $this->getRequest()->query->get($end, '');
    if (preg_match('#^([a-z_][a-z0-9_]*)/(\d+)$#', $requested, $matches) && $this->entityTypeManager->hasDefinition($matches[1])) {
      $entity = $this->entityTypeManager->getStorage($matches[1])->load($matches[2]);
      if ($entity && $entity->access('view')) {
        return [$matches[1], $entity];
      }
    }
    return ['', NULL];
  }

}

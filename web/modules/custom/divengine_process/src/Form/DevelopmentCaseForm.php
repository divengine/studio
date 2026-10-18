<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_process\Entity\DevelopmentCase;
use Drupal\divengine_process\Readiness\ReadinessCriterionManager;
use Drupal\divengine_process\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for Development Cases and their tailoring lines.
 *
 * A line's element is chosen in one grouped select whose values are
 * "element_type:target".
 */
class DevelopmentCaseForm extends ProcessConfigForm {

  protected ReadinessCriterionManager $criteria;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->criteria = $container->get('plugin.manager.divengine_readiness');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\DevelopmentCase $entity */
    $entity = $this->entity;

    $form['is_default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Default'),
      '#default_value' => $entity->isDefault(),
      '#description' => $this->t('Applied to new Developments that do not choose a case. Only one case is the default.'),
    ];
    if (!$entity->isNew()) {
      $form['version'] = [
        '#type' => 'item',
        '#title' => $this->t('Version'),
        '#markup' => $entity->getVersion(),
        '#description' => $this->t('Saving changed lines increments the version. Developments keep the snapshot they applied until someone re-applies the case.'),
      ];
    }

    if (!$form_state->has('lines')) {
      $form_state->set('lines', $entity->getLines());
    }
    $lines = $form_state->get('lines');

    $form['lines'] = [
      '#type' => 'table',
      '#caption' => $this->t('Tailoring'),
      '#header' => [
        $this->t('Element'),
        $this->t('Milestone'),
        $this->t('Level'),
        $this->t('Note'),
        $this->t('Remove'),
        $this->t('Order'),
      ],
      '#empty' => $this->t('No lines yet.'),
      '#tabledrag' => [[
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'case-line-weight',
      ]],
      '#prefix' => '<div id="case-lines">',
      '#suffix' => '</div>',
    ];
    $elements = $this->elementOptions();
    foreach ($lines as $delta => $line) {
      $line = DevelopmentCase::normalizeLine($line);
      $form['lines'][$delta] = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $line['weight'],
        'element' => [
          '#type' => 'select',
          '#title' => $this->t('Element'),
          '#title_display' => 'invisible',
          '#options' => $elements,
          '#empty_option' => $this->t('- Choose -'),
          '#default_value' => $line['element_type'] !== '' ? $line['element_type'] . ':' . $line['target'] : '',
        ],
        'milestone_type' => [
          '#type' => 'select',
          '#title' => $this->t('Milestone'),
          '#title_display' => 'invisible',
          '#options' => Vocabulary::milestoneTypes(),
          '#empty_option' => $this->t('Every milestone'),
          '#default_value' => $line['milestone_type'],
        ],
        'level' => [
          '#type' => 'select',
          '#title' => $this->t('Level'),
          '#title_display' => 'invisible',
          '#options' => Vocabulary::levels(),
          '#default_value' => $line['level'],
        ],
        'note' => [
          '#type' => 'textfield',
          '#title' => $this->t('Note'),
          '#title_display' => 'invisible',
          '#default_value' => $line['note'],
          '#size' => 30,
        ],
        'remove' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Remove'),
          '#title_display' => 'invisible',
        ],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Order'),
          '#title_display' => 'invisible',
          '#delta' => 100,
          '#default_value' => $line['weight'],
          '#attributes' => ['class' => ['case-line-weight']],
        ],
      ];
    }
    $form['add_line'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add a line'),
      '#submit' => ['::addLine'],
      '#limit_validation_errors' => [['lines']],
    ];
    return $form;
  }

  /**
   * Keeps the edited rows and appends an empty one.
   */
  public function addLine(array &$form, FormStateInterface $form_state): void {
    $lines = $this->linesFromValues($form_state->getValue('lines') ?: [], FALSE);
    $lines[] = DevelopmentCase::normalizeLine(['weight' => count($lines) ? max(array_column($lines, 'weight')) + 1 : 0]);
    $form_state->set('lines', $lines);
    $form_state->setRebuild();
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $case = clone $this->entity;
    $case->setLines($this->linesFromValues($form_state->getValue('lines') ?: []));
    foreach ($case->validateLines() as $error) {
      $form_state->setErrorByName('lines', $error);
    }
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $lines = $this->linesFromValues($values['lines'] ?? []);
    unset($values['lines'], $values['add_line'], $values['version']);
    foreach ($values as $key => $value) {
      if (in_array($key, ['label', 'id', 'description', 'weight', 'status', 'is_default'], TRUE)) {
        $entity->set($key, match ($key) {
          'weight' => (int) $value,
          'status', 'is_default' => (bool) $value,
          default => (string) $value,
        });
      }
    }
    /** @var \Drupal\divengine_process\Entity\DevelopmentCase $entity */
    $entity->setLines($lines);
  }

  /**
   * Turns submitted table rows into lines, ordered by weight.
   *
   * @param array<int, array<string, mixed>> $rows
   *   The submitted rows.
   * @param bool $drop_empty
   *   Whether rows without an element are dropped (on save) or kept (while
   *   adding rows).
   *
   * @return array<int, array<string, mixed>>
   */
  protected function linesFromValues(array $rows, bool $drop_empty = TRUE): array {
    $lines = [];
    foreach ($rows as $row) {
      if (!empty($row['remove']) || ($drop_empty && empty($row['element']))) {
        continue;
      }
      [$element_type, $target] = array_pad(explode(':', (string) ($row['element'] ?? ''), 2), 2, '');
      $lines[] = DevelopmentCase::normalizeLine([
        'element_type' => $element_type,
        'target' => $target,
        'milestone_type' => $row['milestone_type'] ?? '',
        'level' => $row['level'] ?? 'recommended',
        'note' => trim((string) ($row['note'] ?? '')),
        'weight' => $row['weight'] ?? 0,
      ]);
    }
    usort($lines, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);
    return $lines;
  }

  /**
   * Grouped options for the element of a line.
   *
   * @return array<string, array<string, string>>
   */
  protected function elementOptions(): array {
    /** @var \Drupal\divengine_process\Entity\DevelopmentCase $entity */
    $entity = $this->entity;
    $groups = [];
    $types = Vocabulary::caseElementTypes();
    foreach (Vocabulary::caseElementEntityTypes() as $element_type => $entity_type_id) {
      $selected = array_column(array_filter($entity->getLines(), fn ($l) => $l['element_type'] === $element_type), 'target');
      foreach ($this->referenceOptions($entity_type_id, $selected) as $id => $label) {
        $groups[(string) $types[$element_type]][$element_type . ':' . $id] = $label;
      }
    }
    foreach ($this->readinessCriteria() as $code => $label) {
      $groups[(string) $types['readiness']]['readiness:' . $code] = $label;
    }
    return $groups;
  }

  /**
   * Readiness criteria that can be chosen, from the criterion plugins.
   *
   * @return array<string, string>
   */
  protected function readinessCriteria(): array {
    return $this->criteria->options();
  }

}

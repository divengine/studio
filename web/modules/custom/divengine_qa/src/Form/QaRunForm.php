<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;
use Drupal\divengine_qa\QaRunWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form of a test run, for QA Leads.
 *
 * Planning fields only; the state moves through the run's actions. The
 * Tester is chosen at creation; once set (or once there is work) it changes
 * only through Reassign. New runs from a plan (?plan=ID) propose the
 * environment's test URL.
 */
class QaRunForm extends ContentEntityForm {

  protected QaRunWorkflow $runs;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->runs = $container->get('divengine_qa.runs');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\divengine_qa\Entity\QaTestRun $run */
    $run = $this->entity;
    if ($run->isNew() && $run->get('plan')->isEmpty() && ($id = $this->getRequest()->query->get('plan'))) {
      $plan = $this->entityTypeManager->getStorage('qa_test_plan')->load($id);
      if ($plan && $plan->access('view')) {
        $run->set('plan', $plan->id());
        if ($run->get('test_url')->value === NULL) {
          $run->set('test_url', $plan->get('environment')->entity?->getDefaultTestUrl() ?? '');
        }
      }
    }
    $form = parent::form($form, $form_state);
    if (!$run->get('plan')->isEmpty() && isset($form['plan'])) {
      $form['plan']['#access'] = $run->isNew() ? FALSE : !$this->runs->executions($run);
    }
    if (!$run->isNew() && isset($form['tester']) && (!$run->get('tester')->isEmpty() || $this->runs->hasTesterWork($run))) {
      $form['tester']['#disabled'] = TRUE;
      $form['tester']['widget'][0]['target_id']['#description'] = $this->t('Use Reassign on the Run to change the Tester.');
    }
    EntityFormLayout::apply($form, $run, ['tester', 'is_automated', 'start_date', 'end_date'], ['tags']);
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created %label with @count executions.', ['%label' => $this->entity->label(), '@count' => count($this->runs->executions($this->entity))])
      : $this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}

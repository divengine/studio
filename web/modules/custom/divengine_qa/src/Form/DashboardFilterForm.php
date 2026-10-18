<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Development and Environment filters of the QA dashboard.
 */
class DashboardFilterForm extends FormBase {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'divengine_qa_dashboard_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;
    $developments = $this->entityTypeManager->getStorage('development');
    $options = array_map(fn ($d) => $d->label(), $developments->loadMultiple($developments->getQuery()->accessCheck(TRUE)->sort('name')->execute()));
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [static::class, 'cleanUp'];
    $form['#attributes']['class'][] = 'container-inline';
    $form['development'] = [
      '#type' => 'select',
      '#title' => $this->t('Development'),
      '#options' => $options,
      '#empty_option' => $this->t('All Developments'),
      '#default_value' => $query->get('development'),
    ];
    $form['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => array_map(fn ($e) => $e->label(), $this->entityTypeManager->getStorage('qa_environment')->loadMultiple()),
      '#empty_option' => $this->t('All Environments'),
      '#default_value' => $query->get('environment'),
    ];
    $form['apply'] = ['#type' => 'submit', '#value' => $this->t('Apply'), '#name' => ''];
    return $form;
  }

  /**
   * Keeps the GET query to the filters only.
   */
  public static function cleanUp(array $form): array {
    unset($form['form_build_id'], $form['form_id'], $form['form_token']);
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}

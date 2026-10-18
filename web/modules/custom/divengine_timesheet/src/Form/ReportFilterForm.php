<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters of the timesheet report, as a GET form.
 */
class ReportFilterForm extends FormBase {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'divengine_timesheet_report_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $from = '', string $to = ''): array {
    $q = $this->getRequest()->query;
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [static::class, 'cleanUp'];
    $form['#attributes']['class'][] = 'dv-filter-form';
    $form['from'] = ['#type' => 'date', '#title' => $this->t('From'), '#default_value' => $from];
    $form['to'] = ['#type' => 'date', '#title' => $this->t('To'), '#default_value' => $to];
    $projects = $this->entityTypeManager->getStorage('project');
    $form['project'] = [
      '#type' => 'select',
      '#title' => $this->t('Project'),
      '#options' => array_map(fn ($p) => $p->label(), $projects->loadMultiple($projects->getQuery()->accessCheck(TRUE)->sort('name')->execute())),
      '#empty_option' => $this->t('All'),
      '#default_value' => $q->get('project'),
    ];
    $account = $this->currentUser();
    if ($account->hasPermission('manage timesheets') || $account->hasPermission('administer projects')) {
      $users = $this->entityTypeManager->getStorage('user');
      $form['person'] = [
        '#type' => 'select',
        '#title' => $this->t('Person'),
        '#options' => array_map(fn ($u) => $u->getDisplayName(), $users->loadMultiple($users->getQuery()->accessCheck(TRUE)->condition('status', 1)->condition('uid', 0, '>')->sort('name')->execute())),
        '#empty_option' => $this->t('Everyone'),
        '#default_value' => $q->get('person'),
      ];
    }
    $form['group'] = [
      '#type' => 'select',
      '#title' => $this->t('Group by'),
      '#options' => ['person' => $this->t('Person'), 'project' => $this->t('Project'), 'task' => $this->t('Task')],
      '#default_value' => $q->get('group', 'person'),
    ];
    $form['apply'] = ['#type' => 'submit', '#value' => $this->t('Apply'), '#name' => ''];
    return $form;
  }

  public static function cleanUp(array $form): array {
    unset($form['form_build_id'], $form['form_id'], $form['form_token']);
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}

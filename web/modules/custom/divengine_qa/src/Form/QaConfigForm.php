<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_process\Form\ProcessConfigForm;
use Drupal\divengine_qa\Entity\QaEnvironment;
use Drupal\divengine_qa\TestUrl;

/**
 * Form for the QA configuration: environments, stages, priorities, tags.
 */
class QaConfigForm extends ProcessConfigForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->entity;
    if ($entity instanceof QaEnvironment) {
      $form['default_test_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Default Test URL'),
        '#default_value' => $entity->getDefaultTestUrl(),
        '#maxlength' => 2048,
        '#description' => $this->t('Copied once into new Test Runs of plans in this environment, e.g. https://test.example.com.'),
      ];
    }
    if (method_exists($entity, 'getColor')) {
      $form['color'] = [
        '#type' => 'color',
        '#title' => $this->t('Color'),
        '#default_value' => $entity->getColor(),
      ];
    }
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->hasValue('default_test_url')) {
      $url = TestUrl::normalize($form_state->getValue('default_test_url'));
      if (!TestUrl::isValid($url)) {
        $form_state->setErrorByName('default_test_url', $this->t("The Default Test URL must be a complete http:// or https:// address, for example https://test.example.com (got '@value').", ['@value' => $url]));
      }
      $form_state->setValue('default_test_url', $url);
    }
  }

}

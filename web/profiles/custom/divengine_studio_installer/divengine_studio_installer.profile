<?php

/**
 * @file
 * Divengine Studio installation profile.
 */

declare(strict_types=1);

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form.
 *
 * Suggests the product name; a name the operator types, or passes to a
 * non-interactive installer, is kept.
 */
function divengine_studio_installer_form_install_configure_form_alter(array &$form, FormStateInterface $form_state): void {
  if (isset($form['site_information']['site_name'])) {
    $form['site_information']['site_name']['#default_value'] = 'Divengine Studio';
  }
}

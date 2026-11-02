<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\UserSession;
use Drupal\divengine_mcp_write\ContentModels;
use Drupal\divengine_mcp_write\Entity\McpContentCapability;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures what MCP clients may do with one content bundle (#86).
 *
 * A new capability first picks a supported bundle; only fields that may be
 * writable are offered. Everything starts disabled.
 */
class McpContentCapabilityForm extends EntityForm {

  protected ContentModels $models;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->models = $container->get('divengine_mcp_write.content_models');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_mcp_write\Entity\McpContentCapability $capability */
    $capability = $this->entity;
    $target = $capability->isNew() ? (string) $form_state->get('target') : $capability->id();

    if ($target === '') {
      $options = $this->supportedBundles();
      $form['target'] = [
        '#type' => 'select',
        '#title' => $this->t('Content type'),
        '#options' => $options,
        '#required' => TRUE,
        '#description' => $options
          ? $this->t('Only bundles of content entity types that are revisionable with a log, owned, publishable and not moderated are offered; people, files, OAuth records and Studio records with their own workflows never are.')
          : $this->t('No bundle on this site can be exposed generically.'),
      ];
      return $form;
    }

    [$entity_type_id, $bundle] = explode('--', $target, 2);
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Content type'),
      '#markup' => $this->entityTypeManager->getDefinition($entity_type_id)->getLabel() . ': ' . ($bundles[$bundle]['label'] ?? $bundle) . ' (' . $target . ')',
    ];
    $form['operations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Operations'),
      '#options' => [
        'read' => $this->t('Read: list and read records (every MCP client, as its person)'),
        'create' => $this->t('Create: new records are saved unpublished and owned by the person'),
        'update' => $this->t('Update: new revisions; publication, owner, Development and language never change'),
        'delete' => $this->t('Delete: needs confirmation from the client; off unless needed'),
      ],
      '#default_value' => $capability->getOperations(),
      '#description' => $this->t('Writes need an OAuth client with the mcp_write scope of a person with the MCP writer role, and that person\'s own access to each record.'),
    ];
    $candidates = $this->models->writableCandidates($entity_type_id, $bundle);
    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Writable fields'),
      '#options' => array_map(fn ($definition) => $definition->getLabel() . ' (' . $definition->getName() . ', ' . $definition->getType() . ')', $candidates),
      '#default_value' => array_intersect($capability->getWritableFields(), array_keys($candidates)),
      '#description' => $this->t('Fields that decide identity, ownership, publication, moderation, revisions or the Development, and fields of unsupported types, are never offered.'),
    ];
    $development_fields = $this->models->developmentFields($entity_type_id, $bundle);
    $form['development_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Development field'),
      '#options' => array_combine($development_fields, $development_fields),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $capability->getDevelopmentField(),
      '#access' => (bool) $development_fields,
      '#description' => $this->t('Set from the Development code on create, only to a Development the person may add records to, and never changed afterwards.'),
    ];
    $form['require_development'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Creating a record needs a Development'),
      '#default_value' => $capability->requiresDevelopment(),
      '#access' => (bool) $development_fields,
      '#states' => ['visible' => [':input[name="development_field"]' => ['!value' => '']]],
    ];

    // What the scope role of write tokens allows, since a token can do what
    // both its person and that role can do.
    $scope_role = $this->entityTypeManager->getStorage('oauth2_scope')->load('mcp_write')?->getGranularity()->getConfiguration()['role'] ?? NULL;
    if ($scope_role) {
      $session = new UserSession(['uid' => 0, 'roles' => ['authenticated', $scope_role]]);
      $create = $this->entityTypeManager->getAccessControlHandler($entity_type_id)->createAccess($bundle, $session);
      $form['scope_role'] = [
        '#type' => 'item',
        '#title' => $this->t('Write tokens'),
        '#markup' => $create
          ? $this->t('The %role role of the mcp_write scope may create %target records.', ['%role' => $scope_role, '%target' => $target])
          : $this->t('The %role role of the mcp_write scope cannot create %target records yet: grant it the bundle\'s permissions (for nodes, create, edit own and "view own unpublished content") if writes should work. This form changes no permission.', ['%role' => $scope_role, '%target' => $target]),
      ];
    }
    $form['label'] = ['#type' => 'value', '#value' => $bundles[$bundle]['label'] ?? $bundle];
    return $form;
  }

  protected function actions(array $form, FormStateInterface $form_state): array {
    if ($this->entity->isNew() && !$form_state->get('target')) {
      return ['choose' => ['#type' => 'submit', '#value' => $this->t('Continue'), '#submit' => ['::choose']]];
    }
    return parent::actions($form, $form_state);
  }

  /**
   * Remembers the chosen bundle and shows its settings.
   */
  public function choose(array &$form, FormStateInterface $form_state): void {
    $form_state->set('target', $form_state->getValue('target'));
    $form_state->setRebuild();
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->entity->isNew() && !$form_state->get('target')) {
      if (!isset($this->supportedBundles()[$form_state->getValue('target')])) {
        $form_state->setErrorByName('target', $this->t('Choose a supported content type.'));
      }
      return;
    }
    if ($this->entity->isNew() && $this->entityTypeManager->getStorage('mcp_content_capability')->load($form_state->get('target'))) {
      $form_state->setErrorByName('summary', $this->t('This content type is already configured.'));
      return;
    }
    parent::validateForm($form, $form_state);
    /** @var \Drupal\divengine_mcp_write\Entity\McpContentCapability $capability */
    $capability = $this->buildEntity($form, $form_state);
    foreach ($this->models->capabilityProblems($capability) as $problem) {
      $form_state->setErrorByName('operations', $problem);
    }
  }

  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\divengine_mcp_write\Entity\McpContentCapability $entity */
    $target = $entity->isNew() ? (string) $form_state->get('target') : $entity->id();
    [$entity_type_id, $bundle] = explode('--', $target, 2) + [1 => ''];
    $entity->set('id', $target);
    $entity->set('label', (string) $form_state->getValue('label'));
    $entity->set('entity_type', $entity_type_id);
    $entity->set('bundle', $bundle);
    $entity->set('operations', array_values(array_filter((array) $form_state->getValue('operations'))));
    $entity->set('fields', array_values(array_filter((array) $form_state->getValue('fields'))));
    $entity->set('development_field', (string) $form_state->getValue('development_field'));
    $entity->set('require_development', (bool) $form_state->getValue('require_development') && (string) $form_state->getValue('development_field') !== '');
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the MCP capability for %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

  /**
   * Supported bundles not configured yet, keyed by capability ID.
   *
   * @return string[]
   */
  protected function supportedBundles(): array {
    $existing = $this->entityTypeManager->getStorage('mcp_content_capability')->loadMultiple();
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if ($this->models->entityTypeProblem($entity_type_id)) {
        continue;
      }
      foreach (\Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id) as $bundle => $info) {
        $id = McpContentCapability::idFor($entity_type_id, $bundle);
        if (!isset($existing[$id]) && !$this->models->bundleProblem($entity_type_id, $bundle)) {
          $options[$id] = $definition->getLabel() . ': ' . $info['label'];
        }
      }
    }
    asort($options);
    return $options;
  }

}

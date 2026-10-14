<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\divengine_traceability\TraceLinkValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TraceLinkForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
    protected TraceLinkValidator $traceLinkValidator,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('divengine_traceability.trace_link_validator'),
    );
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $link = $this->entity;

    $source_artifact = $this->loadArtifact(
      $link->getSourceEntityType(),
      $link->getSourceEntityId()
    );
    $target_artifact = $this->loadArtifact(
      $link->getTargetEntityType(),
      $link->getTargetEntityId()
    );

    if ($link->getSourceEntityType() && $link->getSourceEntityType() !== 'artifact') {
      $this->messenger()->addWarning($this->t('Source entity type is "@type", not artifact. Only artifact-to-artifact links are supported in this form.', [
        '@type' => $link->getSourceEntityType(),
      ]));
    }
    if ($link->getTargetEntityType() && $link->getTargetEntityType() !== 'artifact') {
      $this->messenger()->addWarning($this->t('Target entity type is "@type", not artifact. Only artifact-to-artifact links are supported in this form.', [
        '@type' => $link->getTargetEntityType(),
      ]));
    }

    $form['source_artifact'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Source artifact'),
      '#target_type' => 'artifact',
      '#required' => TRUE,
      '#default_value' => $source_artifact,
      '#weight' => -10,
    ];

    $form['link_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Link type'),
      '#required' => TRUE,
      '#options' => [
        'derives_from' => $this->t('Derives from'),
        'satisfies' => $this->t('Satisfies'),
        'realizes' => $this->t('Realizes'),
        'validates' => $this->t('Validates'),
        'tests' => $this->t('Tests'),
        'depends_on' => $this->t('Depends on'),
        'conflicts_with' => $this->t('Conflicts with'),
        'mitigates' => $this->t('Mitigates'),
        'impacts' => $this->t('Impacts'),
        'documents' => $this->t('Documents'),
        'supersedes' => $this->t('Supersedes'),
        'references' => $this->t('References'),
      ],
      '#default_value' => $link->get('link_type')->value ?? '',
      '#weight' => -9,
    ];

    $form['target_artifact'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Target artifact'),
      '#target_type' => 'artifact',
      '#required' => TRUE,
      '#default_value' => $target_artifact,
      '#weight' => -8,
    ];

    $form['rationale'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rationale'),
      '#default_value' => $link->get('rationale')->value ?? '',
      '#weight' => -5,
    ];

    $form['confidence'] = [
      '#type' => 'select',
      '#title' => $this->t('Confidence'),
      '#options' => [
        'confirmed' => $this->t('Confirmed'),
        'high' => $this->t('High'),
        'medium' => $this->t('Medium'),
        'low' => $this->t('Low'),
        'inferred' => $this->t('Inferred'),
      ],
      '#default_value' => $link->get('confidence')->value ?? 'medium',
      '#weight' => -4,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => $link->isPublished(),
      '#weight' => 0,
    ];

    $form['reviewed_by'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Reviewed by'),
      '#target_type' => 'user',
      '#default_value' => $link->get('reviewed_by')->entity,
      '#weight' => 5,
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $source = $form_state->getValue('source_artifact');
    $target = $form_state->getValue('target_artifact');

    if (!empty($source) && !empty($target)) {
      $source_id = is_object($source) ? $source->id() : (int) $source;
      $target_id = is_object($target) ? $target->id() : (int) $target;

      $errors = $this->traceLinkValidator->validateAll('artifact', $source_id, 'artifact', $target_id);

      foreach ($errors as $error) {
        $form_state->setError($form, $error);
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source = $form_state->getValue('source_artifact');
    $target = $form_state->getValue('target_artifact');

    $source_id = is_object($source) ? $source->id() : (int) $source;
    $target_id = is_object($target) ? $target->id() : (int) $target;

    $this->entity->set('source_entity_type', 'artifact');
    $this->entity->set('source_entity_id', $source_id);
    $this->entity->set('target_entity_type', 'artifact');
    $this->entity->set('target_entity_id', $target_id);

    parent::submitForm($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the trace link.'));
    $form_state->setRedirect('entity.trace_link.canonical', ['trace_link' => $this->entity->id()]);
    return $result;
  }

  protected function loadArtifact(string $entity_type, int $entity_id): ?object {
    if ($entity_type === 'artifact' && $entity_id > 0) {
      return $this->entityTypeManager->getStorage('artifact')->load($entity_id);
    }
    return NULL;
  }

}

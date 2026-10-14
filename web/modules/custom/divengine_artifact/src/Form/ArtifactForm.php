<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ArtifactForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
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
    );
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $artifact = $this->entity;

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $artifact->getTitle(),
      '#weight' => -10,
    ];

    $form['artifact_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Artifact key'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#default_value' => $artifact->getArtifactKey(),
      '#weight' => -9,
      '#element_validate' => [[$this, 'validateArtifactKey']],
    ];

    $form['summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summary'),
      '#default_value' => $artifact->get('summary')->value ?? '',
      '#weight' => -8,
    ];

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $artifact->get('body')->value ?? '',
      '#rows' => 10,
      '#weight' => -7,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => $artifact->isPublished(),
      '#weight' => 0,
    ];

    $form['uid'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Owner'),
      '#target_type' => 'user',
      '#default_value' => $artifact->getOwnerId() ? $artifact->getOwner() : NULL,
      '#weight' => 5,
    ];

    return $form;
  }

  public function validateArtifactKey(array &$element, FormStateInterface $form_state): void {
    $key = trim($element['#value']);
    $form_state->setValueForElement($element, $key);

    if (empty($key)) {
      $form_state->setError($element, new TranslatableMarkup('Artifact key is required.'));
      return;
    }

    $storage = $this->entityTypeManager->getStorage('artifact');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('artifact_key', $key);

    $artifact = $form_state->getFormObject()->getEntity();
    if (!$artifact->isNew()) {
      $query->condition('id', $artifact->id(), '<>');
    }

    if ($query->count()->execute() > 0) {
      $form_state->setError($element, new TranslatableMarkup('The artifact key %key is already in use.', ['%key' => $key]));
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label artifact.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.artifact.canonical', ['artifact' => $this->entity->id()]);
    return $result;
  }

}

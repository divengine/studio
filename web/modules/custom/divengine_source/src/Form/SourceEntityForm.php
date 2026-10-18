<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;
use Drupal\divengine_source\Entity\SourceRef;
use Drupal\divengine_source\SourceGit;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form of repositories and refs.
 *
 * "Add repository" in a Development passes ?development=ID; "Add ref" on a
 * repository passes ?repository=ID.
 */
class SourceEntityForm extends ContentEntityForm {

  protected SourceGit $git;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->git = $container->get('divengine_source.git');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->entity;
    $query = $this->getRequest()->query;
    if ($entity->isNew()) {
      if ($entity instanceof SourceRef && ($id = $query->get('repository')) && ($repository = $this->entityTypeManager->getStorage('source_repository')->load($id)) && $repository->access('update')) {
        $entity->set('repository', $repository->id());
      }
      if (!$entity instanceof SourceRef && ($id = $query->get('development')) && ($development = $this->entityTypeManager->getStorage('development')->load($id)) && $development->access('view')) {
        $entity->set('development', $development->id());
      }
    }
    $form = parent::form($form, $form_state);
    if ($entity instanceof SourceRef) {
      if ($repository = $entity->getRepository()) {
        $form['repository_info'] = ['#type' => 'item', '#title' => $this->t('Repository'), '#markup' => $repository->toLink()->toString(), '#weight' => -20];
      }
      if ($entity->get('last_snapshot')->target_id && isset($form['name'])) {
        $form['name']['widget'][0]['value']['#disabled'] = TRUE;
        $form['name']['widget'][0]['value']['#description'] = $this->t('Indexed refs keep their name: register a new ref instead.');
      }
    }
    else {
      if (!$entity->get('development')->isEmpty() && $entity->isNew()) {
        $form['development']['#access'] = FALSE;
      }
      $roots = $this->git->roots();
      $form['path']['widget'][0]['value']['#description'] .= ' ' . $this->t('Allowed roots: @roots.', ['@roots' => implode(', ', $roots) ?: $this->t('none configured')]);
      if (!$entity->isNew() && $entity->hasHistory()) {
        $form['path']['widget'][0]['value']['#disabled'] = TRUE;
      }
      EntityFormLayout::apply($form, $entity, ['development', 'url'], ['description']);
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($result === SAVED_NEW ? $this->t('Registered %label.', ['%label' => $this->entity->label()]) : $this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $target = $this->entity instanceof SourceRef ? $this->entity->getRepository() : $this->entity;
    $form_state->setRedirectUrl($target->toUrl());
    return $result;
  }

}

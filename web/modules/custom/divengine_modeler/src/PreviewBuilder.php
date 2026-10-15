<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_modeler\Entity\DiagramInterface;

/**
 * Prepares a stored preview for one viewer.
 *
 * Linked elements carry data-divengine-element (set by the notation's
 * exporter). An element whose target the viewer can see becomes a link to it
 * with a data-entity-uri attribute; one the viewer cannot see is marked as
 * restricted and shows nothing about the target.
 */
class PreviewBuilder {

  const ELEMENT_ATTRIBUTE = 'data-divengine-element';

  public function __construct(
    protected LinkIndex $linkIndex,
    protected EntityReferenceResolver $resolver,
  ) {}

  /**
   * The preview of a diagram as served to an account.
   */
  public function build(DiagramInterface $diagram, AccountInterface $account): string {
    $svg = $diagram->getPreviewSvg();
    $document = ModelerNotationBase::parseXml($svg);
    if (!$document) {
      return '';
    }
    $links = [];
    foreach ($diagram->getNotation()->extractLinks($diagram->getSource(), $diagram->getBindings()) as $link) {
      $links[$link['element']] = $link['entity'];
    }
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//*[@' . static::ELEMENT_ATTRIBUTE . ']')) as $element) {
      $uri = $links[$element->getAttribute(static::ELEMENT_ATTRIBUTE)] ?? NULL;
      if ($uri !== NULL) {
        $this->linkElement($element, $uri, $account);
      }
    }
    // Native link syntax of text notations: <a href="entity:node/42">.
    foreach (iterator_to_array($xpath->query('//*[local-name()="a"]')) as $anchor) {
      foreach (['href', 'xlink:href'] as $attribute) {
        $href = $anchor->getAttribute($attribute);
        if (str_starts_with($href, 'entity:')) {
          $url = $this->urlFor($href, $account);
          $url === NULL ? $anchor->removeAttribute($attribute) : $anchor->setAttribute($attribute, $url);
        }
      }
    }
    return $document->saveXML($document->documentElement);
  }

  protected function linkElement(\DOMElement $element, string $uri, AccountInterface $account): void {
    $url = $this->urlFor($uri, $account);
    if ($url === NULL) {
      $element->setAttribute('data-divengine-restricted', '1');
      return;
    }
    $element->setAttribute('data-entity-uri', $uri);
    $anchor = $element->ownerDocument->createElementNS(SvgSafety::SVG_NAMESPACE, 'a');
    $anchor->setAttribute('href', $url);
    $anchor->setAttribute('target', '_top');
    $element->parentNode->replaceChild($anchor, $element);
    $anchor->appendChild($element);
  }

  /**
   * The canonical URL of a target the account may view, or NULL.
   */
  protected function urlFor(string $uri, AccountInterface $account): ?string {
    $entity = $this->resolver->load($uri);
    if (!$entity || !$entity->hasLinkTemplate('canonical') || !$entity->access('view', $account)) {
      return NULL;
    }
    return $entity->toUrl()->toString();
  }

}

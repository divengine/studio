<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

/**
 * Checks SVG previews before they are stored or served.
 *
 * Previews are produced by engines running in the browser, so the server
 * treats them as untrusted input: anything that can run code, embed other
 * documents or fetch remote resources is rejected, never repaired.
 */
class SvgSafety {

  /**
   * Largest accepted preview, in bytes.
   */
  const MAX_BYTES = 5_000_000;

  const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

  /**
   * Elements that run code, embed documents or animate attributes.
   */
  const FORBIDDEN_ELEMENTS = [
    'script', 'foreignobject', 'iframe', 'object', 'embed', 'handler', 'listener',
    'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'discard',
  ];

  /**
   * Checks an SVG document.
   *
   * @return string[]
   *   Error messages; empty when the SVG is safe.
   */
  public function check(string $svg): array {
    if (strlen($svg) > static::MAX_BYTES) {
      return [sprintf('The preview exceeds the %d byte limit.', static::MAX_BYTES)];
    }
    $errors = [];
    $document = ModelerNotationBase::parseXml($svg, $errors);
    if (!$document) {
      return array_map(static fn (string $error): string => 'Invalid SVG: ' . $error, $errors);
    }
    $root = $document->documentElement;
    if ($root->localName !== 'svg' || $root->namespaceURI !== static::SVG_NAMESPACE) {
      return ['The preview is not an SVG document.'];
    }
    foreach ($document->getElementsByTagName('*') as $element) {
      $errors = array_merge($errors, $this->checkElement($element));
      if (count($errors) >= 10) {
        break;
      }
    }
    return array_values(array_unique($errors));
  }

  /**
   * @return string[]
   */
  protected function checkElement(\DOMElement $element): array {
    $errors = [];
    $name = strtolower($element->localName);
    if (in_array($name, static::FORBIDDEN_ELEMENTS, TRUE)) {
      $errors[] = sprintf('The preview contains a forbidden <%s> element.', $element->localName);
    }
    if ($name === 'style' && !$this->isSafeCss($element->textContent)) {
      $errors[] = 'The preview contains a style sheet that loads external resources.';
    }
    foreach ($element->attributes as $attribute) {
      $attribute_name = strtolower($attribute->localName);
      if (str_starts_with($attribute_name, 'on')) {
        $errors[] = sprintf('The preview contains an event handler attribute (%s).', $attribute->nodeName);
      }
      elseif ($attribute_name === 'href' || $attribute_name === 'src') {
        if (!$this->isSafeReference($attribute->value, $name === 'a')) {
          $errors[] = sprintf('The preview contains an unsafe reference (%s).', mb_substr($attribute->value, 0, 60));
        }
      }
      elseif ($attribute_name === 'style' && !$this->isSafeCss($attribute->value)) {
        $errors[] = 'The preview contains an inline style that loads external resources.';
      }
    }
    return $errors;
  }

  /**
   * Whether an href may stay in a stored preview.
   *
   * Every element may point to a fragment or an embedded raster image. Only
   * links (<a>) may also navigate: to entity URIs (rewritten when served),
   * site-relative paths and web or mail addresses. Script schemes, other data
   * URIs and protocol-relative URLs are never allowed, and nothing but a link
   * may reference a remote resource, so serving a preview fetches nothing.
   */
  public function isSafeReference(string $value, bool $navigation = TRUE): bool {
    $value = trim(preg_replace('/[\x00-\x20]+/', '', $value));
    if ($value === '' || str_starts_with($value, '#')) {
      return TRUE;
    }
    if (str_starts_with($value, '//')) {
      return FALSE;
    }
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $value)) {
      return TRUE;
    }
    if (!$navigation) {
      return FALSE;
    }
    if (preg_match('#^(https?:|mailto:|entity:)#i', $value)) {
      return TRUE;
    }
    // Anything else with a scheme (javascript:, data:, vbscript:...) is out.
    return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $value);
  }

  /**
   * Whether CSS only references fragments and loads nothing remote.
   */
  public function isSafeCss(string $css): bool {
    if (preg_match('/@import|expression\s*\(|javascript:|behavior\s*:/i', $css)) {
      return FALSE;
    }
    preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $matches);
    foreach ($matches[2] as $url) {
      if (!str_starts_with(trim($url), '#') && !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', trim($url))) {
        return FALSE;
      }
    }
    return TRUE;
  }

}

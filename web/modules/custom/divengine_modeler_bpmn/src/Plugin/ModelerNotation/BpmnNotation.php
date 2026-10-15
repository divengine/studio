<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler_bpmn\Plugin\ModelerNotation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_modeler\Attribute\ModelerNotation;
use Drupal\divengine_modeler\ModelerNotationBase;

/**
 * BPMN 2.0, edited with bpmn-js.
 *
 * Element links are extension attributes in the Divengine namespace on the
 * linked element: divengine:entity="entity:node/42" and divengine:label.
 */
#[ModelerNotation(
  id: 'bpmn',
  label: new TranslatableMarkup('BPMN 2.0'),
  family: 'canvas',
  source_format: 'xml',
  editor_library: 'divengine_modeler_bpmn/editor',
  preview_library: 'divengine_modeler_bpmn/editor',
  file_extension: 'bpmn',
  description: new TranslatableMarkup('Business Process Model and Notation.'),
)]
class BpmnNotation extends ModelerNotationBase {

  const MODEL_NAMESPACE = 'http://www.omg.org/spec/BPMN/20100524/MODEL';

  const DIVENGINE_NAMESPACE = 'https://divengine.com/schema/modeler/1.0';

  public function defaultSource(): string {
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" xmlns:divengine="https://divengine.com/schema/modeler/1.0" id="Definitions_1" targetNamespace="https://divengine.com/bpmn">
  <bpmn:process id="Process_1" isExecutable="false">
    <bpmn:startEvent id="StartEvent_1" />
  </bpmn:process>
  <bpmndi:BPMNDiagram id="BPMNDiagram_1">
    <bpmndi:BPMNPlane id="BPMNPlane_1" bpmnElement="Process_1">
      <bpmndi:BPMNShape id="StartEvent_1_di" bpmnElement="StartEvent_1">
        <dc:Bounds x="152" y="102" width="36" height="36" />
      </bpmndi:BPMNShape>
    </bpmndi:BPMNPlane>
  </bpmndi:BPMNDiagram>
</bpmn:definitions>

XML;
  }

  public function validateSource(string $source): array {
    $errors = $this->checkSize($source);
    if ($errors) {
      return $errors;
    }
    $document = static::parseXml($source, $errors);
    if (!$document) {
      return $errors;
    }
    $root = $document->documentElement;
    if ($root->localName !== 'definitions' || $root->namespaceURI !== static::MODEL_NAMESPACE) {
      return ['A BPMN document has a bpmn:definitions root element.'];
    }
    return [];
  }

  public function extractLinks(string $source, array $bindings = []): array {
    $document = static::parseXml($source);
    if (!$document) {
      return [];
    }
    $xpath = $this->xpath($document);
    $links = [];
    foreach ($xpath->query('//*[@id][@divengine:entity]') as $element) {
      $uri = $element->getAttributeNS(static::DIVENGINE_NAMESPACE, 'entity');
      if (static::isEntityUri($uri)) {
        $links[] = [
          'element' => $element->getAttribute('id'),
          'entity' => $uri,
          'label' => $element->getAttributeNS(static::DIVENGINE_NAMESPACE, 'label'),
        ];
      }
    }
    return $links;
  }

  public function extractSearchText(string $source): string {
    $document = static::parseXml($source);
    if (!$document) {
      return '';
    }
    $xpath = $this->xpath($document);
    $parts = [];
    foreach ($xpath->query('//bpmn:*/@name') as $name) {
      $parts[] = $name->value;
    }
    foreach ($xpath->query('//bpmn:documentation | //bpmn:textAnnotation/bpmn:text') as $text) {
      $parts[] = $text->textContent;
    }
    return static::compactText($parts);
  }

  protected function xpath(\DOMDocument $document): \DOMXPath {
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('bpmn', static::MODEL_NAMESPACE);
    $xpath->registerNamespace('divengine', static::DIVENGINE_NAMESPACE);
    return $xpath;
  }

}

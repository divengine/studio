// Mounts the BPMN editor on the vendored bpmn-js modeler in jsdom and checks
// element linking, the exported SVG and the off-screen preview.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const moduleDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const dom = new JSDOM('<!doctype html><html><body><div id="canvas"></div></body></html>', {
  url: 'http://localhost/',
  pretendToBeVisual: true,
  runScripts: 'outside-only',
});
const w = dom.window;

// jsdom has no SVG geometry: give diagram-js a flat one.
const matrix = () => ({
  a: 1, b: 0, c: 0, d: 1, e: 0, f: 0,
  multiply: matrix, inverse: matrix, translate: matrix, scale: matrix,
});
Object.assign(w.SVGElement.prototype, {
  getBBox: () => ({ x: 0, y: 0, width: 100, height: 80 }),
  getScreenCTM: matrix,
  getComputedTextLength() { return (this.textContent || '').length * 7; },
  createSVGMatrix: matrix,
  createSVGTransformFromMatrix: (m) => ({ type: 1, matrix: m, setMatrix() {} }),
  createSVGTransform: () => ({ type: 1, matrix: matrix(), setMatrix() {}, setTranslate() {}, setScale() {} }),
  createSVGPoint: () => ({ x: 0, y: 0, matrixTransform() { return this; } }),
});
Object.defineProperty(w.SVGElement.prototype, 'transform', {
  get: () => ({ baseVal: { consolidate: () => null, clear() {}, appendItem() {}, initialize() {}, numberOfItems: 0 } }),
});
w.SVGMatrix = function SVGMatrix() {};
w.structuredClone = globalThis.structuredClone;
w.CSS = { escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`) };
w.HTMLCanvasElement.prototype.getContext = () => ({ font: '', measureText: (text) => ({ width: text.length * 7 }) });

const notations = {};
w.Drupal = {
  t: (text, args = {}) => text.replace(/[@%!]\w+/g, (token) => args[token] ?? token),
  formatPlural: (count, one, many) => (count === 1 ? one : many.replace('@count', count)),
  divengineModeler: { registerNotation: (id, implementation) => { notations[id] = implementation; } },
};
w.eval(fs.readFileSync(`${moduleDir}/js/vendor/bpmn-js/bpmn-modeler.production.min.js`, 'utf8'));
w.eval(fs.readFileSync(`${moduleDir}/js/bpmn-notation.js`, 'utf8'));

const source = `<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" id="D" targetNamespace="https://example.com">
  <bpmn:process id="P" isExecutable="false"><bpmn:startEvent id="Start" /></bpmn:process>
  <bpmndi:BPMNDiagram id="Diagram"><bpmndi:BPMNPlane id="Plane" bpmnElement="P">
    <bpmndi:BPMNShape id="Start_di" bpmnElement="Start"><dc:Bounds x="152" y="102" width="36" height="36" /></bpmndi:BPMNShape>
  </bpmndi:BPMNPlane></bpmndi:BPMNDiagram>
</bpmn:definitions>`;

async function mount(picks = []) {
  const state = { dirty: 0, status: [], opened: [] };
  const editor = await notations.bpmn.mount(w.document.getElementById('canvas'), {
    source,
    bindings: {},
    settings: {},
    markDirty: () => { state.dirty += 1; },
    setStatus: (message) => state.status.push(message),
    pickEntity: async () => picks.shift() ?? null,
    openEntity: (uri) => state.opened.push(uri),
  });
  return { editor, state };
}

const tick = () => new Promise((resolve) => { setTimeout(resolve, 20); });

test('the bpmn.io watermark stays visible', async () => {
  const { editor } = await mount();
  assert.ok(w.document.querySelector('.bjs-powered-by'));
  editor.destroy();
});

test('linking an element writes divengine attributes, a badge and an undo step', async () => {
  const { editor, state } = await mount([{ entity: 'entity:artifact/1', label: 'REQ-1' }]);
  const start = editor.engine.get('elementRegistry').get('Start');
  assert.ok('divengine-link' in editor.engine.get('contextPad').getEntries(start));

  editor.linkSelection();
  assert.equal(state.status.at(-1), 'Select an element first.');

  editor.engine.get('selection').select(start);
  editor.linkSelection();
  await tick();
  const xml = await editor.getSource();
  assert.match(xml, /divengine:entity="entity:artifact\/1"/);
  assert.match(xml, /divengine:label="REQ-1"/);
  assert.match(xml, /xmlns:divengine="https:\/\/divengine.com\/schema\/modeler\/1.0"/);
  assert.equal(state.dirty, 1);

  const badge = w.document.querySelector('.divengine-link-badge');
  badge.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  assert.deepEqual(state.opened, ['entity:artifact/1']);

  editor.undo();
  assert.doesNotMatch(await editor.getSource(), /divengine:entity/);
  assert.equal(w.document.querySelector('.divengine-link-badge'), null);
  editor.destroy();
});

test('the exported SVG has no DOCTYPE and marks linked elements', async () => {
  const { editor } = await mount([{ entity: 'entity:node/5', label: 'Policy' }]);
  editor.engine.get('selection').select(editor.engine.get('elementRegistry').get('Start'));
  editor.linkSelection();
  await tick();
  const svg = await editor.exportSvg();
  assert.match(svg, /^<svg /);
  assert.doesNotMatch(svg, /DOCTYPE|<\?xml/);
  assert.match(svg, /data-divengine-element="Start"/);

  const preview = await notations.bpmn.renderPreview({ source: await editor.getSource() });
  assert.match(preview, /data-divengine-element="Start"/);
  editor.destroy();
});

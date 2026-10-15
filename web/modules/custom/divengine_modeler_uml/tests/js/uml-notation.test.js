// Renders PlantUML sources with the vendored engine and checks the semantic
// keys, the exported SVG and the source checks of uml-notation.js.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const moduleDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'http://localhost/' });
for (const name of ['document', 'DOMParser', 'XMLSerializer', 'Node', 'Element', 'SVGElement', 'HTMLElement']) {
  globalThis[name] = dom.window[name];
}
globalThis.window = globalThis;
globalThis.self = globalThis;
// jsdom has no layout: give the engine rough text metrics.
dom.window.SVGElement.prototype.getBBox = function getBBox() {
  return { x: 0, y: 0, width: (this.textContent || '').length * 7, height: 14 };
};
dom.window.HTMLCanvasElement.prototype.getContext = () => ({
  font: '',
  measureText: (text) => ({ width: text.length * 7, actualBoundingBoxAscent: 10, actualBoundingBoxDescent: 3 }),
});

vm.runInThisContext(fs.readFileSync(`${moduleDir}/js/vendor/plantuml/viz-global.js`, 'utf8'));
const notations = {};
globalThis.Drupal = {
  t: (text, args = {}) => text.replace(/[@%!]\w+/g, (token) => args[token] ?? token),
  divengineModeler: { registerNotation: (id, implementation) => { notations[id] = implementation; } },
};
new vm.Script(fs.readFileSync(`${moduleDir}/js/uml-notation.js`, 'utf8'), {
  importModuleDynamically: vm.constants.USE_MAIN_CONTEXT_DEFAULT_LOADER,
}).runInThisContext();

const settings = { engineUrl: `file://${moduleDir}/js/vendor/plantuml/plantuml.js`, maxSourceBytes: 1000000 };
const { semanticElements } = globalThis.Drupal.divengineModelerUml;

// The engine logs its progress; keep the test output readable.
console.log = () => {};

async function keysOf(source, bindings = {}) {
  const svg = await notations.uml.renderPreview({ source, bindings, settings });
  const root = new DOMParser().parseFromString(svg, 'image/svg+xml').documentElement;
  const { unique, ambiguous } = semanticElements(root);
  return { svg, keys: [...unique.keys()].sort(), ambiguous: [...ambiguous].sort() };
}

test('class diagrams key entities by qualified name and relations by both ends', async () => {
  const { keys, ambiguous } = await keysOf('@startuml\npackage billing {\n  class Invoice\n  class Customer\n}\nCustomer --> Invoice : owns\nCustomer --> Invoice\ninterface Payable\nInvoice ..|> Payable\n@enduml');
  assert.deepEqual(keys, ['entity:Payable', 'entity:billing', 'entity:billing.Customer', 'entity:billing.Invoice', 'link:billing.Invoice->Payable:extension']);
  // Two identical relations cannot be told apart: never guessed.
  assert.deepEqual(ambiguous, ['link:billing.Customer->billing.Invoice:dependency']);
});

test('use case and sequence diagrams have keys', async () => {
  assert.deepEqual((await keysOf('@startuml\nactor User\nusecase "Pay invoice" as UC1\nUser --> UC1\n@enduml')).keys, ['entity:UC1', 'entity:User', 'link:User->UC1:dependency']);
  assert.deepEqual((await keysOf('@startuml\nactor User\nparticipant "Billing API" as API\nUser -> API : pay()\n@enduml')).keys, ['participant:Billing API', 'participant:User']);
});

test('exported SVG is well-formed and marks only linked elements', async () => {
  const { svg } = await keysOf('@startuml\nclass A\nclass B\nA --> B\n@enduml', { 'entity:A': { entity: 'entity:node/1' }, 'entity:Gone': { entity: 'entity:node/2' } });
  assert.equal(svg.match(/xmlns="http:\/\/www\.w3\.org\/2000\/svg"/g).length, 1);
  assert.deepEqual([...svg.matchAll(/data-divengine-element="([^"]+)"/g)].map((match) => match[1]), ['entity:A']);
  assert.ok(!svg.includes('data-divengine-key'));
});

test('engine errors are reported, not exported', async () => {
  await assert.rejects(notations.uml.renderPreview({ source: '@startuml\nclass A {\n@enduml', bindings: {}, settings }), /Syntax Error/);
});

test('sources that would fetch anything are refused before rendering', async () => {
  for (const source of ['!include https://x/y.puml', '!includeurl x', '!import lib', '!theme x from https://y', '%load_json("x")', 'class A <img:https://x/y.png>', 'class A [[javascript:alert(1)]]']) {
    await assert.rejects(notations.uml.renderPreview({ source: `@startuml\n${source}\n@enduml`, bindings: {}, settings }), /not allowed|only/);
  }
});

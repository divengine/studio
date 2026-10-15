// Runs the modeler shell with a fake notation and a fake server.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const moduleDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function setup(responses) {
  const dom = new JSDOM('<!doctype html><html><body><div data-divengine-modeler="7"></div></body></html>', {
    url: 'http://localhost/diagram/7/modeler',
    runScripts: 'outside-only',
  });
  const w = dom.window;
  const requests = [];
  w.fetch = async (url, options = {}) => {
    requests.push({ url: String(url), options });
    const [status, body] = responses.shift() ?? [200, {}];
    return {
      ok: status >= 200 && status < 300,
      status,
      text: async () => body,
      json: async () => body,
    };
  };
  // jsdom has no <dialog> behaviour.
  w.HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
  w.HTMLDialogElement.prototype.close = function close() { this.open = false; };
  w.confirm = () => true;
  w.eval(`window.once = (id, selector, context) => Array.from((context || document).querySelectorAll(selector));`);
  w.Drupal = {
    t: (text, args = {}) => text.replace(/[@%!][\w-]+/g, (token) => args[token] ?? token),
    behaviors: {},
  };
  w.drupalSettings = {};
  w.eval(fs.readFileSync(`${moduleDir}/js/modeler.js`, 'utf8'));
  return { w, requests };
}

const settings = {
  diagramId: 7,
  revisionId: 10,
  label: 'Order flow',
  notation: 'fake',
  notationLabel: 'Fake',
  fileExtension: 'txt',
  source: 'A',
  bindings: {},
  state: 'draft',
  urls: { save: '/diagram/7/model', canonical: '/diagram/7', token: '/session/token', search: '/s', resolve: '/r', go: '/go' },
  settings: {},
};

function fakeNotation(state) {
  return {
    async mount(container, context) {
      state.context = context;
      return {
        getSource: async () => state.source,
        exportSvg: async () => {
          if (state.exportFails) {
            throw new Error('no svg');
          }
          return '<svg xmlns="http://www.w3.org/2000/svg"/>';
        },
        getBindings: () => ({ 'entity:A': { entity: 'entity:node/1', label: 'One' } }),
        undo() {}, redo() {}, fit() {}, destroy() {},
        linkSelection() { state.linked = true; },
      };
    },
  };
}

async function start(responses, state) {
  const { w, requests } = setup(responses);
  w.Drupal.divengineModeler.registerNotation('fake', fakeNotation(state));
  w.Drupal.behaviors.divengineModeler.attach(w.document, { divengineModeler: { editors: { 7: settings } } });
  await new Promise((resolve) => { setTimeout(resolve, 10); });
  const button = (label) => Array.from(w.document.querySelectorAll('button')).find((b) => b.textContent === label);
  const status = () => w.document.querySelector('.divengine-modeler__status').textContent;
  return { w, requests, button, status };
}

const flush = () => new Promise((resolve) => { setTimeout(resolve, 20); });

test('save sends source, svg, bindings and the base revision with the CSRF token', async () => {
  const state = { source: 'B' };
  const { w, requests, button, status } = await start([
    [200, 'TOKEN'],
    [200, { status: 'saved', revisionId: 11, previewFresh: true, message: 'Saved as revision 11.' }],
  ], state);
  state.context.markDirty();
  w.document.querySelector('.divengine-modeler__log').value = 'Renamed';
  button('Save').click();
  await flush();
  const save = requests[1];
  assert.equal(save.url, '/diagram/7/model');
  assert.equal(save.options.headers['X-CSRF-Token'], 'TOKEN');
  assert.deepEqual(JSON.parse(save.options.body), {
    source: 'B',
    svg: '<svg xmlns="http://www.w3.org/2000/svg"/>',
    bindings: { 'entity:A': { entity: 'entity:node/1', label: 'One' } },
    log: 'Renamed',
    baseRevisionId: 10,
    force: false,
  });
  assert.equal(status(), 'Saved as revision 11.');
  assert.ok(!w.document.querySelector('[data-divengine-modeler]').classList.contains('is-dirty'));
});

test('a failed export still saves the source', async () => {
  const state = { source: 'B', exportFails: true };
  const { requests, button, status } = await start([
    [200, 'TOKEN'],
    [200, { status: 'saved', revisionId: 11, previewFresh: false, message: 'Saved.' }],
  ], state);
  button('Save').click();
  await flush();
  assert.equal(JSON.parse(requests[1].options.body).svg, null);
  assert.match(status(), /preview could not be exported/);
});

test('a conflict asks and saves again with force; errors are shown', async () => {
  const state = { source: 'B' };
  const { requests, button, status } = await start([
    [200, 'TOKEN'],
    [409, { errors: ['Someone saved.'], conflict: true, revisionId: 12 }],
    [422, { errors: ['The preview contains a forbidden <script> element.'] }],
  ], state);
  button('Save').click();
  await flush();
  assert.equal(JSON.parse(requests[2].options.body).force, true);
  assert.equal(status(), 'The preview contains a forbidden <script> element.');
});

test('the picker searches, picks and offers removal', async () => {
  const state = { source: 'B' };
  const { w, requests } = await start([
    [200, { results: [{ entity: 'entity:artifact/3', label: 'REQ-3', type: 'artifact', url: '/artifact/3' }] }],
  ], state);
  const choice = state.context.pickEntity({ entity: 'entity:node/1', label: 'One' });
  const dialog = w.document.querySelector('dialog.divengine-picker');
  assert.ok(dialog.open);
  assert.ok(Array.from(dialog.querySelectorAll('button')).some((b) => b.textContent === 'Remove link'));
  const search = dialog.querySelector('.divengine-picker__search');
  search.value = 'REQ';
  search.dispatchEvent(new w.Event('input'));
  await new Promise((resolve) => { setTimeout(resolve, 300); });
  assert.equal(requests[0].url, '/s?q=REQ');
  dialog.querySelector('.divengine-picker__result').click();
  // The choice comes from the jsdom realm.
  assert.deepEqual({ ...(await choice) }, { entity: 'entity:artifact/3', label: 'REQ-3' });
  assert.equal(w.document.querySelector('dialog.divengine-picker'), null);
});

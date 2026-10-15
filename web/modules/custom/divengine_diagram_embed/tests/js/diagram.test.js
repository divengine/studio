// Runs the diagram plugin inside the CKEditor 5 build shipped by Drupal core.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const moduleDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const vendor = path.resolve(moduleDir, '../../../core/assets/vendor/ckeditor5');

const diagrams = {
  'uuid-1': { uuid: 'uuid-1', label: 'Order to cash', type: 'BPMN', url: '/diagram/1', previewUrl: '/diagram/1/preview/aa.svg?fit=1' },
  'uuid-2': { uuid: 'uuid-2', label: 'Billing classes', type: 'UML', url: '/diagram/2', previewUrl: null },
};

async function setup(t, data = '') {
  const dom = new JSDOM('<!doctype html><html><body><div id="editor"></div></body></html>', {
    url: 'http://localhost/node/1/edit',
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const w = dom.window;
  const requests = [];
  w.fetch = async (url) => {
    requests.push(String(url));
    const query = new URL(String(url), 'http://localhost').searchParams;
    let status = 200;
    let body;
    if (String(url).startsWith('/lookup')) {
      const uuid = query.get('uuid');
      if (uuid === 'secret') {
        [status, body] = [403, { state: 'restricted' }];
      }
      else if (diagrams[uuid]) {
        body = { result: diagrams[uuid] };
      }
      else {
        [status, body] = [404, { state: 'missing' }];
      }
    }
    else {
      const text = (query.get('q') || '').toLowerCase();
      body = { results: Object.values(diagrams).filter((d) => d.label.toLowerCase().includes(text)) };
    }
    return { status, ok: status === 200, json: async () => body };
  };
  // CKEditor scrolls the selection into view on focus.
  w.scrollTo = () => {};
  w.ResizeObserver = class {
    observe() {}

    unobserve() {}

    disconnect() {}
  };
  // jsdom has no <dialog> behaviour.
  w.HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
  w.HTMLDialogElement.prototype.close = function close() { this.open = false; };
  w.Drupal = {
    t: (text, args = {}) => text.replace(/[@%!][\w-]+/g, (token) => args[token] ?? token),
  };
  for (const file of ['ckeditor5-dll/ckeditor5-dll.js', 'editor-classic/editor-classic.js', 'essentials/essentials.js']) {
    w.eval(fs.readFileSync(`${vendor}/${file}`, 'utf8'));
  }
  w.eval(fs.readFileSync(`${moduleDir}/js/ckeditor5.diagram.js`, 'utf8'));
  const { CKEditor5 } = w;
  const editor = await CKEditor5.editorClassic.ClassicEditor.create(w.document.getElementById('editor'), {
    licenseKey: 'GPL',
    plugins: [CKEditor5.essentials.Essentials, CKEditor5.paragraph.Paragraph, CKEditor5.divengineDiagram.DivengineDiagram],
    toolbar: ['divengineDiagram'],
    divengineDiagram: { searchUrl: '/search', lookupUrl: '/lookup' },
  });
  editor.setData(data);
  t.after(async () => {
    await editor.destroy();
    w.close();
  });
  return { w, editor, requests };
}

const settle = () => new Promise((resolve) => { setTimeout(resolve, 0); });

test('<drupal-diagram> survives a round trip and drops unknown alignments', async (t) => {
  const { editor } = await setup(t, 
    '<p>Before</p>'
    + '<drupal-diagram data-diagram-uuid="uuid-1" data-align="right"></drupal-diagram>'
    + '<drupal-diagram data-diagram-uuid="uuid-2" data-align="sideways"></drupal-diagram>'
    + '<drupal-diagram></drupal-diagram>',
  );
  assert.equal(
    editor.getData(),
    '<p>Before</p>'
    + '<drupal-diagram data-diagram-uuid="uuid-1" data-align="right"></drupal-diagram>'
    + '<drupal-diagram data-diagram-uuid="uuid-2"></drupal-diagram>',
  );
});

test('the widget shows the preview, or a notice, looking each diagram up once', async (t) => {
  const { w, editor, requests } = await setup(t, 
    '<drupal-diagram data-diagram-uuid="uuid-1"></drupal-diagram>'
    + '<drupal-diagram data-diagram-uuid="uuid-1"></drupal-diagram>'
    + '<drupal-diagram data-diagram-uuid="uuid-2"></drupal-diagram>'
    + '<drupal-diagram data-diagram-uuid="secret"></drupal-diagram>'
    + '<drupal-diagram data-diagram-uuid="gone"></drupal-diagram>',
  );
  await settle();
  const widgets = [...w.document.querySelectorAll('.ck-content .divengine-diagram-widget')];
  assert.equal(widgets.length, 5);
  assert.ok(widgets[0].classList.contains('ck-widget'));
  const image = widgets[0].querySelector('img');
  assert.equal(image.getAttribute('src'), '/diagram/1/preview/aa.svg?fit=1');
  assert.equal(image.getAttribute('alt'), 'Diagram: Order to cash');
  assert.deepEqual(
    widgets.map((widget) => widget.querySelector('.divengine-diagram-widget__caption').textContent),
    ['Order to cash', 'Order to cash', 'Billing classes (no preview yet)', 'Restricted diagram', 'Missing diagram'],
  );
  assert.equal(widgets[2].querySelector('img'), null);
  assert.equal(requests.filter((url) => url === '/lookup?uuid=uuid-1').length, 1);
  assert.ok(!editor.getData().includes('divengine-diagram-widget'));
});

test('the toolbar button searches diagrams and inserts the chosen one', async (t) => {
  const { w, editor, requests } = await setup(t, '<p>Text</p>');
  editor.model.change((writer) => {
    writer.setSelection(editor.model.document.getRoot().getChild(0), 'end');
  });
  const button = editor.ui.view.toolbar.items.find((item) => item.label === 'Diagram');
  button.fire('execute');
  await settle();
  const dialog = w.document.querySelector('dialog.divengine-diagram-picker');
  assert.ok(dialog.open);
  assert.equal(requests.at(-1), '/search?q=');
  assert.equal(dialog.querySelectorAll('.divengine-diagram-picker__result').length, 2);

  const input = dialog.querySelector('input');
  input.value = 'billing';
  input.dispatchEvent(new w.Event('input'));
  await new Promise((resolve) => { setTimeout(resolve, 300); });
  const results = [...dialog.querySelectorAll('.divengine-diagram-picker__result')];
  assert.equal(requests.at(-1), '/search?q=billing');
  assert.equal(results.length, 1);
  assert.equal(results[0].textContent, 'Billing classesUML');

  results[0].click();
  assert.equal(w.document.querySelector('dialog'), null);
  assert.equal(editor.getData(), '<p>Text</p><drupal-diagram data-diagram-uuid="uuid-2"></drupal-diagram>');
});

test('alignment toggles and replacing a diagram keeps it', async (t) => {
  const { w, editor } = await setup(t, '<drupal-diagram data-diagram-uuid="uuid-1"></drupal-diagram>');
  editor.model.change((writer) => {
    writer.setSelection(editor.model.document.getRoot().getChild(0), 'on');
  });
  editor.execute('alignDivengineDiagram', { value: 'center' });
  assert.equal(editor.getData(), '<drupal-diagram data-diagram-uuid="uuid-1" data-align="center"></drupal-diagram>');
  assert.equal(editor.commands.get('insertDivengineDiagram').value, 'uuid-1');

  editor.plugins.get('DivengineDiagram').openPicker();
  await settle();
  w.document.querySelector('.divengine-diagram-picker__result[data-uuid="uuid-2"]').click();
  assert.equal(editor.getData(), '<drupal-diagram data-diagram-uuid="uuid-2" data-align="center"></drupal-diagram>');

  editor.execute('alignDivengineDiagram', { value: 'center' });
  assert.equal(editor.getData(), '<drupal-diagram data-diagram-uuid="uuid-2"></drupal-diagram>');
});

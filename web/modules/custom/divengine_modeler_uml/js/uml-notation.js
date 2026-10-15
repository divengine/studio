/**
 * @file
 * UML notation for the Divengine modeler: PlantUML source in CodeMirror,
 * rendered in the browser by the vendored @plantuml/core engine.
 *
 * The SVG is regenerated on every change, so element links are bindings from
 * a semantic key to {entity, label}, applied after each render to the one
 * element with that key. Bindings that match no element or several are listed
 * as unresolved for the user to re-bind; they are never guessed (ADR-0005).
 */
((Drupal) => {
  const RENDER_DELAY = 400;
  const MAX_SVG_BYTES = 5000000;

  // Mirrors UmlNotation::FORBIDDEN: rendering must never fetch anything.
  const FORBIDDEN = [
    [/^\s*!(?:include\w*|import)\b/im, 'Includes and imports are not allowed; diagrams must be self-contained.'],
    [/^\s*!theme\s+\S+\s+from\b/im, 'Themes can only come from the bundled theme library.'],
    [/%load_json\s*\(/i, 'Loading JSON from files or addresses is not allowed.'],
    [/<img\s*:\s*(?:[a-z][a-z0-9+.-]*:|\/)/i, 'Images can only be embedded, not loaded from an address.'],
    [/\[\[\s*(?:javascript|vbscript|data)\s*:/i, 'Links to script or data addresses are not allowed.'],
  ];

  let enginePromise = null;

  function loadEngine(url) {
    if (!enginePromise) {
      enginePromise = import(url).then((engine) => {
        if (typeof engine.renderToString !== 'function') {
          throw new Error('The PlantUML engine has no renderToString().');
        }
        return engine;
      }).catch((error) => {
        enginePromise = null;
        throw error;
      });
    }
    return enginePromise;
  }

  function checkSource(source, maxBytes) {
    if (new TextEncoder().encode(source).length > maxBytes) {
      return Drupal.t('The source exceeds the @bytes byte limit.', { '@bytes': maxBytes });
    }
    const found = FORBIDDEN.find(([pattern]) => pattern.test(source));
    return found ? Drupal.t(found[1]) : null;
  }

  /**
   * Repairs what the engine emits so the SVG is well-formed XML.
   *
   * The engine repeats the SVG namespace declaration on the root element.
   */
  function normalizeSvg(svg) {
    return String(svg).replace(/<svg\b[^>]*>/, (tag) => {
      let seen = false;
      return tag.replace(/\s+xmlns="[^"]*"/g, (attribute) => {
        if (seen) {
          return '';
        }
        seen = true;
        return attribute;
      });
    });
  }

  /**
   * Reads the engine's error diagram: "[From textarea (line N) ]" + message.
   */
  function renderError(root) {
    const texts = Array.from(root.querySelectorAll('text'));
    const origin = texts.map((text) => text.textContent.match(/\[From [^\]]*\(line (\d+)\)\s*\]/)).find(Boolean);
    if (!origin) {
      return null;
    }
    // The engine writes the diagnosis in red.
    const message = texts
      .filter((text) => /^#(?:f00|ff0000)$/i.test(text.getAttribute('fill') || ''))
      .map((text) => text.textContent.trim())
      .join(' ');
    return { line: Number.parseInt(origin[1], 10), message: message || Drupal.t('PlantUML could not read the source.') };
  }

  /**
   * Finds the elements of a rendering by semantic key.
   *
   * @return {{unique: Map<string, Element>, ambiguous: Set<string>}}
   */
  function semanticElements(root) {
    const names = new Map();
    const candidates = [];
    root.querySelectorAll('g.entity[data-qualified-name], g.cluster[data-qualified-name]').forEach((group) => {
      const name = group.getAttribute('data-qualified-name');
      names.set(group.getAttribute('id'), name);
      candidates.push([`entity:${name}`, group]);
    });
    root.querySelectorAll('g.link[data-entity-1][data-entity-2]').forEach((group) => {
      const from = names.get(group.getAttribute('data-entity-1'));
      const to = names.get(group.getAttribute('data-entity-2'));
      if (from && to) {
        candidates.push([`link:${from}->${to}:${group.getAttribute('data-link-type') || 'link'}`, group]);
      }
    });
    // Sequence diagrams: each lifeline is a group titled with the participant.
    root.querySelectorAll('g:not([class]) > title:first-child').forEach((title) => {
      const name = title.textContent.trim();
      if (name) {
        candidates.push([`participant:${name}`, title.parentNode]);
      }
    });
    const counts = new Map();
    candidates.forEach(([key]) => counts.set(key, (counts.get(key) || 0) + 1));
    const unique = new Map();
    const ambiguous = new Set();
    candidates.forEach(([key, node]) => {
      if (counts.get(key) === 1) {
        unique.set(key, node);
      } else {
        ambiguous.add(key);
      }
    });
    return { unique, ambiguous };
  }

  /**
   * Renders a source; resolves to {svg, root, error}.
   */
  function render(settings, source) {
    const problem = checkSource(source, settings.maxSourceBytes || 1000000);
    if (problem) {
      return Promise.reject(new Error(problem));
    }
    return loadEngine(settings.engineUrl).then((engine) => new Promise((resolve, reject) => {
      engine.renderToString(
        source.split(/\r\n|\r|\n/),
        (raw) => {
          const svg = normalizeSvg(raw);
          if (svg.length > MAX_SVG_BYTES) {
            reject(new Error(Drupal.t('The rendered diagram exceeds the 5 MB limit.')));
            return;
          }
          const document = new DOMParser().parseFromString(svg, 'image/svg+xml');
          const root = document.documentElement;
          if (root.localName !== 'svg' || document.querySelector('parsererror')) {
            reject(new Error(Drupal.t('PlantUML returned an unreadable SVG.')));
            return;
          }
          resolve({ svg, root, error: renderError(root) });
        },
        (message) => reject(new Error(String(message || Drupal.t('PlantUML could not render this source.')))),
      );
    }));
  }

  /**
   * Serializes a rendering for storage: only linked elements are marked.
   */
  function exportRendering(root, bindings) {
    const copy = root.cloneNode(true);
    const { unique } = semanticElements(copy);
    Object.keys(bindings).forEach((key) => {
      unique.get(key)?.setAttribute('data-divengine-element', key);
    });
    copy.querySelectorAll('.divengine-uml-selected, .divengine-uml-linked').forEach((node) => {
      node.classList.remove('divengine-uml-selected', 'divengine-uml-linked');
      if (!node.getAttribute('class')) {
        node.removeAttribute('class');
      }
    });
    copy.querySelectorAll('[data-divengine-key]').forEach((node) => node.removeAttribute('data-divengine-key'));
    return new XMLSerializer().serializeToString(copy);
  }

  /**
   * A small PlantUML highlighter for CodeMirror's StreamLanguage.
   */
  const plantumlMode = {
    name: 'plantuml',
    startState: () => ({ comment: false }),
    token(stream, state) {
      if (state.comment) {
        if (stream.match(/.*?'\//)) {
          state.comment = false;
        } else {
          stream.skipToEnd();
        }
        return 'comment';
      }
      if (stream.match(/\/'/)) {
        state.comment = true;
        return 'comment';
      }
      if (stream.sol() && stream.match(/\s*'.*/)) {
        return 'comment';
      }
      if (stream.match(/@(start|end)\w+/)) {
        return 'meta';
      }
      if (stream.match(/!\w+/) || stream.match(/skinparam\b/)) {
        return 'meta';
      }
      if (stream.match(/"(?:[^"\\]|\\.)*"?/)) {
        return 'string';
      }
      if (stream.match(/\[\[[^\]]*\]\]/)) {
        return 'link';
      }
      if (stream.match(/(?:abstract|actor|agent|artifact|as|boundary|card|class|cloud|collections|component|control|database|end|entity|enum|exception|extends|file|folder|frame|group|implements|interface|loop|alt|else|opt|par|package|participant|queue|rectangle|node|note|of|on|over|left|right|top|bottom|state|storage|usecase|activate|deactivate|return|if|then|endif|while|endwhile|start|stop|fork|again|title|legend|header|footer)\b/)) {
        return 'keyword';
      }
      if (stream.match(/[-.=<>|*o#x+^]{2,}/)) {
        return 'operator';
      }
      stream.next();
      return null;
    },
  };

  Drupal.divengineModeler.registerNotation('uml', {
    async mount(container, context) {
      const CM = window.DivengineCodeMirror;
      if (!CM) {
        throw new Error('CodeMirror is not loaded.');
      }
      const { element } = Drupal.divengineModeler;
      const settings = context.settings;
      let bindings = { ...(context.bindings || {}) };
      let rendering = null;
      let renderedSource = null;
      let selectedKey = null;
      let timer = null;
      let renderToken = 0;

      const messages = element('div', { class: 'divengine-uml__messages', role: 'status' });
      const canvas = element('div', { class: 'divengine-uml__svg' });
      const linkList = element('ul', { class: 'divengine-uml__link-list' });
      const code = element('div', { class: 'divengine-uml__code' });
      container.append(element('div', { class: 'divengine-uml' }, [
        code,
        element('div', { class: 'divengine-uml__render' }, [messages, canvas]),
        element('aside', { class: 'divengine-uml__links', 'aria-label': Drupal.t('Linked elements') }, [
          element('h3', { text: Drupal.t('Linked elements') }),
          element('p', { class: 'description', text: Drupal.t('Click an element to select it, double-click it to link it to a record.') }),
          linkList,
        ]),
      ]));

      const view = new CM.EditorView({
        parent: code,
        state: CM.EditorState.create({
          doc: context.source,
          extensions: [
            CM.basicSetup,
            CM.keymap.of([CM.indentWithTab]),
            CM.StreamLanguage.define(plantumlMode),
            CM.EditorView.lineWrapping,
            CM.EditorView.updateListener.of((update) => {
              if (update.docChanged) {
                context.markDirty();
                clearTimeout(timer);
                timer = setTimeout(refresh, RENDER_DELAY);
              }
            }),
          ],
        }),
      });

      const source = () => view.state.doc.toString();

      const goToLine = (line) => {
        if (line >= 1 && line <= view.state.doc.lines) {
          const { from } = view.state.doc.line(line);
          view.dispatch({ selection: { anchor: from }, scrollIntoView: true });
        }
      };

      const showMessage = (text, kind = 'error', line = null) => {
        messages.replaceChildren();
        if (!text) {
          return;
        }
        messages.dataset.kind = kind;
        messages.append(document.createTextNode(text));
        if (line) {
          messages.append(' ', element('button', {
            type: 'button',
            class: 'link',
            text: Drupal.t('Go to line @line', { '@line': line }),
            onclick: () => {
              goToLine(line);
              view.focus();
            },
          }));
        }
      };

      const link = async (key) => {
        const choice = await context.pickEntity(bindings[key] ? { ...bindings[key] } : null);
        if (!choice) {
          return;
        }
        if (choice.remove) {
          delete bindings[key];
        } else {
          bindings[key] = { entity: choice.entity, label: choice.label || '' };
        }
        bindings = { ...bindings };
        context.markDirty();
        decorate();
      };

      // Applies selection and bindings to the current rendering and lists the
      // bindings with their status.
      const decorate = () => {
        const found = rendering ? semanticElements(rendering.root) : { unique: new Map(), ambiguous: new Set() };
        if (rendering) {
          found.unique.forEach((node, key) => {
            node.setAttribute('data-divengine-key', key);
            node.classList.toggle('divengine-uml-linked', Boolean(bindings[key]));
            node.classList.toggle('divengine-uml-selected', key === selectedKey);
          });
        }
        linkList.replaceChildren(...Object.keys(bindings).sort().map((key) => {
          const binding = bindings[key];
          let status = Drupal.t('linked');
          if (rendering && !found.unique.has(key)) {
            status = found.ambiguous.has(key) ? Drupal.t('ambiguous: several elements have this key') : Drupal.t('unresolved: no element has this key');
          }
          const resolved = !rendering || found.unique.has(key);
          return element('li', { class: resolved ? 'is-resolved' : 'is-unresolved' }, [
            element('code', { text: key }),
            element('span', { text: ` → ${binding.label || binding.entity} ` }),
            element('em', { text: `(${status})` }),
            element('div', { class: 'divengine-uml__link-actions' }, [
              element('button', { type: 'button', class: 'link', text: Drupal.t('Open'), onclick: () => context.openEntity(binding.entity) }),
              element('button', { type: 'button', class: 'link', text: Drupal.t('Change'), onclick: () => link(key) }),
              element('button', {
                type: 'button',
                class: 'link',
                text: Drupal.t('Remove'),
                onclick: () => {
                  delete bindings[key];
                  bindings = { ...bindings };
                  context.markDirty();
                  decorate();
                },
              }),
            ]),
          ]);
        }));
        if (!linkList.children.length) {
          linkList.append(element('li', { class: 'description', text: Drupal.t('No element is linked yet.') }));
        }
      };

      const keyAt = (target) => target?.closest?.('[data-divengine-key]')?.getAttribute('data-divengine-key') || null;

      canvas.addEventListener('click', (event) => {
        const key = keyAt(event.target);
        selectedKey = key;
        decorate();
        const node = event.target.closest?.('[data-source-line]');
        if (node) {
          // data-source-line is zero-based.
          goToLine(Number.parseInt(node.getAttribute('data-source-line'), 10) + 1);
        }
        if (key && bindings[key] && (event.ctrlKey || event.metaKey)) {
          context.openEntity(bindings[key].entity);
        }
      });
      canvas.addEventListener('dblclick', (event) => {
        const key = keyAt(event.target);
        if (key) {
          selectedKey = key;
          link(key);
        }
      });

      async function refresh() {
        const text = source();
        const token = ++renderToken;
        try {
          const result = await render(settings, text);
          if (token !== renderToken) {
            return;
          }
          canvas.replaceChildren(document.importNode(result.root, true));
          rendering = { ...result, root: canvas.firstElementChild };
          renderedSource = text;
          if (result.error) {
            showMessage(result.error.message, 'error', result.error.line);
          } else {
            showMessage('');
          }
          decorate();
        } catch (error) {
          if (token === renderToken) {
            rendering = null;
            renderedSource = null;
            showMessage(error?.message || String(error));
            decorate();
          }
        }
      }

      context.setStatus(Drupal.t('Loading the PlantUML engine…'));
      await refresh();
      context.setStatus('');

      return {
        getSource: source,
        async exportSvg() {
          if (renderedSource !== source()) {
            clearTimeout(timer);
            await refresh();
          }
          if (!rendering || rendering.error) {
            throw new Error(Drupal.t('The diagram has errors; only the source is saved.'));
          }
          return exportRendering(rendering.root, bindings);
        },
        getBindings: () => ({ ...bindings }),
        undo: () => CM.undo(view),
        redo: () => CM.redo(view),
        fit: () => canvas.classList.toggle('is-actual-size'),
        linkSelection() {
          if (selectedKey) {
            link(selectedKey);
          } else {
            context.setStatus(Drupal.t('Click an element of the diagram first.'), 'info');
          }
        },
        destroy: () => view.destroy(),
      };
    },

    async renderPreview({ source, bindings, settings }) {
      const result = await render(settings, source);
      if (result.error) {
        throw new Error(result.error.message);
      }
      return exportRendering(result.root, bindings || {});
    },
  });

  // Exposed for tests and for other text notations.
  Drupal.divengineModelerUml = { normalizeSvg, semanticElements, exportRendering, checkSource };
})(Drupal);

/**
 * @file
 * Shared modeler shell: toolbar, save client, dirty guard, entity picker and
 * off-screen preview rendering.
 *
 * Notation modules register an implementation with
 * Drupal.divengineModeler.registerNotation(id, {mount, renderPreview}).
 *
 * mount(container, context) resolves to an editor exposing getSource() and
 * exportSvg() (both may return promises), getBindings(), undo(), redo(),
 * fit(), destroy() and, when the notation links elements, linkSelection(). context carries the source, the
 * bindings, the notation settings and the shell callbacks markDirty(),
 * setStatus(), pickEntity() and openEntity().
 *
 * renderPreview({source, bindings, settings}) resolves to an SVG string.
 */
((Drupal, drupalSettings, once) => {
  const notations = {};
  let tokenPromise = null;

  function csrfToken(url) {
    if (!tokenPromise) {
      tokenPromise = fetch(url, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Token request failed (${response.status}).`);
          }
          return response.text();
        })
        .catch((error) => {
          tokenPromise = null;
          throw error;
        });
    }
    return tokenPromise;
  }

  async function postJson(url, tokenUrl, payload) {
    const token = await csrfToken(tokenUrl);
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': token,
      },
      body: JSON.stringify(payload),
    });
    let data = {};
    try {
      data = await response.json();
    } catch (e) {
      data = {};
    }
    return { ok: response.ok, status: response.status, data };
  }

  async function getJson(url, params) {
    const query = new URLSearchParams(params).toString();
    const response = await fetch(`${url}?${query}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    let data = {};
    try {
      data = await response.json();
    } catch (e) {
      data = {};
    }
    return { ok: response.ok, status: response.status, data };
  }

  function element(tag, attributes = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => {
      if (name === 'text') {
        node.textContent = value;
      } else if (name.startsWith('on') && typeof value === 'function') {
        node.addEventListener(name.slice(2), value);
      } else if (value !== null && value !== undefined && value !== false) {
        node.setAttribute(name, value === true ? '' : value);
      }
    });
    children.forEach((child) => child && node.append(child));
    return node;
  }

  function download(name, type, content) {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const link = element('a', { href: url, download: name });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function fileName(label, extension) {
    const base = String(label || 'diagram').replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
    return `${base || 'diagram'}.${extension}`;
  }

  /**
   * Lets the user pick the record an element links to.
   */
  class EntityPicker {
    constructor(urls) {
      this.urls = urls;
    }

    open(current) {
      return new Promise((resolve) => {
        let settled = false;
        const finish = (value) => {
          if (!settled) {
            settled = true;
            dialog.close();
            dialog.remove();
            resolve(value);
          }
        };
        const results = element('ul', { class: 'divengine-picker__results', role: 'listbox' });
        const message = element('p', { class: 'divengine-picker__message', role: 'status' });
        const search = element('input', {
          type: 'search',
          class: 'divengine-picker__search form-text',
          placeholder: Drupal.t('Search by title or name'),
          'aria-label': Drupal.t('Search records'),
        });
        const paste = element('input', {
          type: 'text',
          class: 'divengine-picker__paste form-text',
          placeholder: Drupal.t('/node/42, a URL of this site or entity:artifact/7'),
          'aria-label': Drupal.t('Address of a record'),
        });
        const show = (items) => {
          results.replaceChildren(...items.map((item) => element('li', {}, [
            element('button', {
              type: 'button',
              class: 'divengine-picker__result',
              onclick: () => finish({ entity: item.entity, label: item.label }),
            }, [
              element('strong', { text: item.label }),
              element('span', { text: ` ${item.type}` }),
            ]),
          ])));
          message.textContent = items.length ? '' : Drupal.t('No records found.');
        };
        let timer = null;
        search.addEventListener('input', () => {
          clearTimeout(timer);
          const text = search.value.trim();
          if (text.length < 2) {
            results.replaceChildren();
            message.textContent = '';
            return;
          }
          timer = setTimeout(async () => {
            const { ok, data } = await getJson(this.urls.search, { q: text });
            if (search.value.trim() === text) {
              ok ? show(data.results || []) : (message.textContent = Drupal.t('The search failed.'));
            }
          }, 250);
        });
        const resolveButton = element('button', {
          type: 'button',
          class: 'button',
          text: Drupal.t('Use this address'),
          onclick: async () => {
            const { ok, data } = await getJson(this.urls.resolve, { value: paste.value });
            if (ok && data.result) {
              finish({ entity: data.result.entity, label: data.result.label });
            } else {
              message.textContent = (data.errors || [Drupal.t('Nothing found at that address.')]).join(' ');
            }
          },
        });
        const actions = element('div', { class: 'divengine-picker__actions' }, [
          current ? element('button', {
            type: 'button',
            class: 'button button--danger',
            text: Drupal.t('Remove link'),
            onclick: () => finish({ remove: true }),
          }) : null,
          element('button', {
            type: 'button',
            class: 'button',
            text: Drupal.t('Cancel'),
            onclick: () => finish(null),
          }),
        ]);
        const dialog = element('dialog', { class: 'divengine-picker', 'aria-label': Drupal.t('Link to a record') }, [
          element('h2', { text: Drupal.t('Link to a record') }),
          current ? element('p', {
            class: 'divengine-picker__current',
            text: Drupal.t('Currently linked to @label (@uri).', { '@label': current.label || '', '@uri': current.entity }),
          }) : null,
          search,
          results,
          element('div', { class: 'divengine-picker__paste-row' }, [paste, resolveButton]),
          message,
          actions,
        ]);
        dialog.addEventListener('cancel', () => finish(null));
        document.body.append(dialog);
        dialog.showModal();
        search.focus();
      });
    }
  }

  /**
   * The page around a notation editor.
   */
  class Shell {
    constructor(root, settings, notation) {
      this.root = root;
      this.settings = settings;
      this.notation = notation;
      this.revisionId = settings.revisionId;
      this.dirty = false;
      this.saving = false;
      this.picker = new EntityPicker(settings.urls);
      this.build();
    }

    build() {
      const { settings } = this;
      const button = (label, action, extra = {}) => element('button', {
        type: 'button',
        class: 'button button--small',
        onclick: action,
        ...extra,
      }, [document.createTextNode(label)]);
      this.saveButton = button(Drupal.t('Save'), () => this.save(), { class: 'button button--small button--primary' });
      this.status = element('span', { class: 'divengine-modeler__status', role: 'status', 'aria-live': 'polite' });
      this.log = element('input', {
        type: 'text',
        class: 'divengine-modeler__log form-text',
        maxlength: 1000,
        placeholder: Drupal.t('What changed? (revision message)'),
        'aria-label': Drupal.t('Revision message'),
      });
      const tools = [
        this.saveButton,
        button(Drupal.t('Undo'), () => this.editor?.undo(), { title: 'Ctrl+Z' }),
        button(Drupal.t('Redo'), () => this.editor?.redo(), { title: 'Ctrl+Y' }),
        button(Drupal.t('Fit'), () => this.editor?.fit()),
      ];
      this.linkButton = button(Drupal.t('Link element'), () => this.editor?.linkSelection?.(), {
        title: Drupal.t('Link the selected element to a record'),
      });
      tools.push(this.linkButton);
      tools.push(button(Drupal.t('Download source'), () => this.downloadSource()));
      tools.push(button(Drupal.t('Download SVG'), () => this.downloadSvg()));
      tools.push(element('a', { class: 'button button--small', href: settings.urls.canonical, text: Drupal.t('Close') }));
      const state = settings.state ? element('span', {
        class: `divengine-modeler__state divengine-modeler__state--${settings.state}`,
        text: settings.state,
      }) : null;
      this.toolbar = element('div', { class: 'divengine-modeler__toolbar' }, [
        element('div', { class: 'divengine-modeler__title' }, [
          element('strong', { text: settings.label }),
          element('span', { class: 'divengine-modeler__notation', text: settings.notationLabel }),
          state,
        ]),
        element('div', { class: 'divengine-modeler__tools' }, tools),
        element('div', { class: 'divengine-modeler__savebar' }, [this.log, this.status]),
      ]);
      this.canvas = element('div', { class: 'divengine-modeler__canvas' });
      this.root.replaceChildren(this.toolbar, this.canvas);

      window.addEventListener('beforeunload', (event) => {
        if (this.dirty) {
          event.preventDefault();
          event.returnValue = '';
        }
      });
      this.root.addEventListener('keydown', (event) => {
        const mod = event.ctrlKey || event.metaKey;
        if (mod && event.key.toLowerCase() === 's') {
          event.preventDefault();
          this.save();
        }
      });
      document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && !this.root.contains(event.target)) {
          event.preventDefault();
          this.save();
        }
      });
    }

    async mount() {
      this.setStatus(Drupal.t('Loading the editor…'));
      try {
        this.editor = await this.notation.mount(this.canvas, {
          source: this.settings.source,
          bindings: this.settings.bindings || {},
          settings: this.settings.settings || {},
          markDirty: () => this.markDirty(),
          setStatus: (message, kind) => this.setStatus(message, kind),
          pickEntity: (current) => this.picker.open(current),
          openEntity: (uri) => window.open(`${this.settings.urls.go}?uri=${encodeURIComponent(uri)}`, '_blank', 'noopener'),
        });
        this.linkButton.hidden = typeof this.editor.linkSelection !== 'function';
        this.setStatus('');
      } catch (error) {
        this.setStatus(Drupal.t('The editor could not load: @error', { '@error': error?.message || error }), 'error');
      }
    }

    markDirty() {
      this.dirty = true;
      this.root.classList.add('is-dirty');
      this.setStatus(Drupal.t('Unsaved changes'));
    }

    setStatus(message, kind = 'info') {
      this.status.textContent = message || '';
      this.status.dataset.kind = kind;
    }

    async save(force = false) {
      if (!this.editor || this.saving) {
        return;
      }
      this.saving = true;
      this.saveButton.disabled = true;
      this.setStatus(Drupal.t('Saving…'));
      try {
        const source = await this.editor.getSource();
        let svg = null;
        try {
          svg = await this.editor.exportSvg();
        } catch (error) {
          // The source is saved even when the preview cannot be exported.
          svg = null;
        }
        const { ok, status, data } = await postJson(this.settings.urls.save, this.settings.urls.token, {
          source,
          svg,
          bindings: this.editor.getBindings ? this.editor.getBindings() : {},
          log: this.log.value,
          baseRevisionId: this.revisionId,
          force,
        });
        if (ok) {
          this.revisionId = data.revisionId;
          this.dirty = false;
          this.root.classList.remove('is-dirty');
          this.log.value = '';
          this.setStatus(data.previewFresh ? data.message : `${data.message} ${Drupal.t('The preview could not be exported.')}`, 'ok');
        } else if (status === 409 && window.confirm((data.errors || []).join(' '))) {
          this.saving = false;
          await this.save(true);
          return;
        } else {
          this.setStatus((data.errors || [Drupal.t('Saving failed (@status).', { '@status': status })]).join(' '), 'error');
        }
      } catch (error) {
        this.setStatus(Drupal.t('Saving failed: @error', { '@error': error?.message || error }), 'error');
      } finally {
        this.saving = false;
        this.saveButton.disabled = false;
      }
    }

    async downloadSource() {
      if (this.editor) {
        const type = this.settings.fileExtension === 'json' ? 'application/json' : 'text/plain';
        download(fileName(this.settings.label, this.settings.fileExtension), type, await this.editor.getSource());
      }
    }

    async downloadSvg() {
      if (!this.editor) {
        return;
      }
      try {
        download(fileName(this.settings.label, 'svg'), 'image/svg+xml', await this.editor.exportSvg());
      } catch (error) {
        this.setStatus(Drupal.t('The SVG could not be exported.'), 'error');
      }
    }
  }

  Drupal.divengineModeler = {
    registerNotation(id, implementation) {
      notations[id] = implementation;
    },
    notation(id) {
      return notations[id];
    },
    element,
    postJson,
  };

  Drupal.behaviors.divengineModeler = {
    attach(context, settings) {
      once('divengine-modeler', '[data-divengine-modeler]', context).forEach((root) => {
        const editorSettings = settings.divengineModeler?.editors?.[root.dataset.divengineModeler];
        const notation = editorSettings && notations[editorSettings.notation];
        if (!notation) {
          root.textContent = Drupal.t('No editor is available for this notation.');
          return;
        }
        new Shell(root, editorSettings, notation).mount();
      });
    },
  };

  Drupal.behaviors.divengineModelerPreview = {
    attach(context, settings) {
      once('divengine-preview', '[data-divengine-preview-render]', context).forEach(async (container) => {
        const preview = settings.divengineModeler?.previews?.[container.dataset.diagramId];
        const notation = preview && notations[preview.notation];
        if (!notation?.renderPreview) {
          return;
        }
        const status = container.querySelector('.divengine-diagram-preview__status');
        try {
          if (status) {
            status.textContent = Drupal.t('Rendering the preview…');
          }
          const svg = await notation.renderPreview({
            source: preview.source,
            bindings: preview.bindings || {},
            settings: preview.settings || {},
          });
          const { ok, data } = await postJson(preview.storeUrl, preview.tokenUrl, { svg, hash: preview.hash });
          if (!ok) {
            throw new Error((data.errors || []).join(' '));
          }
          let image = container.querySelector('img');
          if (!image) {
            image = element('img', { alt: '' });
            container.prepend(image);
          }
          image.src = data.url;
          status?.remove();
        } catch (error) {
          if (status) {
            status.textContent = Drupal.t('The preview could not be rendered: @error', { '@error': error?.message || error });
          }
        }
      });
    },
  };
})(Drupal, drupalSettings, once);

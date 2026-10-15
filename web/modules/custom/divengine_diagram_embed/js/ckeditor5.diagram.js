/**
 * @file
 * CKEditor 5 plugin that embeds diagrams as <drupal-diagram>.
 *
 * Written against the CKEditor 5 build shipped by Drupal core (the global
 * CKEditor5 object), so it needs no build step. The data is
 * <drupal-diagram data-diagram-uuid="…" data-align="…"></drupal-diagram>; the
 * divengine_diagram_embed text filter renders it. Inside the editor the embed
 * is a widget showing the diagram's preview as an image.
 */
((Drupal, CKEditor5) => {
  const { Plugin, Command } = CKEditor5.core;
  const { Widget, WidgetToolbarRepository, toWidget, isWidget } = CKEditor5.widget;
  const { ButtonView } = CKEditor5.ui;

  const MODEL = 'divengineDiagram';
  const ALIGNMENTS = ['left', 'center', 'right'];

  const ICONS = {
    diagram: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M2 2h7v5H2zm9 11h7v5h-7zM5 7h1.5v7.25H11v1.5H5z"/></svg>',
    left: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M2 4h8v7H2zm10 1h6v1.5h-6zm0 4h6v1.5h-6zM2 14h16v1.5H2z"/></svg>',
    center: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M5 3h10v8H5zm-3 11h16v1.5H2zm0 3h16v1.5H2z"/></svg>',
    right: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 4h8v7h-8zM2 5h6v1.5H2zm0 4h6v1.5H2zm0 5h16v1.5H2z"/></svg>',
  };

  /**
   * The diagram widget selected in the model, if any.
   */
  function selectedDiagram(selection) {
    const element = selection.getSelectedElement();
    return element && element.name === MODEL ? element : null;
  }

  /**
   * Inserts a diagram, or replaces the selected one keeping its alignment.
   */
  class InsertDiagramCommand extends Command {
    refresh() {
      const { model } = this.editor;
      const selected = selectedDiagram(model.document.selection);
      this.value = selected ? selected.getAttribute('diagramUuid') : null;
      this.isEnabled = Boolean(selected) || model.schema.findAllowedParent(model.document.selection.getFirstPosition(), MODEL) !== null;
    }

    execute(uuid) {
      const { model } = this.editor;
      model.change((writer) => {
        const selected = selectedDiagram(model.document.selection);
        if (selected) {
          writer.setAttribute('diagramUuid', uuid, selected);
          return;
        }
        model.insertObject(writer.createElement(MODEL, { diagramUuid: uuid }), null, null, { setSelection: 'on' });
      });
    }
  }

  /**
   * Aligns the selected diagram; choosing the current alignment clears it.
   */
  class AlignDiagramCommand extends Command {
    refresh() {
      const selected = selectedDiagram(this.editor.model.document.selection);
      this.isEnabled = Boolean(selected);
      this.value = selected ? selected.getAttribute('diagramAlign') || null : null;
    }

    execute({ value }) {
      const { model } = this.editor;
      const selected = selectedDiagram(model.document.selection);
      model.change((writer) => {
        if (!ALIGNMENTS.includes(value) || selected.getAttribute('diagramAlign') === value) {
          writer.removeAttribute('diagramAlign', selected);
        }
        else {
          writer.setAttribute('diagramAlign', value, selected);
        }
      });
    }
  }

  class DivengineDiagram extends Plugin {
    static get pluginName() {
      return 'DivengineDiagram';
    }

    static get requires() {
      return [Widget, WidgetToolbarRepository];
    }

    init() {
      const { editor } = this;
      this.lookups = new Map();
      this.urls = editor.config.get('divengineDiagram') || {};

      editor.model.schema.register(MODEL, {
        inheritAllFrom: '$blockObject',
        allowAttributes: ['diagramUuid', 'diagramAlign'],
      });
      this.defineConverters();

      editor.commands.add('insertDivengineDiagram', new InsertDiagramCommand(editor));
      editor.commands.add('alignDivengineDiagram', new AlignDiagramCommand(editor));

      editor.ui.componentFactory.add('divengineDiagram', (locale) => {
        const button = new ButtonView(locale);
        const command = editor.commands.get('insertDivengineDiagram');
        button.set({ label: Drupal.t('Diagram'), icon: ICONS.diagram, tooltip: true });
        button.bind('isEnabled').to(command, 'isEnabled');
        button.bind('isOn').to(command, 'value', (value) => Boolean(value));
        this.listenTo(button, 'execute', () => this.openPicker());
        return button;
      });
      const alignLabels = {
        left: Drupal.t('Align left'),
        center: Drupal.t('Center'),
        right: Drupal.t('Align right'),
      };
      ALIGNMENTS.forEach((align) => {
        const name = `divengineDiagramAlign${align[0].toUpperCase()}${align.slice(1)}`;
        editor.ui.componentFactory.add(name, (locale) => {
          const button = new ButtonView(locale);
          const command = editor.commands.get('alignDivengineDiagram');
          button.set({ label: alignLabels[align], icon: ICONS[align], tooltip: true, isToggleable: true });
          button.bind('isEnabled').to(command, 'isEnabled');
          button.bind('isOn').to(command, 'value', (value) => value === align);
          this.listenTo(button, 'execute', () => {
            editor.execute('alignDivengineDiagram', { value: align });
            editor.editing.view.focus();
          });
          return button;
        });
      });
      editor.ui.componentFactory.add('divengineDiagramReplace', (locale) => {
        const button = new ButtonView(locale);
        button.set({ label: Drupal.t('Choose another diagram'), withText: true, tooltip: true });
        this.listenTo(button, 'execute', () => this.openPicker());
        return button;
      });
    }

    afterInit() {
      const { editor } = this;
      editor.plugins.get(WidgetToolbarRepository).register('divengineDiagram', {
        ariaLabel: Drupal.t('Diagram toolbar'),
        items: ['divengineDiagramAlignLeft', 'divengineDiagramAlignCenter', 'divengineDiagramAlignRight', '|', 'divengineDiagramReplace'],
        getRelatedElement: (selection) => {
          const element = selection.getSelectedElement();
          return element && isWidget(element) && element.getCustomProperty(MODEL) ? element : null;
        },
      });
    }

    defineConverters() {
      const { conversion } = this.editor;

      conversion.for('upcast').elementToElement({
        view: { name: 'drupal-diagram', attributes: { 'data-diagram-uuid': true } },
        model: (viewElement, { writer }) => {
          const attributes = { diagramUuid: viewElement.getAttribute('data-diagram-uuid') };
          const align = viewElement.getAttribute('data-align');
          if (ALIGNMENTS.includes(align)) {
            attributes.diagramAlign = align;
          }
          return writer.createElement(MODEL, attributes);
        },
      });

      conversion.for('dataDowncast').elementToElement({
        model: { name: MODEL, attributes: ['diagramUuid', 'diagramAlign'] },
        view: (modelElement, { writer }) => {
          const attributes = { 'data-diagram-uuid': modelElement.getAttribute('diagramUuid') };
          if (modelElement.getAttribute('diagramAlign')) {
            attributes['data-align'] = modelElement.getAttribute('diagramAlign');
          }
          return writer.createEmptyElement('drupal-diagram', attributes);
        },
      });

      conversion.for('editingDowncast').elementToElement({
        model: { name: MODEL, attributes: ['diagramUuid', 'diagramAlign'] },
        view: (modelElement, { writer }) => {
          const uuid = modelElement.getAttribute('diagramUuid');
          const align = modelElement.getAttribute('diagramAlign');
          const classes = ['divengine-diagram-widget'];
          if (align) {
            classes.push(`divengine-diagram-widget--align-${align}`);
          }
          const container = writer.createContainerElement('div', { class: classes.join(' '), 'data-diagram-uuid': uuid });
          const preview = writer.createRawElement('div', { class: 'divengine-diagram-widget__preview' }, (domElement) => {
            this.renderPreview(domElement, uuid);
          });
          writer.insert(writer.createPositionAt(container, 0), preview);
          writer.setCustomProperty(MODEL, true, container);
          return toWidget(container, writer, { label: Drupal.t('Diagram') });
        },
      });
    }

    /**
     * GETs JSON from one of the plugin's endpoints.
     */
    async getJson(url, params) {
      const query = new URLSearchParams(params).toString();
      const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      let body = {};
      try {
        body = await response.json();
      }
      catch (error) {
        body = {};
      }
      return { status: response.status, body };
    }

    /**
     * What the server says about a diagram, fetched once per editor.
     */
    lookup(uuid) {
      if (!this.lookups.has(uuid)) {
        this.lookups.set(uuid, this.getJson(this.urls.lookupUrl, { uuid }).then(({ status, body }) => {
          if (status === 200 && body.result) {
            return { state: 'ready', diagram: body.result };
          }
          return { state: status === 403 ? 'restricted' : 'missing' };
        }).catch(() => ({ state: 'error' })));
      }
      return this.lookups.get(uuid);
    }

    /**
     * Fills the widget with the preview, or with a notice.
     */
    renderPreview(domElement, uuid) {
      const document = domElement.ownerDocument;
      domElement.textContent = Drupal.t('Loading diagram…');
      this.lookup(uuid).then(({ state, diagram }) => {
        domElement.textContent = '';
        if (state === 'ready' && diagram.previewUrl) {
          const image = document.createElement('img');
          image.src = diagram.previewUrl;
          image.alt = Drupal.t('Diagram: @label', { '@label': diagram.label });
          domElement.append(image);
        }
        const caption = document.createElement('div');
        caption.className = 'divengine-diagram-widget__caption';
        const messages = {
          ready: diagram && diagram.previewUrl
            ? diagram.label
            : Drupal.t('@label (no preview yet)', { '@label': diagram ? diagram.label : '' }),
          restricted: Drupal.t('Restricted diagram'),
          missing: Drupal.t('Missing diagram'),
          error: Drupal.t('The diagram could not be loaded.'),
        };
        caption.textContent = messages[state];
        domElement.append(caption);
      });
    }

    /**
     * Opens the dialog that searches diagrams and inserts the chosen one.
     */
    openPicker() {
      const { editor } = this;
      const document = editor.ui.view.element ? editor.ui.view.element.ownerDocument : window.document;
      const dialog = document.createElement('dialog');
      dialog.className = 'divengine-diagram-picker';
      dialog.setAttribute('aria-label', Drupal.t('Embed a diagram'));
      const heading = document.createElement('h2');
      heading.textContent = Drupal.t('Embed a diagram');
      const input = document.createElement('input');
      input.type = 'search';
      input.className = 'divengine-diagram-picker__search';
      input.placeholder = Drupal.t('Search diagrams by name');
      input.setAttribute('aria-label', Drupal.t('Search diagrams by name'));
      const status = document.createElement('p');
      status.className = 'divengine-diagram-picker__status';
      status.setAttribute('role', 'status');
      const list = document.createElement('ul');
      list.className = 'divengine-diagram-picker__results';
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'divengine-diagram-picker__cancel';
      cancel.textContent = Drupal.t('Cancel');
      dialog.append(heading, input, status, list, cancel);

      const close = () => {
        dialog.close();
        dialog.remove();
        editor.editing.view.focus();
      };
      let request = 0;
      const search = async () => {
        const current = ++request;
        status.textContent = Drupal.t('Searching…');
        let response;
        try {
          response = await this.getJson(this.urls.searchUrl, { q: input.value });
        }
        catch (error) {
          response = { status: 0, body: {} };
        }
        if (current !== request) {
          return;
        }
        list.textContent = '';
        const results = response.status === 200 ? response.body.results || [] : [];
        if (response.status !== 200) {
          status.textContent = Drupal.t('Diagrams could not be searched.');
          return;
        }
        status.textContent = results.length ? '' : Drupal.t('No diagram matches.');
        results.forEach((diagram) => {
          const item = document.createElement('li');
          const choose = document.createElement('button');
          choose.type = 'button';
          choose.className = 'divengine-diagram-picker__result';
          choose.dataset.uuid = diagram.uuid;
          const label = document.createElement('span');
          label.className = 'divengine-diagram-picker__label';
          label.textContent = diagram.label;
          const type = document.createElement('span');
          type.className = 'divengine-diagram-picker__type';
          type.textContent = diagram.type;
          choose.append(label, type);
          choose.addEventListener('click', () => {
            this.lookups.set(diagram.uuid, Promise.resolve({ state: 'ready', diagram }));
            close();
            editor.execute('insertDivengineDiagram', diagram.uuid);
          });
          item.append(choose);
          list.append(item);
        });
      };
      let timer = null;
      input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(search, 250);
      });
      cancel.addEventListener('click', close);
      dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
      });
      document.body.append(dialog);
      dialog.showModal();
      input.focus();
      return search();
    }
  }

  CKEditor5.divengineDiagram = { DivengineDiagram };
})(Drupal, CKEditor5);

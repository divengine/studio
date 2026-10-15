/**
 * @file
 * BPMN notation for the Divengine modeler, on the vendored bpmn-js modeler.
 *
 * Element links are stored on the element as divengine:entity and
 * divengine:label extension attributes (ADR-0005).
 */
((Drupal, BpmnJS) => {
  const NAMESPACE = 'https://divengine.com/schema/modeler/1.0';

  const moddleExtension = {
    name: 'Divengine',
    uri: NAMESPACE,
    prefix: 'divengine',
    xml: { tagAlias: 'lowerCase' },
    types: [
      {
        name: 'LinkedElement',
        extends: ['bpmn:BaseElement'],
        properties: [
          { name: 'entity', isAttr: true, type: 'String' },
          { name: 'label', isAttr: true, type: 'String' },
        ],
      },
    ],
  };

  function linkOf(element) {
    const businessObject = element?.businessObject;
    const entity = businessObject?.get ? businessObject.get('divengine:entity') : null;
    return entity ? { entity, label: businessObject.get('divengine:label') || '' } : null;
  }

  function isLinkable(element, canvas) {
    return element && element.type !== 'label' && element !== canvas.getRootElement() && element.businessObject;
  }

  /**
   * Adds "Link to a record" to the context pad of every element.
   */
  function LinkContextPad(contextPad, canvas, options) {
    contextPad.registerProvider(500, this);
    this.getContextPadEntries = (element) => {
      if (!isLinkable(element, canvas)) {
        return {};
      }
      return {
        'divengine-link': {
          group: 'edit',
          className: linkOf(element) ? 'divengine-link-entry is-linked' : 'divengine-link-entry',
          title: Drupal.t('Link to a record'),
          action: {
            click: () => options.link(element),
          },
        },
      };
    };
  }
  LinkContextPad.$inject = ['contextPad', 'canvas', 'config.divengine'];

  const linkModule = {
    __init__: ['divengineLinkContextPad'],
    divengineLinkContextPad: ['type', LinkContextPad],
  };

  /**
   * Marks linked elements in an exported SVG for the platform's preview.
   */
  function annotate(svg, linkedIds) {
    const document = new DOMParser().parseFromString(svg, 'image/svg+xml');
    if (document.querySelector('parsererror')) {
      throw new Error('bpmn-js exported an unreadable SVG.');
    }
    document.querySelectorAll('[data-element-id]').forEach((node) => {
      if (linkedIds.has(node.getAttribute('data-element-id'))) {
        node.setAttribute('data-divengine-element', node.getAttribute('data-element-id'));
      }
    });
    // The serialized root drops the XML declaration and the DOCTYPE, which
    // the server refuses.
    return new XMLSerializer().serializeToString(document.documentElement);
  }

  async function exportSvg(modeler) {
    const { svg } = await modeler.saveSVG();
    const linked = new Set(
      modeler.get('elementRegistry').filter((element) => linkOf(element)).map((element) => element.id),
    );
    return annotate(svg, linked);
  }

  async function importSource(modeler, source, setStatus) {
    const { warnings } = await modeler.importXML(source);
    if (warnings?.length && setStatus) {
      setStatus(Drupal.formatPlural(warnings.length, 'Imported with 1 warning.', 'Imported with @count warnings.'), 'info');
    }
  }

  Drupal.divengineModeler.registerNotation('bpmn', {
    async mount(container, context) {
      let modeler = null;
      const link = async (element) => {
        const current = linkOf(element);
        const choice = await context.pickEntity(current);
        if (!choice) {
          return;
        }
        modeler.get('modeling').updateProperties(element, choice.remove
          ? { 'divengine:entity': undefined, 'divengine:label': undefined }
          : { 'divengine:entity': choice.entity, 'divengine:label': choice.label });
      };

      modeler = new BpmnJS({
        container,
        additionalModules: [linkModule],
        moddleExtensions: { divengine: moddleExtension },
        divengine: { link },
      });

      const overlays = modeler.get('overlays');
      const registry = modeler.get('elementRegistry');
      const refreshBadges = () => {
        overlays.remove({ type: 'divengine-link' });
        registry.forEach((element) => {
          const current = element.type !== 'label' ? linkOf(element) : null;
          if (!current) {
            return;
          }
          const badge = document.createElement('button');
          badge.type = 'button';
          badge.className = 'divengine-link-badge';
          badge.title = Drupal.t('Open @label', { '@label': current.label || current.entity });
          badge.textContent = '↗';
          badge.addEventListener('click', (event) => {
            event.stopPropagation();
            context.openEntity(current.entity);
          });
          overlays.add(element, 'divengine-link', { position: { top: -10, right: 8 }, html: badge });
        });
      };

      modeler.on('commandStack.changed', () => {
        context.markDirty();
        refreshBadges();
      });
      await importSource(modeler, context.source, context.setStatus);
      modeler.get('canvas').zoom('fit-viewport', 'auto');
      refreshBadges();

      return {
        // The bpmn-js instance, for integrations and tests.
        engine: modeler,
        async getSource() {
          const { xml } = await modeler.saveXML({ format: true });
          return xml;
        },
        exportSvg: () => exportSvg(modeler),
        getBindings: () => ({}),
        undo: () => modeler.get('commandStack').undo(),
        redo: () => modeler.get('commandStack').redo(),
        fit: () => modeler.get('canvas').zoom('fit-viewport', 'auto'),
        linkSelection() {
          const [element] = modeler.get('selection').get();
          if (isLinkable(element, modeler.get('canvas'))) {
            link(element);
          } else {
            context.setStatus(Drupal.t('Select an element first.'), 'info');
          }
        },
        destroy: () => modeler.destroy(),
      };
    },

    async renderPreview({ source }) {
      const container = document.createElement('div');
      container.style.cssText = 'position:fixed;left:-10000px;top:0;width:1200px;height:800px;';
      document.body.append(container);
      const viewer = new BpmnJS({ container, moddleExtensions: { divengine: moddleExtension } });
      try {
        await viewer.importXML(source);
        return await exportSvg(viewer);
      } finally {
        viewer.destroy();
        container.remove();
      }
    },
  });
})(Drupal, window.BpmnJS);

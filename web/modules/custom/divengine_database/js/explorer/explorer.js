/* Mounts the schema explorer or the schema evolution page (OWL) where the server placed it.
 *
 * The page renders <div data-divengine-database="explorer|evolution"> and passes its endpoints and
 * parameters in drupalSettings.divengineDatabase. JointJS and ELK are loaded by the library.
 */
import { StudioApi } from "./api.js";
import { SchemaExplorer } from "./schema_explorer.js";
import { SchemaEvolution } from "./schema_evolution.js";
import explorerTemplates from "./schema_explorer.templates.js";
import evolutionTemplates from "./schema_evolution.templates.js";

const PAGES = {
    explorer: { Component: SchemaExplorer, templates: explorerTemplates },
    evolution: { Component: SchemaEvolution, templates: evolutionTemplates },
};

const { Drupal, drupalSettings, once } = window;

Drupal.behaviors.divengineDatabaseExplorer = {
    attach(context) {
        for (const el of once("divengine-database", "[data-divengine-database]", context)) {
            const page = PAGES[el.dataset.divengineDatabase];
            const settings = drupalSettings.divengineDatabase || {};
            if (!page) {
                continue;
            }
            const api = new StudioApi(settings);
            window.owl.mount(page.Component, el, {
                templates: page.templates,
                dev: false,
                props: { api, params: settings.params || {}, elkWorker: settings.elkWorker },
            });
        }
    },
};

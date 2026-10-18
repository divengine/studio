/* Schema explorer — OWL controller for the interactive database diagram (mounted by explorer.js on
 * the explorer pages). Orchestrates the pipeline and owns UI state; each stage lives in its own module:
 *
 *   server (SchemaFacts)       normalized facts: index / details / search
 *   schema_model.js            client-side facts + adjacency (no rendering knowledge)
 *   graph_adapter.js           visible subgraph + geometry (GraphSpec)
 *   layout_service.js          ELK layered layout + orthogonal routing, in a Web Worker
 *   renderer.js                JointJS drawing, LOD, culling, interaction
 *
 * Params: {snapshot_id} (explore a snapshot) and/or {table_id} (start from that table's neighborhood).
 * Diff mode: {diff_from, diff_to} explores the UNION of two snapshots (the diff graph endpoint) with the
 * server's change annotations — removed objects are ghosts from FROM, everything else is drawn from TO.
 */
import { SchemaModel } from "./schema_model.js";
import { HUB_CHOICES, buildGraphSpec, defaultViewState, resolveDensity, selectTables, tablesNeedingDetails } from "./graph_adapter.js";
import { ALGORITHMS, ALGORITHM_LABEL } from "./layout_shapes.js";
import { DIRECTIONS, ELK_VERSION, LayoutService } from "./layout_service.js";
import { JOINT_VERSION, SchemaRenderer } from "./renderer.js";
import { LOD_LABEL } from "./lod.js";
import { CHANGE_SIGN, TYPE_LABEL, describeProperty, objectLabel } from "./schema_diff_view.js";
import { LocalPositionStore, viewSignature } from "./position_store.js";
import {
    AutoQuality, FrameMeter, GraphicsPreferenceStore, KNOB_OPTIONS, LEVEL_LABEL, PRESET_CHOICES,
    geometryKey, resolvePolicy, settingsFor,
} from "./graphics_policy.js";

const { Component, onMounted, onWillStart, onWillUnmount, useRef, useState } = window.owl;

// "Show everything" is automatic only for small schemas; beyond this the explorer starts from a
// neighborhood and asks before laying out the whole snapshot (it stays possible, never forbidden).
const AUTO_ALL_MAX = 120;
const DETAIL_BATCH = 400;
const SEARCH_DEBOUNCE = 180;

// Diff focus modes: which part of the union A U B is laid out.
//   changed    only tables that changed (added / removed / modified, incl. child changes)
//   neighbors  changed tables + their direct FK neighbours (context) — the default
//   all        the whole union, unchanged tables subdued
export const DIFF_FOCUS = ["changed", "neighbors", "all"];

const LAYOUT_PREFS_KEY = "divengine_database.schema_explorer.layout";

/** Remembered layout algorithm + hub threshold (per browser; visualization preference only). */
function loadLayoutPrefs() {
    try {
        return JSON.parse(window.localStorage.getItem(LAYOUT_PREFS_KEY) || "{}") || {};
    } catch {
        return {};
    }
}

export class SchemaExplorer extends Component {
    static template = "divengine_database.SchemaExplorer";
    static props = ["*"];

    setup() {
        this.api = this.props.api;
        this.stageRef = useRef("stage");
        this.minimapRef = useRef("minimap");
        this.rootRef = useRef("root");
        this.searchRef = useRef("search");

        const params = this.props.params || {};
        this.params = params;
        this.diffPair = params.diff_from && params.diff_to
            ? { from: Number(params.diff_from), to: Number(params.diff_to) } : null;
        this.view = defaultViewState();
        this.store = new LocalPositionStore();
        // Graphics quality: preset (persisted) -> settings -> resolved RenderPolicy (graphics_policy.js).
        this.gfxStore = new GraphicsPreferenceStore();
        this.auto = new AutoQuality();
        this.meter = new FrameMeter((ms, t) => this._onFrame(ms, t));
        const gfxPrefs = this.gfxStore.load();
        this.algorithms = ALGORITHMS;
        this.hubChoices = HUB_CHOICES;
        const layoutPrefs = loadLayoutPrefs();
        if (HUB_CHOICES.includes(layoutPrefs.hubThreshold)) {
            this.view.hubThreshold = layoutPrefs.hubThreshold;
        }
        this.model = null;
        this.renderer = null;
        this.layoutService = null;
        this._generation = 0;

        this.state = useState({
            phase: "loading",          // loading | layout | ready | error
            error: null,
            notice: null,              // large-graph guidance
            confirmAll: false,
            // controls (mirrors this.view for the template)
            scope: "neighborhood",
            depth: 1,
            direction: "both",
            kinds: "both",
            density: "auto",
            schema: "",
            showTables: true,
            showViews: true,
            connectivity: "any",
            layoutDirection: DIRECTIONS.RIGHT,
            layoutAlgorithm: ALGORITHMS.includes(layoutPrefs.algorithm) ? layoutPrefs.algorithm : "layered", // layered | organic | radial (layout_shapes.ALGORITHMS)
            hubThreshold: this.view.hubThreshold,
            layoutNotice: null,         // e.g. organic -> layered fallback on a huge graph
            // search
            query: "",
            results: [],
            searching: false,
            // selection / inspector
            selected: null,            // {kind: 'table'|'relation', id}
            inspector: null,
            // HUD / perf
            hud: {},
            perf: {},
            showPerf: !!params.debug,
            gfx: {
                preset: gfxPrefs.preset,          // auto | low | balanced | high | ultra | custom
                custom: gfxPrefs.custom,          // persisted CUSTOM knobs (null until first customised)
                level: "balanced",                // resolved level (AUTO) or the preset itself
                settings: {},                     // effective knobs (drives the custom panel)
                background: "grid",
                minimap: "normal",
                panel: false,
                autoAction: "",
                fps: null,
            },
            showMinimap: true,
            fullscreen: false,
            snapshotLabel: "",
            snapshotId: null,
            snapshotGroups: [],        // [{database, snapshots: [{id, label}]}] for the selector
            switching: false,
            schemas: [],
            seedNames: [],
            // schema diff overlay (null outside diff mode)
            diff: null,                // {fromKey, toKey, changedTables, summary, notes, crossDatabase}
            diffFocus: "neighbors",
        });

        onWillStart(async () => {
            try {
                const t0 = performance.now();
                const indexCall = this.diffPair
                    ? this.api.diffGraph(this.diffPair.from, this.diffPair.to)
                    : this.api.index(this.params.snapshot_id);
                const [index] = await Promise.all([
                    indexCall,
                    this.diffPair ? Promise.resolve() : this._loadSnapshotChoices(),
                ]);
                this._setModel(index, performance.now() - t0);
                if (!window.joint || !window.ELK) {
                    throw new Error("The diagram libraries (JointJS / ELK) could not be loaded.");
                }
            } catch (e) {
                this.state.phase = "error";
                this.state.error = (e && e.message) || String(e);
            }
        });

        this._resolveGraphics();

        onMounted(() => {
            if (this.state.phase === "error") {
                return;
            }
            this.layoutService = new LayoutService(window.ELK, { workerUrl: this.props.elkWorker });
            this.renderer = new SchemaRenderer(this.stageRef.el, window.joint, {
                relationMeta: (rid) => this.model.relation(rid) || {},
                onSelectTable: (tid) => this.selectTable(tid),
                onSelectRelation: (rid) => this.selectRelation(rid),
                onActivateTable: (tid) => this.exploreFrom(tid),
                onHover: (target) => this._hover(target),
                onBlank: () => this.clearSelection(),
                onMoved: (tid, pos) => this._moved(tid, pos),
                onViewChange: () => this._viewChanged(),
            }, this.policy);
            this.renderer.setDiffMode(!!this.model.diff);
            this._initialScope();
            this.refresh({ fit: true });
            this._onKey = (ev) => this._keydown(ev);
            window.addEventListener("keydown", this._onKey);
        });

        onWillUnmount(() => {
            this.meter.stop();
            window.removeEventListener("keydown", this._onKey);
            clearTimeout(this._searchTimer);
            if (this.renderer) {
                this.renderer.destroy();
            }
            if (this.layoutService) {
                this.layoutService.destroy();
            }
        });
    }

    // ------------------------------------------------------------------ bootstrap
    /** Ready snapshots the user can see, grouped by database, for the snapshot selector. */
    async _loadSnapshotChoices() {
        const groups = await this.api.snapshots();
        this.state.snapshotGroups = groups.map((g) => ({
            database: g.database,
            snapshots: g.snapshots.map((s) => ({ id: s.id, label: s.key + (s.captured_at ? `  ·  ${s.captured_at.slice(0, 16).replace("T", " ")}` : "") })),
        }));
    }

    _setModel(index, fetchMs) {
        const t1 = performance.now();
        this.model = new SchemaModel(index);
        this.state.perf.fetchIndexMs = fetchMs;
        this.state.perf.modelMs = performance.now() - t1;
        this.state.snapshotId = index.snapshot.id;
        this.state.snapshotLabel = index.snapshot.key || "";
        this.state.schemas = this.model.schemas;
        const d = this.model.diff;
        this.state.diff = d ? {
            fromKey: d.from.key, toKey: d.to.key,
            // snapshot keys already carry the database code ("<code>@<fingerprint>")
            fromLabel: d.from.key || `#${d.from.id}`,
            toLabel: d.to.key || `#${d.to.id}`,
            changedTables: this.model.stats.changed_tables || 0,
            summary: d.summary, notes: d.notes || [], crossDatabase: !!d.cross_database,
        } : null;
    }

    /** Switch the explorer to another snapshot (any database) without leaving the view. */
    async switchSnapshot(ev) {
        const snapshotId = Number(ev.target.value);
        if (!snapshotId || snapshotId === this.state.snapshotId) {
            return;
        }
        this.state.switching = true;
        try {
            const t0 = performance.now();
            const index = await this.api.index(snapshotId);
            this._setModel(index, performance.now() - t0);
            window.history.replaceState(null, "", this.api.url("explorer", { snapshot: snapshotId }));
            // Table ids, seeds and filters belong to the previous snapshot: start fresh.
            this.view = defaultViewState();
            this.params = { snapshot_id: snapshotId };
            this.state.selected = null;
            this.state.inspector = null;
            this.state.results = [];
            this.state.confirmAll = false;
            this.state.notice = null;
            this._initialScope();
            await this.refresh({ fit: true });
        } catch (e) {
            this.state.phase = "error";
            this.state.error = (e && e.message) || String(e);
        } finally {
            this.state.switching = false;
        }
    }

    _initialScope() {
        if (this.model.diff) {
            this._applyDiffFocus();
            return;
        }
        const total = this.model.tableCount;
        const seed = this.params.table_id && this.model.table(this.params.table_id) ? this.params.table_id : null;
        if (seed) {
            this.view.seeds = [seed];
            this.view.scope = "neighborhood";
        } else if (total <= AUTO_ALL_MAX) {
            this.view.scope = "all";
        } else {
            // Real FKs first: SchemaSpy implied relations can create fake hubs (a table with a `name`
            // column can attract every implied relation of the schema).
            const hub = this.model.hubs(1, "real")[0] || this.model.hubs(1, this.view.kinds)[0];
            this.view.seeds = hub ? [hub] : [];
            this.view.scope = "neighborhood";
            this.state.notice = {
                total,
                text: `${total.toLocaleString()} tables detected. Showing the FK neighborhood of the most `
                    + "connected table — search, pick a table, or use “Show all” deliberately.",
            };
        }
        this._syncControls();
    }

    // ------------------------------------------------------------------ diff mode
    /** Union table ids whose own record or children changed. */
    changedTableIds() {
        const out = [];
        for (const t of this.model.tables.values()) {
            if (t.change && t.change !== "unchanged") {
                out.push(t.id);
            }
        }
        return out;
    }

    /** Diff focus -> scope/seeds/depth. Changed tables are seeds, so no filter can hide them. */
    _applyDiffFocus() {
        const focus = this.state.diffFocus;
        const changed = this.changedTableIds();
        this.state.notice = null;
        if (!changed.length) {
            this.view.seeds = [];
            this.view.scope = this.model.tableCount <= AUTO_ALL_MAX ? "all" : "neighborhood";
            if (this.view.scope === "neighborhood") {
                const hub = this.model.hubs(1, this.view.kinds)[0];
                this.view.seeds = hub ? [hub] : [];
            }
            this.state.notice = {
                total: this.model.tableCount,
                text: "No structural differences between the two snapshots — nothing to highlight.",
            };
        } else if (focus === "all") {
            this.view.seeds = changed;
            this.view.scope = "all";
            if (this.model.tableCount > AUTO_ALL_MAX) {
                this.state.notice = {
                    total: this.model.tableCount,
                    text: `Full union: ${this.model.tableCount.toLocaleString()} tables, unchanged ones subdued.`,
                };
            }
        } else {
            this.view.seeds = changed;
            this.view.scope = "neighborhood";
            this.view.depth = focus === "changed" ? 0 : Math.max(1, this.view.depth || 1);
        }
        this._syncControls();
    }

    setDiffFocus(focus) {
        if (!DIFF_FOCUS.includes(focus) || !this.model.diff) {
            return;
        }
        this.state.diffFocus = focus;
        this.view.depth = focus === "changed" ? 0 : Math.max(1, this.view.depth);
        this._applyDiffFocus();
        this.refresh({ fit: true });
    }

    /** Back to the Schema Evolution page for the same pair. */
    openEvolution() {
        if (!this.diffPair) {
            return;
        }
        this.api.go("evolution", { from: this.diffPair.from, to: this.diffPair.to });
    }

    changeSign(change) {
        return CHANGE_SIGN[change] || "";
    }

    _syncControls() {
        const v = this.view;
        Object.assign(this.state, {
            scope: v.scope, depth: v.depth, direction: v.direction, kinds: v.kinds, density: v.density,
            connectivity: v.connectivity, showTables: v.types.table, showViews: v.types.view,
            schema: v.schemas && v.schemas.size === 1 ? [...v.schemas][0] : "",
            seedNames: v.seeds.map((id) => (this.model.table(id) || {}).name).filter(Boolean),
        });
    }

    // ------------------------------------------------------------------ pipeline
    /**
     * Select -> fetch missing column rows -> build GraphSpec -> ELK -> JointJS. Superseded runs are
     * dropped (generation counter), so fast clicking never renders a stale graph.
     */
    async refresh({ fit = false, focus = null } = {}) {
        if (!this.model || !this.renderer) {
            return;
        }
        const gen = ++this._generation;
        const perf = { ...this.state.perf };
        this.state.phase = "layout";
        this.state.error = null;
        try {
            let t = performance.now();
            const sel = selectTables(this.model, this.view);
            perf.selectMs = performance.now() - t;

            // AUTO: graph complexity caps the level BEFORE layout (so geometry never needs a 2nd layout).
            if (this.state.gfx.preset === "auto") {
                const edges = this.model.relationsWithin(sel.ids, this.view.kinds).length;
                if (this.auto.setComplexity({ nodes: sel.ids.size, cells: sel.ids.size + edges }, performance.now())) {
                    this._resolveGraphics();
                }
            }
            // Layout geometry (how much of each table is drawn) comes from the graphics policy.
            this.view.detail = { ...this.policy.geometry };
            this._geometryApplied = geometryKey(this.policy);

            const need = tablesNeedingDetails(this.model, sel.ids, this.view);
            t = performance.now();
            for (let i = 0; i < need.length; i += DETAIL_BATCH) {
                const batch = need.slice(i, i + DETAIL_BATCH);
                const det = this.diffPair
                    ? await this.api.diffDetails(this.diffPair.from, this.diffPair.to, batch)
                    : await this.api.details(this.model.snapshot.id, batch);
                this.model.setDetails(det);
                if (gen !== this._generation) {
                    return;
                }
            }
            perf.detailsMs = performance.now() - t;
            perf.detailsFetched = need.length;

            t = performance.now();
            const spec = buildGraphSpec(this.model, sel.ids, this.view);
            perf.buildMs = performance.now() - t;

            const layout = await this.layoutService.layout(spec, {
                direction: this.state.layoutDirection,
                algorithm: this.state.layoutAlgorithm,
                // radial centre: the exploration seeds (diff mode: the changed tables), else the hub
                roots: this.view.seeds.map((id) => `t${id}`),
            });
            if (!layout || gen !== this._generation) {
                return;
            }
            perf.layoutMs = layout.ms;
            perf.layoutMode = layout.mode;
            perf.isolated = layout.isolated;
            this.state.layoutNotice = layout.fallback;
            this._layoutUsed = layout.algorithm;

            const pinned = this._pinnedFor(spec);
            perf.pinned = pinned.size;
            const rendered = await this.renderer.render(spec, layout, { pinned });
            if (gen !== this._generation) {
                return;
            }
            perf.renderMs = rendered.ms;
            this.spec = spec;
            this.selection = sel;
            this.state.perf = perf;
            this.state.phase = "ready";
            if (focus && this.renderer.focusTable(focus)) {
                this.selectTable(focus);
            } else if (fit) {
                this.renderer.fit();
            }
            this._reapplySelection();
            this._updateHud();
        } catch (e) {
            if (gen === this._generation) {
                this.state.phase = "error";
                this.state.error = (e && (e.data && e.data.message || e.message)) || String(e);
            }
        }
    }

    /** Layout identity for saved manual positions (layered at the default hub setting keeps the old key). */
    _layoutKey() {
        let key = this.state.layoutDirection;
        if (this.state.layoutAlgorithm !== "layered") {
            key = `${this.state.layoutAlgorithm}:${key}`;
        }
        if (this.view.hubThreshold !== defaultViewState().hubThreshold) {
            key = `${key}:hubs${this.view.hubThreshold}`;
        }
        return key;
    }

    _pinnedFor(spec) {
        const code = this.model.snapshot.database_code || "db";
        // In diff mode the seeds are "every changed table": key the layout by the pair + focus instead.
        this._signature = this.diffPair
            ? `diff:${this.model.diff.from.key}>${this.model.diff.to.key}|${this.state.diffFocus}|`
                + viewSignature({ ...this.view, scope: this.view.scope }, [], this._layoutKey())
            : viewSignature(this.view, this.view.seeds.map((id) => this.model.table(id).key),
                this._layoutKey());
        const saved = this.store.load(code, this._signature);
        const pinned = new Map();
        for (const n of spec.nodes) {
            if (saved[n.key]) {
                pinned.set(n.id, saved[n.key]);
            }
        }
        return pinned;
    }

    _moved(tableId, pos) {
        const t = this.model.table(tableId);
        if (t && this._signature) {
            this.store.save(this.model.snapshot.database_code || "db", this._signature, t.key, pos);
            this._drawMinimap();
        }
    }

    resetPositions() {
        if (this._signature) {
            this.store.clear(this.model.snapshot.database_code || "db", this._signature);
        }
        this.refresh({ fit: true });
    }

    // ------------------------------------------------------------------ HUD / perf
    _updateHud() {
        const spec = this.spec;
        if (!spec) {
            return;
        }
        const r = this.renderer.stats();
        this.state.hud = {
            visible: spec.nodes.length,
            total: this.model.tableCount,
            visibleRelations: spec.edges.length,
            totalRelations: this.model.relationCount,
            layout: this._layoutUsed === "layered" || !this._layoutUsed
                ? `ELK layered · ${this.state.layoutDirection === "RIGHT" ? "→" : "↓"}`
                : this._layoutUsed === "organic" ? "ELK stress (organic)" : "Radial",
            hubs: spec.hubs ? spec.hubs.size : 0,
            collapsed: spec.collapsedRelationIds ? spec.collapsedRelationIds.length : 0,
            detail: LOD_LABEL[r.lod],
            density: spec.density,
            truncated: this.selection && this.selection.truncated,
            filteredOut: this.selection ? this.selection.filteredOut : 0,
        };
        Object.assign(this.state.perf, {
            tables: spec.nodes.length,
            rows: spec.rowCount,
            relations: spec.edges.length,
            cells: r.cells,
            mounted: r.mounted,
            svgNodes: r.svgNodes,
            culling: r.culling,
            scale: r.scale,
            lod: LOD_LABEL[r.lod],
            columnsTotal: this.model.stats.columns || 0,
            versions: `JointJS ${JOINT_VERSION} · ELK ${ELK_VERSION}`,
        });
        this._drawMinimap();
    }

    _viewChanged() {
        this.meter.poke();
        if (!this.spec) {
            return;
        }
        const r = this.renderer.stats();
        this.state.hud = { ...this.state.hud, detail: LOD_LABEL[r.lod] };
        if (this.state.showPerf) {
            Object.assign(this.state.perf, { mounted: r.mounted, svgNodes: r.svgNodes, scale: r.scale, lod: LOD_LABEL[r.lod] });
        }
        this._drawMinimap();
    }

    _drawMinimap() {
        if (this.state.showMinimap && this.renderer) {
            this._minimapFrame = this.renderer.drawMinimap(this.minimapRef.el);
        }
    }

    onMinimapClick(ev) {
        const f = this._minimapFrame;
        if (!f) {
            return;
        }
        const rect = ev.currentTarget.getBoundingClientRect();
        const sx = ev.currentTarget.width / rect.width;
        const sy = ev.currentTarget.height / rect.height;
        const x = ((ev.clientX - rect.left) * sx - f.ox) / f.k;
        const y = ((ev.clientY - rect.top) * sy - f.oy) / f.k;
        this.renderer.centerOn(x, y);
    }

    // ------------------------------------------------------------------ selection / emphasis
    selectTable(tableId) {
        const t = this.model.table(tableId);
        if (!t) {
            return;
        }
        this.state.selected = { kind: "table", id: tableId };
        const incoming = this.model.relationsOf(tableId, "incoming", this.view.kinds);
        const outgoing = this.model.relationsOf(tableId, "outgoing", this.view.kinds);
        this.state.inspector = {
            kind: "table",
            id: tableId,
            name: t.name,
            schema: t.schema,
            type: t.type,
            columns: t.columnCount,
            pk: t.pkCount,
            fk: t.fkCount,
            incoming: incoming.length,
            outgoing: outgoing.length,
            comment: t.details && t.details.comment,
            visible: this.renderer.hasTable(tableId),
            collapsed: this.view.collapsed.has(tableId),
            expanded: this.view.expanded.has(tableId),
            isSeed: this.view.seeds.includes(tableId),
            hubCollapsed: (this.spec && (this.spec.nodes.find((n) => n.tableId === tableId) || {}).hubOf) || 0,
            hubExpanded: this.view.hubExpanded.has(tableId),
            ...this._tableDiffInfo(t),
        };
        this._emphasizeTable(tableId, "is-selected");
        this._drawMinimap();
    }

    selectRelation(relId) {
        const rel = this.model.relation(relId);
        if (!rel) {
            return;
        }
        const s = this.model.table(rel.source);
        const t = this.model.table(rel.target);
        this.state.selected = { kind: "relation", id: relId };
        this.state.inspector = {
            kind: "relation",
            id: relId,
            name: rel.name,
            implied: rel.implied,
            source: s.name,
            sourceSchema: s.schema,
            sourceId: s.id,
            target: t.name,
            targetSchema: t.schema,
            targetId: t.id,
            pairs: rel.columns.map((c) => `${s.name}.${c.sourceName} → ${t.name}.${c.targetName}`),
            cardinality: rel.cardinality,
            optional: rel.optional,
            onDeleteCascade: rel.onDeleteCascade,
            onUpdate: rel.onUpdate,
            change: this.model.diff ? rel.change : null,
            ghost: rel.origin === "from",
        };
        this._emphasizeRelation(relId, "is-selected");
        this._drawMinimap();
    }

    /** Diff annotations for the inspector: the table's own changes and its changed columns/objects. */
    _tableDiffInfo(t) {
        if (!this.model.diff) {
            return { change: null };
        }
        const det = t.details;
        const objectChanges = ((det && det.objectChanges) || []).map((o, i) => ({
            key: `${o.key || i}`,
            sign: CHANGE_SIGN[o.change] || "",
            change: o.change,
            label: `${TYPE_LABEL[o.type] || o.type} ${objectLabel(o)}`,
            props: (o.changes || []).map(describeProperty),
        }));
        const columnChanges = ((det && det.columns) || [])
            .filter((c) => c.change && c.change !== "unchanged")
            .map((c) => ({
                key: `c${c.id}`, sign: CHANGE_SIGN[c.change] || "", change: c.change, name: c.name, type: c.type,
                props: c.changes.map(describeProperty),
            }));
        return {
            change: t.change,
            ghost: t.origin === "from",
            childChanges: t.childChanges,
            objectChanges,
            columnChanges,
            detailsLoaded: !!det,
        };
    }

    clearSelection() {
        this.state.selected = null;
        this.state.inspector = null;
        this.renderer && this.renderer.clearEmphasis();
        this._drawMinimap();
    }

    _reapplySelection() {
        const sel = this.state.selected;
        if (sel && sel.kind === "table" && this.model.table(sel.id)) {
            this.selectTable(sel.id);
        } else if (sel && sel.kind === "relation" && this.model.relation(sel.id)) {
            this.selectRelation(sel.id);
        }
    }

    _emphasizeTable(tableId, selfClass) {
        const tables = new Map([[tableId, selfClass]]);
        const relations = new Map();
        const rows = new Map();
        const addRow = (tid, col) => {
            if (!rows.has(tid)) {
                rows.set(tid, new Set());
            }
            rows.get(tid).add(col);
        };
        for (const rid of this.model.relationsOf(tableId, "both", this.view.kinds)) {
            const rel = this.model.relation(rid);
            const out = rel.source === tableId;
            relations.set(rid, out ? "is-out" : "is-in");
            const other = out ? rel.target : rel.source;
            if (other !== tableId) {
                tables.set(other, "is-endpoint");
            }
            for (const c of rel.columns) {
                addRow(rel.source, c.source);
                addRow(rel.target, c.target);
            }
        }
        this.renderer.emphasize({ tables, relations, rows });
    }

    _emphasizeRelation(relId, selfClass) {
        const rel = this.model.relation(relId);
        const rows = new Map([[rel.source, new Set()], [rel.target, new Set()]]);
        for (const c of rel.columns) {
            rows.get(rel.source).add(c.source);
            rows.get(rel.target).add(c.target);
        }
        this.renderer.emphasize({
            tables: new Map([[rel.source, "is-endpoint"], [rel.target, "is-endpoint"]]),
            relations: new Map([[relId, selfClass]]),
            rows,
        });
    }

    _hover(target) {
        if (!this.renderer) {
            return;
        }
        this.meter.poke();
        if (!target) {
            // restore the persistent selection (or nothing)
            const sel = this.state.selected;
            if (sel && sel.kind === "table") {
                this._emphasizeTable(sel.id, "is-selected");
            } else if (sel && sel.kind === "relation") {
                this._emphasizeRelation(sel.id, "is-selected");
            } else {
                this.renderer.clearEmphasis();
            }
            return;
        }
        if (target.table) {
            this._emphasizeTable(target.table, "is-selected");
        } else if (target.relation) {
            this._emphasizeRelation(target.relation, "is-selected");
        }
    }

    // ------------------------------------------------------------------ navigation actions
    exploreFrom(tableId) {
        this.view.scope = "neighborhood";
        this.view.seeds = [tableId];
        this.state.notice = null;
        this.state.selected = { kind: "table", id: tableId };
        this._syncControls();
        this.refresh({ focus: tableId });
    }

    addSeed(tableId) {
        if (!this.view.seeds.includes(tableId)) {
            this.view.seeds = [...this.view.seeds, tableId];
        }
        this.view.scope = "neighborhood";
        this._syncControls();
        this.refresh({ focus: tableId });
    }

    toggleCollapse(tableId) {
        const v = this.view;
        if (v.collapsed.has(tableId)) {
            v.collapsed.delete(tableId);
        } else {
            v.collapsed.add(tableId);
            v.expanded.delete(tableId);
        }
        this.refresh({ focus: tableId });
    }

    toggleExpand(tableId) {
        const v = this.view;
        if (v.expanded.has(tableId)) {
            v.expanded.delete(tableId);
        } else {
            v.expanded.add(tableId);
            v.collapsed.delete(tableId);
        }
        this.refresh({ focus: tableId });
    }

    /** The table page of a table, or of the child table of a relation, in the snapshot it was drawn from. */
    openRecord(kind, id) {
        const tableId = kind === "relation" ? (this.model.relation(id) || {}).source : id;
        const table = this.model.table(tableId);
        if (!table) {
            return;
        }
        const d = this.model.diff;
        const snapshot = d ? (table.origin === "from" ? d.from.id : d.to.id) : this.model.snapshot.id;
        window.open(this.api.url("table", { snapshot, table: tableId }) + (kind === "relation" ? "#relationships" : ""), "_blank");
    }

    // ------------------------------------------------------------------ controls
    setDepth(d) {
        this.view.depth = d;
        this._syncControls();
        this.refresh({ fit: true });
    }

    setDirection(d) {
        this.view.direction = d;
        this._syncControls();
        this.refresh({ fit: true });
    }

    setKinds(k) {
        this.view.kinds = k;
        this._syncControls();
        this.refresh({ fit: true });
    }

    setDensity(d) {
        this.view.density = d;
        this._syncControls();
        this.refresh();
    }

    setConnectivity(c) {
        this.view.connectivity = c;
        this._syncControls();
        this.refresh({ fit: true });
    }

    setSchema(ev) {
        const value = ev.target.value;
        this.view.schemas = value ? new Set([value]) : null;
        this._syncControls();
        this.refresh({ fit: true });
    }

    toggleType(type) {
        this.view.types = { ...this.view.types, [type]: !this.view.types[type] };
        if (!this.view.types.table && !this.view.types.view) {
            this.view.types[type === "table" ? "view" : "table"] = true;
        }
        this._syncControls();
        this.refresh({ fit: true });
    }

    setLayoutAlgorithm(a) {
        if (!ALGORITHMS.includes(a)) {
            return;
        }
        this.state.layoutAlgorithm = a;
        this._saveLayoutPrefs();
        this.refresh({ fit: true });
    }

    _saveLayoutPrefs() {
        try {
            window.localStorage.setItem(LAYOUT_PREFS_KEY,
                JSON.stringify({ algorithm: this.state.layoutAlgorithm, hubThreshold: this.view.hubThreshold }));
        } catch {
            // private mode / blocked storage: the choice just is not remembered
        }
    }

    algorithmLabel(a) {
        return ALGORITHM_LABEL[a] || a;
    }

    setHubThreshold(t) {
        if (!HUB_CHOICES.includes(t)) {
            return;
        }
        this.view.hubThreshold = t;
        this.state.hubThreshold = t;
        this._saveLayoutPrefs();
        this.refresh({ fit: true });
    }

    /** Draw (or collapse again) the relations of one hub table. */
    toggleHub(tableId) {
        const set = this.view.hubExpanded;
        if (set.has(tableId)) {
            set.delete(tableId);
        } else {
            set.add(tableId);
        }
        this.refresh({ focus: tableId });
    }

    setLayoutDirection(d) {
        this.state.layoutDirection = d;
        this.refresh({ fit: true });
    }

    relayout() {
        this.refresh({ fit: true });
    }

    showNeighborhood() {
        if (!this.view.seeds.length) {
            const sel = this.state.selected;
            const hub = sel && sel.kind === "table" ? sel.id : this.model.hubs(1, this.view.kinds)[0];
            this.view.seeds = hub ? [hub] : [];
        }
        this.view.scope = "neighborhood";
        this._syncControls();
        this.refresh({ fit: true });
    }

    requestShowAll() {
        if (this.model.tableCount > AUTO_ALL_MAX && !this.state.confirmAll) {
            this.state.confirmAll = true;
            return;
        }
        this.confirmShowAll();
    }

    confirmShowAll() {
        this.state.confirmAll = false;
        this.state.notice = null;
        this.view.scope = "all";
        this._syncControls();
        this.refresh({ fit: true });
    }

    cancelShowAll() {
        this.state.confirmAll = false;
    }

    dismissNotice() {
        this.state.notice = null;
    }

    estimateAllDensity() {
        return resolveDensity(this.view, this.model ? this.model.tableCount : 0);
    }

    fit() {
        this.renderer && this.renderer.fit();
    }

    zoomIn() {
        this.renderer && this.renderer.zoomBy(1.25);
    }

    zoomOut() {
        this.renderer && this.renderer.zoomBy(1 / 1.25);
    }

    toggleFullscreen() {
        const el = this.rootRef.el;
        this.state.fullscreen = !this.state.fullscreen;
        if (this.state.fullscreen && el.requestFullscreen) {
            el.requestFullscreen().catch(() => {});
        } else if (!this.state.fullscreen && document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
        setTimeout(() => this.fit(), 150);
    }

    togglePerf() {
        this.state.showPerf = !this.state.showPerf;
        if (this.state.showPerf) {
            this._updateHud();
        }
    }

    toggleMinimap() {
        this.state.showMinimap = !this.state.showMinimap;
        setTimeout(() => this._drawMinimap(), 0);
    }

    _keydown(ev) {
        if (ev.target && ["INPUT", "TEXTAREA", "SELECT"].includes(ev.target.tagName)) {
            if (ev.key === "Escape") {
                this.state.results = [];
            }
            return;
        }
        if (ev.key === "Escape") {
            this.clearSelection();
        } else if (ev.key === "/") {
            ev.preventDefault();
            this.searchRef.el && this.searchRef.el.focus();
        } else if (ev.key === "f" || ev.key === "F") {
            this.fit();
        }
    }

    // ------------------------------------------------------------------ search
    onSearchInput(ev) {
        const q = ev.target.value;
        this.state.query = q;
        // Instant: table / schema names from the in-memory index.
        this.state.results = this.model.searchLocal(q, 12).map((r) => ({ ...r, key: `l${r.tableId}` }));
        clearTimeout(this._searchTimer);
        if (q.trim().length < 2 || this.diffPair) {
            return; // diff mode: union ids are not one snapshot's ids, so only the local index is searched
        }
        // Debounced: server-side search adds COLUMN-name matches (column names are not in the index).
        this._searchTimer = setTimeout(async () => {
            this.state.searching = true;
            try {
                const hits = await this.api.search(this.model.snapshot.id, q, 30);
                if (this.state.query !== q) {
                    return;
                }
                const seen = new Set(this.state.results.map((r) => `${r.tableId}:${r.kind}`));
                const merged = [...this.state.results];
                for (const h of hits) {
                    const k = `${h.table_id}:${h.kind}`;
                    if (!seen.has(k) && this.model.table(h.table_id)) {
                        seen.add(k);
                        merged.push({ tableId: h.table_id, kind: h.kind, text: h.text, key: `s${k}` });
                    }
                }
                this.state.results = merged.slice(0, 30);
            } finally {
                this.state.searching = false;
            }
        }, SEARCH_DEBOUNCE);
    }

    onSearchKeydown(ev) {
        if (ev.key === "Enter" && this.state.results.length) {
            this.pickResult(this.state.results[0]);
        }
    }

    pickResult(result) {
        this.state.results = [];
        this.state.query = "";
        if (this.searchRef.el) {
            this.searchRef.el.value = "";
        }
        const tid = result.tableId;
        if (this.renderer.hasTable(tid)) {
            this.renderer.focusTable(tid);
            this.selectTable(tid);
        } else {
            this.exploreFrom(tid);
        }
    }

    // ------------------------------------------------------------------ graphics quality
    /** preset -> settings -> RenderPolicy. The only place the controller turns a preset into a policy. */
    _resolveGraphics() {
        const g = this.state.gfx;
        const level = g.preset === "auto" ? (this.auto.level || "balanced") : g.preset;
        const settings = settingsFor(g.preset, { custom: g.custom, autoLevel: level });
        this.policy = resolvePolicy(settings);
        Object.assign(g, {
            level, settings, background: settings.background, minimap: settings.minimap,
            autoAction: g.preset === "auto" ? this.auto.lastAction : "",
        });
        if (this.renderer) {
            this.renderer.setPolicy(this.policy);
            this._drawMinimap();
        }
        return this.policy;
    }

    /** User-initiated change: persist, apply, and relayout when the layout geometry differs. */
    _applyGraphicsChange() {
        this.gfxStore.save({ preset: this.state.gfx.preset, custom: this.state.gfx.custom });
        this._resolveGraphics();
        if (this.renderer && geometryKey(this.policy) !== this._geometryApplied) {
            this.refresh();
        }
        this._updateHud();
    }

    setGraphicsPreset(ev) {
        const preset = ev.target.value;
        if (!PRESET_CHOICES.includes(preset)) {
            return;
        }
        const g = this.state.gfx;
        if (preset === "custom" && !g.custom) {
            g.custom = { ...g.settings }; // start CUSTOM from what is on screen now
        }
        g.preset = preset;
        g.panel = preset === "custom";
        if (preset === "auto") {
            this.auto = new AutoQuality(); // AUTO re-evaluates from scratch
            if (this.spec) {
                this.auto.setComplexity({ nodes: this.spec.nodes.length, cells: this.renderer.stats().cells },
                    performance.now());
            }
        }
        this._applyGraphicsChange();
    }

    toggleGraphicsPanel() {
        const g = this.state.gfx;
        if (g.preset !== "custom") {
            g.custom = { ...g.settings };
            g.preset = "custom";
            g.panel = true;
            this._applyGraphicsChange();
        } else {
            g.panel = !g.panel;
        }
    }

    setGraphicsKnob(key, value) {
        const g = this.state.gfx;
        g.custom = { ...(g.custom || g.settings), [key]: value };
        g.preset = "custom";
        this._applyGraphicsChange();
    }

    knobOptions(key) {
        return KNOB_OPTIONS[key] || [];
    }

    levelLabel(level) {
        return LEVEL_LABEL[level] || level;
    }

    /** rAF frame interval while the user interacts; feeds AUTO and the FPS readout. */
    _onFrame(ms, t) {
        this._frames = (this._frames || 0) + 1;
        if (ms > 0 && ms < 250) {
            this._emaMs = this._emaMs ? this._emaMs + 0.1 * (ms - this._emaMs) : ms;
        }
        if (this.state.gfx.preset === "auto" && this.auto.addFrame(ms, t)) {
            this._resolveGraphics();
            this._updateHud();
        }
        if (this._frames % 30 === 0) {
            this.state.gfx.fps = this._emaMs ? Math.round(1000 / this._emaMs) : null;
        }
    }

    // ------------------------------------------------------------------ template helpers
    fmt(n) {
        return typeof n === "number" ? n.toLocaleString() : n;
    }

    ms(v) {
        return typeof v === "number" ? `${v.toFixed(v < 10 ? 1 : 0)} ms` : "—";
    }
}


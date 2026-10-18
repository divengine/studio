/* Graph adapter — turns (SchemaModel + exploration state) into a renderer-neutral GraphSpec.
 *
 * Pure JavaScript: no Drupal, no JointJS, no ELK. This is the ONLY place that decides
 *   - WHICH tables are visible (scope: FK neighborhood of seeds, or all; filters),
 *   - HOW MUCH of each table is shown (layout density: compact / structure / full),
 *   - the GEOMETRY every downstream stage agrees on (node size, row y, column port positions).
 * ELK lays out exactly these boxes/ports; JointJS draws inside exactly these boxes. Because geometry is
 * fixed here, zoom-level detail (LOD) is a pure rendering concern and never forces a relayout.
 *
 * GraphSpec = {
 *   nodes: [{id, tableId, name, schema, type, density, width, height, rows[], ports[], counts, pending}],
 *   edges: [{id, relId, source, sourcePort?, target, targetPort?, implied, selfLoop}],
 *   density, tableIds: Set<number>, relationIds: number[]
 * }
 */

export const GEOMETRY = Object.freeze({
    HEADER: 40,        // schema line + table name
    ROW: 20,           // one column row
    META: 22,          // compact footer line (counts)
    PAD: 6,            // bottom padding
    MIN_W: 200,
    MAX_W: 380,
    CHAR_TITLE: 7.6,   // approx. px per char, 13px sans bold
    CHAR_ROW: 7.0,     // approx. px per char, 12px monospace
    MAX_ROWS: 80,      // full density: rows beyond this collapse into "+N more"
});

export const DENSITIES = ["compact", "structure", "full"];

// Auto-density thresholds by number of visible tables. Tuned so the SVG node count stays bounded:
// full detail only for small neighborhoods, key columns for medium sets, names only beyond that.
export const AUTO_DENSITY = Object.freeze({ full: 60, structure: 250 });

export const HUB_DEFAULT = 12;
export const HUB_CHOICES = Object.freeze([0, 6, 12, 25, 50]);

/**
 * Hub tables of the whole snapshot: referenced by >= hubThreshold relations of the active kinds. A hub is a
 * property of the table (not of the current view), so the same table is a hub in every view; the exploration
 * seeds (neighborhood scope) and hubs the user expanded are exempt.
 * @returns {Set<number>}
 */
export function hubTables(model, state) {
    const threshold = state.hubThreshold || 0;
    const hubs = new Set();
    if (!threshold) {
        return hubs;
    }
    const exempt = new Set(state.hubExpanded || []);
    if (state.scope === "neighborhood") {
        for (const id of state.seeds || []) {
            exempt.add(id); // exploring FROM a hub means wanting to see its relations
        }
    }
    for (const id of model.tables.keys()) {
        if (exempt.has(id)) {
            continue;
        }
        let n = 0;
        for (const rid of model.incoming.get(id) || []) {
            const r = model.relation(rid);
            if (r.source !== r.target && model.relationAllowed(r, state.kinds)) {
                n++;
            }
        }
        if (n >= threshold) {
            hubs.add(id);
        }
    }
    return hubs;
}

/**
 * Split visible relations into drawn edges and hub-collapsed ones (relations INTO a hub). A CHANGED relation
 * (diff mode) is never collapsed.
 * @returns {{drawn: object[], collapsed: object[], hubs: Map<number, number>}} hubs = hub id -> collapsed count
 */
export function collapseHubs(relations, hubIds) {
    const hubs = new Map();
    const drawn = [];
    const collapsed = [];
    for (const r of relations) {
        const diffFact = r.change && r.change !== "unchanged";
        if (hubIds.has(r.target) && r.source !== r.target && !diffFact) {
            collapsed.push(r);
            hubs.set(r.target, (hubs.get(r.target) || 0) + 1);
        } else {
            drawn.push(r);
        }
    }
    return { drawn, collapsed, hubs };
}

export function defaultViewState() {
    return {
        scope: "neighborhood",      // 'neighborhood' | 'all'
        seeds: [],                  // table ids the neighborhood grows from
        depth: 1,                   // 1..3
        direction: "both",          // 'both' | 'outgoing' | 'incoming'
        kinds: "both",              // 'real' | 'implied' | 'both'
        schemas: null,              // null = every schema, else Set<string>
        types: { table: true, view: true },
        connectivity: "any",        // 'any' | 'connected' | 'isolated'
        density: "auto",            // 'auto' | 'compact' | 'structure' | 'full'
        collapsed: new Set(),       // table ids forced to compact
        expanded: new Set(),        // table ids forced to full
        maxTables: 5000,            // neighborhood safety bound (reported, not silent)
        // Hub collapsing: a table referenced by >= hubThreshold visible relations is a "hub"; those
        // relations are not drawn as edges (they would cross the whole drawing) but summarised as a note on
        // each child ("↗ users") and on the hub ("← 126 references"). 0 = off. Exploration seeds and
        // hubs the user expanded are exempt; a CHANGED relation (diff mode) is never collapsed.
        hubThreshold: HUB_DEFAULT,
        hubExpanded: new Set(),
        // Layout-detail thresholds from the graphics RenderPolicy (policy.geometry). They change how much
        // of each table is DRAWN (with "+N more" / counts), never which tables or relations exist.
        detail: { full: AUTO_DENSITY.full, structure: AUTO_DENSITY.structure, maxRows: GEOMETRY.MAX_ROWS },
    };
}

/**
 * Decide which tables are visible.
 * @returns {{ids: Set<number>, depthOf: Map<number, number>, truncated: boolean, filteredOut: number}}
 */
export function selectTables(model, state) {
    let ids;
    let depthOf = new Map();
    let truncated = false;
    if (state.scope === "all" || !state.seeds.length) {
        ids = new Set(model.tables.keys());
    } else {
        // Never walk THROUGH a hub: one hop into a table like `locks` would pull in every table referencing it.
        const nb = model.neighborhood(state.seeds, {
            depth: state.depth, direction: state.direction, kinds: state.kinds,
            maxTables: state.maxTables, skip: hubTables(model, state),
        });
        ids = nb.ids;
        depthOf = nb.depthOf;
        truncated = nb.truncated;
    }
    const seeds = new Set(state.seeds);
    const before = ids.size;
    const out = new Set();
    for (const id of ids) {
        const t = model.table(id);
        if (seeds.has(id)) {
            out.add(id); // a seed is never filtered away — it is what the user asked to see
            continue;
        }
        if (state.schemas && !state.schemas.has(t.schema)) {
            continue;
        }
        if (!state.types[t.type]) {
            continue;
        }
        if (state.connectivity !== "any") {
            const isolated = model.isIsolated(id, state.kinds);
            if (state.connectivity === "connected" && isolated) {
                continue;
            }
            if (state.connectivity === "isolated" && !isolated) {
                continue;
            }
        }
        out.add(id);
    }
    return { ids: out, depthOf, truncated, filteredOut: before - out.size };
}

export function resolveDensity(state, visibleCount) {
    if (state.density !== "auto") {
        return state.density;
    }
    const t = state.detail || AUTO_DENSITY;
    if (visibleCount <= t.full) {
        return "full";
    }
    if (visibleCount <= t.structure) {
        return "structure";
    }
    return "compact";
}

/** Which densities need column rows loaded (the controller fetches them before building). */
export function tablesNeedingDetails(model, ids, state) {
    const global = resolveDensity(state, ids.size);
    const need = [];
    for (const id of ids) {
        const d = nodeDensity(state, global, id);
        if (d !== "compact" && !model.hasDetails(id)) {
            need.push(id);
        }
    }
    return need;
}

function nodeDensity(state, global, id) {
    if (state.collapsed.has(id)) {
        return "compact";
    }
    if (state.expanded.has(id)) {
        return "full";
    }
    return global;
}

function clampWidth(px) {
    return Math.round(Math.max(GEOMETRY.MIN_W, Math.min(GEOMETRY.MAX_W, px)));
}

function titleWidth(t) {
    const label = Math.max((t.schema || "").length * 0.8, t.name.length);
    return label * GEOMETRY.CHAR_TITLE + 84; // + type badge + padding
}

function rowWidth(c) {
    return (c.name.length + (c.type || "").length) * GEOMETRY.CHAR_ROW + 78; // key badge, gap, port
}

/**
 * Build the renderer-neutral graph for the selected tables.
 * @param {SchemaModel} model
 * @param {Set<number>} ids visible table ids
 * @param {object} state view state
 */
export function buildGraphSpec(model, ids, state) {
    const density = resolveDensity(state, ids.size);
    const hubIds = hubTables(model, state);
    const { drawn: relations, collapsed, hubs } = collapseHubs(model.relationsWithin(ids, state.kinds), hubIds);
    // Hub notes describe the TABLE, visible hub or not: child -> Map(hub id -> relation count), and for a hub
    // its total incoming references (active kinds). Changed relations (diff) are drawn, so not counted.
    const hubRefs = new Map();
    const hubTotal = new Map();
    if (hubIds.size) {
        for (const id of ids) {
            for (const rid of model.relationsOf(id, "outgoing", state.kinds)) {
                const r = model.relation(rid);
                if (hubIds.has(r.target) && r.target !== id && !(r.change && r.change !== "unchanged")) {
                    if (!hubRefs.has(id)) {
                        hubRefs.set(id, new Map());
                    }
                    hubRefs.get(id).set(r.target, (hubRefs.get(id).get(r.target) || 0) + 1);
                }
            }
            if (hubIds.has(id)) {
                hubTotal.set(id, model.relationsOf(id, "incoming", state.kinds)
                    .filter((rid) => model.relation(rid).source !== id).length);
            }
        }
    }

    // Columns that must stay visible in "structure" density because a visible relation targets them
    // (usually the parent PK, but an FK may reference a UNIQUE non-PK column).
    const referenced = new Set();
    for (const rel of relations) {
        for (const c of rel.columns) {
            if (c.target) {
                referenced.add(c.target);
            }
            if (c.source) {
                referenced.add(c.source);
            }
        }
    }

    const nodes = [];
    const nodeById = new Map();
    for (const id of ids) {
        const t = model.table(id);
        let d = nodeDensity(state, density, id);
        const pending = d !== "compact" && !t.details;
        if (pending) {
            d = "compact"; // honest fallback until the rows arrive — never invent columns
        }
        const node = {
            id: `t${id}`,
            tableId: id,
            key: t.key,
            name: t.name,
            schema: t.schema,
            type: t.type,
            density: d,
            pending,
            counts: { columns: t.columnCount, pk: t.pkCount, fk: t.fkCount },
            change: t.change,              // diff mode (null otherwise)
            childChanges: t.childChanges,
            rows: [],
            ports: [],
            width: 0,
            height: 0,
            _rowIndex: new Map(), // colId -> row index (internal, for ports)
        };
        let width = titleWidth(t);
        if (d === "compact") {
            node.height = GEOMETRY.HEADER + GEOMETRY.META;
        } else {
            const cols = t.details.columns;
            let shown;
            if (d === "full") {
                const cap = (state.detail && state.detail.maxRows) || GEOMETRY.MAX_ROWS;
                // Changed columns (diff mode) are never collapsed into "+N more", whatever the quality.
                shown = cols.filter((c, i) => i < cap || (c.change && c.change !== "unchanged"));
            } else {
                // Structure: key columns + referenced columns + (diff mode) every CHANGED column, so a
                // structural change is always visible at this level.
                shown = cols.filter((c) => c.pk || c.fk || c.impliedFk || referenced.has(c.id)
                    || (c.change && c.change !== "unchanged"));
            }
            for (const c of shown) {
                node._rowIndex.set(c.id, node.rows.length);
                node.rows.push({
                    kind: "column", colId: c.id, name: c.name, type: c.type,
                    pk: c.pk, fk: c.fk, impliedFk: c.impliedFk, nullable: c.nullable,
                    unique: c.unique, default: c.default, hasDefault: c.hasDefault,
                    autoIncrement: c.autoIncrement, comment: c.comment,
                    change: c.change, changes: c.changes,
                });
                width = Math.max(width, rowWidth(c));
            }
            const hidden = cols.length - shown.length;
            if (hidden > 0) {
                node.rows.push({ kind: "more", hidden });
            }
            node.height = GEOMETRY.HEADER + node.rows.length * GEOMETRY.ROW + GEOMETRY.PAD;
        }
        // Hub note: one extra line summarising collapsed relations (child side or hub side).
        const refs = hubRefs.get(id);
        if (refs || hubTotal.has(id)) {
            const parts = [];
            if (hubTotal.has(id)) {
                parts.push(`← ${hubTotal.get(id)} reference${hubTotal.get(id) === 1 ? "" : "s"} (hub)`);
            }
            if (refs) {
                parts.push(`↗ ${[...refs].map(([hid, n]) => `${model.table(hid).name}${n > 1 ? ` ×${n}` : ""}`).join(", ")}`);
            }
            node.hubNote = parts.join("  ");
            node.hubOf = hubTotal.get(id) || 0;
            node.hubTargets = refs ? [...refs.keys()] : [];
            node.height += GEOMETRY.ROW;
        }
        node.width = clampWidth(width);
        nodes.push(node);
        nodeById.set(id, node);
    }

    const edges = [];
    const portSeen = new Set();
    const addPort = (node, colId, side) => {
        const idx = node._rowIndex.get(colId);
        if (idx === undefined) {
            return undefined; // row not shown -> the edge attaches to the table box
        }
        const portId = `c${colId}:${side === "out" ? "o" : "i"}`;
        if (!portSeen.has(`${node.id}/${portId}`)) {
            portSeen.add(`${node.id}/${portId}`);
            node.ports.push({
                id: portId, colId, side,
                x: side === "out" ? node.width : 0,
                y: GEOMETRY.HEADER + idx * GEOMETRY.ROW + GEOMETRY.ROW / 2,
            });
        }
        return portId;
    };
    for (const rel of relations) {
        const src = nodeById.get(rel.source);
        const tgt = nodeById.get(rel.target);
        const pair = rel.columns[0];
        edges.push({
            id: `r${rel.id}`,
            relId: rel.id,
            source: src.id,
            sourcePort: pair ? addPort(src, pair.source, "out") : undefined,
            target: tgt.id,
            targetPort: pair ? addPort(tgt, pair.target, "in") : undefined,
            implied: rel.implied,
            selfLoop: rel.source === rel.target,
            change: rel.change,            // diff mode (null otherwise)
        });
    }
    for (const n of nodes) {
        delete n._rowIndex;
    }
    return {
        nodes, edges, density,
        tableIds: ids,
        relationIds: relations.map((r) => r.id),
        collapsedRelationIds: collapsed.map((r) => r.id),
        hubs,
        rowCount: nodes.reduce((s, n) => s + n.rows.length, 0),
    };
}

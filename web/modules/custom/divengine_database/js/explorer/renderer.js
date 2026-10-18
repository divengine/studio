/* JointJS renderer — draws a laid-out GraphSpec and handles direct manipulation. Knows JointJS (core,
 * open source, MPL-2.0); knows nothing about Drupal, SQL or ELK internals. Replaceable: it consumes
 * (GraphSpec, layout) and emits semantic callbacks (table/relation selected, moved, ...).
 *
 * Scalability mechanisms (JointJS core):
 *   - async paper + frozen during bulk changes (one resetCells batch, then unfreeze)
 *   - viewManagement {lazyInitialize, disposeHidden} + cellVisibility viewport culling: a cell gets a
 *     view and SVG only while it intersects the (padded) viewport; everything else stays a plain model.
 *     autoFreeze stops the render loop when idle.
 *   - column "ports" are drawn in the table markup and links anchor with model-only offsets
 *     (topLeft + dx/dy of the column row), so no link ever needs to measure a DOM node of a table that
 *     is culled, collapsed or re-rendered at another LOD.
 *   - visuals are baked into per-instance JSON markup (no per-row attrs processing) and regenerated
 *     only when the zoom LOD changes; unmounted views re-render lazily when they scroll into view
 *   - emphasis is a CSS class toggle; the only filter (glow) is applied to the single selected table
 */
import { GEOMETRY } from "./graph_adapter.js";
import { LOD, lodForScale } from "./lod.js";
import { PRESETS, resolvePolicy } from "./graphics_policy.js";

export const JOINT_VERSION = "4.3.3";

const SCALE_MIN = 0.02;
const SCALE_MAX = 2.5;
const TITLE_PX = 7.6;
const ROW_PX = 7.0;
const TYPE_PX = 6.4;

// ER end markers (crow's foot). Source = child ("many" unless 1:1), target = parent ("one").
const MARK_MANY = "M 0 -6 L 12 0 L 0 6 M 0 0 L 12 0";
const MARK_ONE = "M 8 -6 L 8 6";
const MARK_ONE_MANDATORY = "M 6 -6 L 6 6 M 11 -6 L 11 6";
// Fallback router once ELK's route no longer applies (manual move / pinned table).
const RIGHT_ANGLE = Object.freeze({ name: "rightAngle", args: { useModelGeometry: true } });

const MARK_ONE_OPTIONAL = "M 6 -6 L 6 6 M 18 0 a 3.5 3.5 0 1 0 -7 0 a 3.5 3.5 0 1 0 7 0";

export function truncate(text, maxChars) {
    const s = String(text == null ? "" : text);
    if (maxChars <= 1) {
        return "…";
    }
    return s.length > maxChars ? `${s.slice(0, Math.max(1, maxChars - 1))}…` : s;
}

function txt(className, x, y, text, extra = {}) {
    return {
        tagName: "text",
        className,
        attributes: { x, y, ...extra },
        textContent: text,
    };
}

function keyBadge(row) {
    if (row.pk) {
        return "PK";
    }
    if (row.fk) {
        return "FK";
    }
    if (row.impliedFk) {
        return "fk?";
    }
    if (row.unique === "unique") {
        return "UQ";
    }
    return "";
}

function rowTooltip(row) {
    const parts = [`${row.name} : ${row.type}`, row.nullable ? "NULL" : "NOT NULL"];
    if (row.pk) {
        parts.push(`primary key #${row.pk}`);
    }
    if (row.fk) {
        parts.push("foreign key");
    }
    if (row.impliedFk) {
        parts.push("implied relationship (heuristic, not a DB FK)");
    }
    if (row.unique === "unique") {
        parts.push("unique");
    } else if (row.unique === "part") {
        parts.push("part of a composite unique index");
    }
    if (row.hasDefault) {
        parts.push(`default ${row.default === null ? "NULL" : JSON.stringify(row.default)}`);
    }
    if (row.autoIncrement) {
        parts.push("auto_increment");
    }
    if (row.comment) {
        parts.push(`— ${row.comment}`);
    }
    if (row.change && row.change !== "unchanged") {
        parts.unshift(`[${row.change.toUpperCase()}]`);
        for (const c of row.changes || []) {
            parts.push(`${c.property}: ${diffValue(c.before)} → ${diffValue(c.after)}`);
        }
    }
    return parts.join(" · ");
}

// ---- schema-diff overlay (the renderer only DISPLAYS change annotations; it never computes them) ----
const MINIMAP_DIFF = Object.freeze({
    added: "rgba(110, 231, 183, 0.95)", removed: "rgba(255, 110, 140, 0.9)", modified: "rgba(255, 200, 87, 0.95)",
});
const DIFF_GLYPH = Object.freeze({ added: "+", removed: "−", modified: "~" });
const DIFF_BADGE = Object.freeze({ added: "ADDED", removed: "REMOVED", modified: "CHANGED" });

function diffValue(v) {
    if (v && typeof v === "object" && "has" in v) {
        return v.has ? `DEFAULT ${v.value === null ? "NULL" : v.value}` : "no default";
    }
    return v === null || v === undefined ? "∅" : String(v);
}

function isChanged(change) {
    return !!change && change !== "unchanged";
}

/**
 * JSON markup for one table at one LOD. Pure (exported for tests). Geometry comes from the node
 * (fixed by the adapter); LOD only changes what is drawn inside the box.
 */
const DEFAULT_MARKUP = Object.freeze({ structureBars: true, rowTooltips: true, columnTypes: true });

/**
 * `opts` = policy.markup (graphics quality): placeholder bars at STRUCTURE LOD, per-row tooltips and the
 * type column at DETAIL LOD. They only change how much SVG is drawn — never which columns exist.
 */
export function buildTableMarkup(node, lod, opts = DEFAULT_MARKUP) {
    const W = node.width;
    const H = node.height;
    const HEAD = GEOMETRY.HEADER;
    const isView = node.type === "view";
    const m = [
        {
            tagName: "rect",
            className: `ci-body${isView ? " ci-body-view" : ""}${node.change ? ` ci-body-diff-${node.change}` : ""}`,
            attributes: { width: W, height: H, rx: 7, ry: 7 },
            children: [{ tagName: "title", textContent: `${node.schema ? node.schema + "." : ""}${node.name} (${node.type})` }],
        },
    ];
    if (lod === LOD.LANDSCAPE) {
        // Minimal SVG per table (body + name): thousands of tables stay cheap. Large type, centred.
        const chars = Math.floor((W - 20) / (TITLE_PX * 1.7));
        // The change glyph survives every LOD and every graphics quality (diff semantics never disappear).
        const glyph = isChanged(node.change) ? `${DIFF_GLYPH[node.change]} ` : "";
        m.push(txt(`ci-title ci-title-lg${glyph ? ` ci-title-diff-${node.change}` : ""}`, 10,
            Math.min(H / 2 + 8, HEAD + 14), truncate(glyph + node.name, chars)));
        return m;
    }
    m.push(
        {
            tagName: "path",
            className: "ci-head",
            attributes: { d: `M7,0 H${W - 7} Q${W},0 ${W},7 V${HEAD} H0 V7 Q0,0 7,0 Z` },
        },
        { tagName: "rect", className: "ci-accent", attributes: { x: 0, y: HEAD - 1.5, width: W, height: 1.5 } },
    );
    const titleChars = Math.floor((W - 70) / TITLE_PX);
    // The schema line shares the header's top row with the right-aligned badge: budget its width so a long
    // schema never runs under TABLE / VIEW / ADDED / REMOVED / CHANGED (uppercase, letter-spaced glyphs).
    const badgeText = isChanged(node.change) ? DIFF_BADGE[node.change] : (isView ? "VIEW" : "TABLE");
    const schemaChars = Math.max(4, Math.floor((W - 12 - 10 - badgeText.length * 7.6 - 12) / 7.4));
    m.push(txt("ci-schema", 12, 15, truncate(node.schema || "—", schemaChars)));
    m.push(txt("ci-title", 12, 32, truncate((isChanged(node.change) ? `${DIFF_GLYPH[node.change]} ` : "") + node.name, titleChars)));
    if (isChanged(node.change)) {
        m.push(txt(`ci-badge ci-badge-diff-${node.change}`, W - 10, 15, DIFF_BADGE[node.change], { "text-anchor": "end" }));
    } else {
        m.push(txt(`ci-badge${isView ? " ci-badge-view" : ""}`, W - 10, 15, isView ? "VIEW" : "TABLE",
            { "text-anchor": "end" }));
    }

    if (node.density === "compact" || !node.rows.length) {
        const meta = node.pending
            ? "loading columns…"
            : `${node.counts.columns} cols · ${node.counts.pk} PK · ${node.counts.fk} FK`;
        m.push(txt("ci-meta", 12, HEAD + 15, meta));
        pushHubNote(m, node);
        return m;
    }

    const rows = [];
    node.rows.forEach((row, i) => {
        const y0 = HEAD + i * GEOMETRY.ROW;
        const base = y0 + 14;
        if (row.kind === "more") {
            rows.push(txt("ci-more", 34, base, `+${row.hidden} more column${row.hidden === 1 ? "" : "s"}`));
            return;
        }
        const badge = keyBadge(row);
        // Changed rows (diff mode) are drawn like key rows: text at STRUCTURE LOD, never a placeholder bar.
        const keyRow = !!(row.pk || row.fk || row.impliedFk || isChanged(row.change));
        const cls = ["ci-row"];
        if (isChanged(row.change)) {
            cls.push(`ci-row-diff-${row.change}`);
        }
        if (row.pk) {
            cls.push("ci-row-pk");
        }
        if (row.fk || row.impliedFk) {
            cls.push("ci-row-fk");
        }
        if (lod === LOD.STRUCTURE && !keyRow) {
            if (!opts.structureBars) {
                return; // cheapest: key columns only (the row slot stays; counts are in the header)
            }
            // Faint bar instead of text: shows the table's shape without the DOM cost of text.
            rows.push({
                tagName: "rect", className: "ci-bar",
                attributes: { x: 34, y: y0 + 8, height: 4, rx: 2,
                    width: Math.min(W - 60, 16 + row.name.length * 4.2), "data-col": row.colId },
            });
            return;
        }
        const children = [
            { tagName: "rect", className: "ci-row-bg", attributes: { x: 1, y: y0, width: W - 2, height: GEOMETRY.ROW } },
        ];
        if (isChanged(row.change)) {
            // Left change marker: a diff fact, drawn at every quality level.
            children.push({ tagName: "rect", className: "ci-row-mark", attributes: { x: 1, y: y0 + 2, width: 3, height: GEOMETRY.ROW - 4 } });
        }
        if (badge) {
            children.push(txt(`ci-key ci-key-${badge === "fk?" ? "implied" : badge.toLowerCase()}`, 10, base, badge));
        }
        if (lod === LOD.DETAIL) {
            const typeChars = opts.columnTypes ? Math.min(row.type.length, 16) : 0;
            const nameChars = Math.floor((W - 34 - typeChars * TYPE_PX - 22) / ROW_PX);
            children.push(txt(`ci-col${row.nullable ? " ci-nullable" : ""}`, 34, base, truncate(row.name, nameChars)));
            const typeChange = (row.changes || []).find((c) => c.property === "type");
            if (typeChange) {
                // Changed datatype is a diff FACT: shown even when column types are turned off.
                children.push(txt("ci-type ci-type-diff", W - 14, base,
                    truncate(`${typeChange.before}→${typeChange.after}`.toLowerCase(), 28), { "text-anchor": "end" }));
            } else if (opts.columnTypes) {
                children.push(txt("ci-type", W - 14, base, truncate(row.type, 16), { "text-anchor": "end" }));
            }
            if (opts.rowTooltips) {
                children.push({ tagName: "title", textContent: rowTooltip(row) });
            }
        } else {
            const nameChars = Math.floor((W - 48) / ROW_PX);
            children.push(txt("ci-col", 34, base, truncate(row.name, nameChars)));
        }
        rows.push({ tagName: "g", className: cls.join(" "), attributes: { "data-col": row.colId }, children });
    });
    m.push({ tagName: "g", className: "ci-rows", children: rows });
    // Column connection points (visual only — links anchor by model offsets, see render()).
    for (const p of node.ports) {
        m.push({ tagName: "circle", className: `ci-port ci-port-${p.side}`,
            attributes: { cx: p.x, cy: p.y, r: 3.5, "data-col": p.colId } });
    }
    pushHubNote(m, node);
    return m;
}

/** Hub note (collapsed hub relations), last line of the box; the adapter reserved its row. */
function pushHubNote(m, node) {
    if (!node.hubNote) {
        return;
    }
    const chars = Math.floor((node.width - 24) / TYPE_PX);
    m.push({
        tagName: "g", className: "ci-hubnote-g",
        children: [
            { tagName: "title", textContent: `${node.hubNote} — hub relations are summarised, not drawn (HUBS setting)` },
            txt(`ci-hubnote${node.hubOf ? " ci-hubnote-hub" : ""}`, 12, node.height - 8, truncate(node.hubNote, chars)),
        ],
    });
}

function defineShapes(joint) {
    const Table = joint.dia.Element.define("ci.Table", { z: 2 }, { markup: [] });
    const Relation = joint.dia.Link.define("ci.Relation", {
        z: 1,
        attrs: {
            wrapper: { connection: true, fill: "none", stroke: "transparent", strokeWidth: 12, strokeLinejoin: "round" },
            line: { connection: true, fill: "none", strokeLinejoin: "round" },
        },
    }, {
        markup: [
            { tagName: "path", selector: "wrapper", attributes: { "pointer-events": "stroke" } },
            { tagName: "path", selector: "line", className: "ci-rel-line", attributes: { "pointer-events": "none" } },
        ],
    });
    // Views re-apply emphasis classes when culling (re)mounts them.
    const TableView = joint.dia.ElementView.extend({
        onMount(isInitialMount) {
            joint.dia.ElementView.prototype.onMount.call(this, isInitialMount);
            const r = this.paper && this.paper.ciRenderer;
            if (r) {
                r._decorate(this);
            }
        },
    });
    const RelationView = joint.dia.LinkView.extend({
        onMount(isInitialMount) {
            joint.dia.LinkView.prototype.onMount.call(this, isInitialMount);
            const r = this.paper && this.paper.ciRenderer;
            if (r) {
                r._decorate(this);
            }
        },
    });
    return { ci: { Table, Relation, TableView, RelationView } };
}

function portDy(node, portId) {
    const port = node && portId ? node.ports.find((p) => p.id === portId) : null;
    return port ? port.y : null;
}

/**
 * Straight-edge endpoint: on the column row (dy) when it is drawn, on the side of the table that faces the
 * other end; the table centre (clipped to its border) otherwise.
 */
function straightEnd(nodeId, dy, box, other) {
    if (dy === null || dy === undefined) {
        return { id: nodeId, anchor: { name: "center", args: { useModelGeometry: true } },
            connectionPoint: { name: "bbox", args: { useModelGeometry: true } } };
    }
    const facesLeft = other.x + other.width / 2 < box.x + box.width / 2;
    return { id: nodeId, anchor: { name: "topLeft", args: { dx: facesLeft ? 0 : box.width, dy, useModelGeometry: true } } };
}

function unionBox(points) {
    let x1 = Infinity;
    let y1 = Infinity;
    let x2 = -Infinity;
    let y2 = -Infinity;
    for (const p of points) {
        x1 = Math.min(x1, p.x);
        y1 = Math.min(y1, p.y);
        x2 = Math.max(x2, p.x);
        y2 = Math.max(y2, p.y);
    }
    return { x: x1, y: y1, width: x2 - x1, height: y2 - y1 };
}

function intersects(a, b) {
    return a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;
}

export class SchemaRenderer {
    /**
     * @param {HTMLElement} host
     * @param {object} joint window.joint
     * @param {object} callbacks {onSelectTable, onSelectRelation, onHover, onActivateTable,
     *                            onMoved, onViewChange, onBlank}
     */
    constructor(host, joint, callbacks = {}, policy = null) {
        this.joint = joint;
        this.cb = callbacks;
        this.ns = defineShapes(joint);
        this.graph = new joint.dia.Graph({}, { cellNamespace: this.ns });
        this.lod = LOD.DETAIL;
        this.spec = null;
        this.nodeById = new Map();
        this.culling = false;
        this._view = { x: 0, y: 0, width: 0, height: 0 };
        this._lit = new Map();       // cell id -> class list to apply on mount
        this._focusActive = false;
        this._rowLit = new Map();    // element id -> Set<colId>
        this._movedLinks = new Set();
        this._raf = null;
        this.renderMs = 0;

        // The paper owns its own element: paper.remove() must never delete the host owned by OWL.
        this.el = document.createElement("div");
        this.el.className = "ci-paper-host";
        host.appendChild(this.el);
        this.paper = new joint.dia.Paper({
            el: this.el,
            model: this.graph,
            width: "100%",
            height: "100%",
            async: true,
            frozen: true,
            autoFreeze: true,
            viewManagement: { lazyInitialize: true, disposeHidden: true },
            sorting: joint.dia.Paper.sorting.APPROX,
            cellViewNamespace: this.ns,
            background: { color: "transparent" },
            gridSize: 10,
            clickThreshold: 5,
            moveThreshold: 3,
            interactive: (view) => (view.model.isElement() ? { elementMove: true, addLinkFromMagnet: false } : false),
            defaultConnectionPoint: { name: "anchor" },
            defaultConnector: { name: "rounded", args: { radius: 7 } },
            cellVisibility: (cell) => this._cellVisible(cell),
        });
        this.paper.ciRenderer = this;
        this.paper.el.classList.add("ci-paper", "ci-lod-2");
        this.policy = policy || resolvePolicy(PRESETS.balanced);
        this._labeled = new Set();
        this._applyEffectClasses();
        this._wireEvents();
    }

    // ------------------------------------------------------------------ render
    /**
     * Replace the diagram. `layout` = {positions, routes}; `pinned` = Map<nodeId,{x,y}> manual positions.
     * @returns {Promise<{ms:number, cells:number}>} resolves when the first render pass is done
     */
    render(spec, layout, { pinned = new Map(), lod = this.lod } = {}) {
        const t0 = performance.now();
        const { ci } = this.ns;
        this.spec = spec;
        this.lod = lod;
        this.nodeById = new Map(spec.nodes.map((n) => [n.id, n]));
        // orthogonal: ELK routes into column ports (layered); straight: organic / radial layouts
        this.edgeStyle = layout.edgeStyle || "orthogonal";
        const straight = this.edgeStyle === "straight";
        this.paper.el.classList.toggle("ci-edges-straight", straight);
        this._movedLinks.clear();
        this._lit.clear();
        this._rowLit.clear();
        this._focusActive = false;
        this.paper.el.classList.remove("ci-has-focus");
        this._setLodClass(lod);

        const cells = [];
        const boxes = new Map();
        for (const n of spec.nodes) {
            const pos = pinned.get(n.id) || layout.positions.get(n.id) || { x: 0, y: 0 };
            boxes.set(n.id, { x: pos.x, y: pos.y, width: n.width, height: n.height });
            cells.push(new ci.Table({
                id: n.id,
                position: { x: pos.x, y: pos.y },
                size: { width: n.width, height: n.height },
                markup: buildTableMarkup(n, lod, this.policy.markup),
                ciTable: n.tableId,
                ciChange: n.change || null,
            }));
        }
        for (const e of spec.edges) {
            const route = layout.routes.get(e.id);
            const sBox = boxes.get(e.source);
            const tBox = boxes.get(e.target);
            const rerouted = !straight && (!route || pinned.has(e.source) || pinned.has(e.target));
            // Anchors are model offsets from the table's top-left corner: the column row's connection
            // point when the column is shown, else ELK's exact border point. No DOM measurement.
            const end = (nodeId, portId, box, point) => {
                const port = portId && this.nodeById.get(nodeId).ports.find((p) => p.id === portId);
                // useModelGeometry: the table's DOM bbox includes protruding port dots, and culled
                // tables have no DOM at all — the model box is the only reliable reference.
                if (port && straight) {
                    return straightEnd(nodeId, port.y, box, nodeId === e.source ? tBox : sBox);
                }
                if (port) {
                    return { id: nodeId, anchor: { name: "topLeft", args: { dx: port.x, dy: port.y, useModelGeometry: true } } };
                }
                if (straight) {
                    return straightEnd(nodeId, null, box, nodeId === e.source ? tBox : sBox);
                }
                if (!rerouted && point) {
                    return { id: nodeId, anchor: { name: "topLeft",
                        args: { dx: point.x - box.x, dy: point.y - box.y, useModelGeometry: true } } };
                }
                return { id: nodeId, anchor: { name: "center", args: { useModelGeometry: true } } };
            };
            const rel = e;
            cells.push(new ci.Relation({
                id: e.id,
                ciRel: e.relId,
                ciChange: e.change || null,
                source: end(e.source, e.sourcePort, sBox, route && route.start),
                target: end(e.target, e.targetPort, tBox, route && route.end),
                vertices: rerouted || straight ? [] : route.bends,
                router: rerouted ? RIGHT_ANGLE : { name: "normal" },
                ciSrcDy: portDy(this.nodeById.get(e.source), e.sourcePort),
                ciTgtDy: portDy(this.nodeById.get(e.target), e.targetPort),
                ciBox: rerouted || straight ? unionBox([sBox, tBox].flatMap((b) => [b, { x: b.x + b.width, y: b.y + b.height }]))
                    : unionBox([route.start, ...route.bends, route.end]),
                attrs: { line: this._lineAttrs(rel) },
            }));
            if (rerouted) {
                this._movedLinks.add(e.id);
            }
        }

        this._labeled.clear();
        this.culling = cells.length >= this.policy.culling.minCells;
        this.paper.freeze();
        this.graph.resetCells(cells);
        this._updateViewArea();
        return new Promise((resolve) => {
            let settled = false;
            const done = () => {
                if (settled) {
                    return;
                }
                settled = true;
                this.paper.off("render:done", done);
                this.renderMs = performance.now() - t0;
                resolve({ ms: this.renderMs, cells: cells.length });
            };
            this.paper.on("render:done", done);
            this.paper.unfreeze();
            if (!cells.length) {
                done();
            } else {
                setTimeout(done, 5000); // never hang the UI on a missed event
            }
        });
    }

    _lineAttrs(e) {
        const spec = this.cb.relationMeta ? this.cb.relationMeta(e.relId) : {};
        const color = e.implied ? "#9d8cff" : "#4fd8ff";
        const many = spec.cardinality !== "one-to-one";
        return {
            class: `ci-rel-line ci-rel${e.implied ? " ci-rel-implied" : ""}${e.selfLoop ? " ci-rel-self" : ""}`
                + `${e.change ? ` ci-rel-diff-${e.change}` : ""}`,
            sourceMarker: { type: "path", d: many ? MARK_MANY : MARK_ONE, fill: "none", stroke: color, "stroke-width": 1.4 },
            targetMarker: {
                type: "path", d: spec.optional ? MARK_ONE_OPTIONAL : MARK_ONE_MANDATORY,
                fill: "#070b16", stroke: color, "stroke-width": 1.4,
            },
        };
    }

    // ------------------------------------------------------------------ LOD
    _setLodClass(lod) {
        const cl = this.paper.el.classList;
        cl.remove("ci-lod-0", "ci-lod-1", "ci-lod-2");
        cl.add(`ci-lod-${lod}`);
    }

    /** Regenerate table markup for a new zoom LOD. Unmounted views re-render lazily when shown. */
    setLod(lod) {
        if (lod === this.lod || !this.spec) {
            return;
        }
        this._rebuildMarkup(lod);
    }

    _rebuildMarkup(lod) {
        this.lod = lod;
        this._setLodClass(lod);
        if (!this.spec) {
            return;
        }
        this.paper.freeze();
        for (const n of this.spec.nodes) {
            const el = this.graph.getCell(n.id);
            if (el) {
                el.set("markup", buildTableMarkup(n, lod, this.policy.markup));
            }
        }
        this.paper.unfreeze();
        this._refreshRowHighlights();
        this._applyRelationLabels();
    }

    // ------------------------------------------------------------------ graphics quality
    /**
     * Apply a resolved RenderPolicy (graphics_policy.js). Rendering-only: effects (CSS classes on the
     * paper), LOD thresholds, markup options, culling and relation labels. Layout geometry (policy.geometry)
     * is applied by the controller at the next layout.
     */
    setPolicy(policy) {
        const prev = this.policy;
        this.policy = policy;
        this._applyEffectClasses();
        this.culling = this.graph.getCells().length >= policy.culling.minCells;
        this._updateViewArea();
        const lod = lodForScale(this.paper.scale().sx, policy.lod);
        if (lod !== this.lod || JSON.stringify(prev.markup) !== JSON.stringify(policy.markup)) {
            this._rebuildMarkup(lod);
        } else {
            this._applyRelationLabels();
        }
        this.paper.wakeUp(); // re-evaluate cellVisibility with the new culling buffer
    }

    /** Diff overlay on/off (paper class only; the change data comes with the GraphSpec). */
    setDiffMode(on) {
        this.paper.el.classList.toggle("ci-diff-mode", !!on);
    }

    _applyEffectClasses() {
        const cl = this.paper.el.classList;
        for (const c of [...cl]) {
            if (c.startsWith("ci-fx-") || c.startsWith("ci-tr-")) {
                cl.remove(c);
            }
        }
        const fx = this.policy.effects;
        if (fx.glow !== "off") {
            cl.add("ci-fx-glow-on");
        }
        if (fx.glow === "related") {
            cl.add("ci-fx-glow-related");
        }
        if (fx.shadows) {
            cl.add("ci-fx-shadows");
        }
        if (fx.selectionPulse) {
            cl.add("ci-fx-pulse");
        }
        if (fx.edgeAnimation) {
            cl.add("ci-fx-edge-anim");
        }
        cl.add(`ci-tr-${fx.transitions}`);
    }

    /** FK-name labels on at most policy.labels.relations emphasised relations (selected first). */
    _applyRelationLabels() {
        const max = this.lod === LOD.LANDSCAPE ? 0 : this.policy.labels.relations;
        const want = new Set();
        if (max > 0) {
            const lit = [...this._lit].filter(([id]) => id.startsWith("r"))
                .sort((a, b) => (b[1].includes("is-selected") ? 1 : 0) - (a[1].includes("is-selected") ? 1 : 0));
            for (const [id] of lit.slice(0, max)) {
                want.add(id);
            }
        }
        for (const id of this._labeled) {
            if (!want.has(id)) {
                const link = this.graph.getCell(id);
                if (link) {
                    link.set("labels", []);
                }
            }
        }
        for (const id of want) {
            if (this._labeled.has(id)) {
                continue;
            }
            const link = this.graph.getCell(id);
            const meta = link && this.cb.relationMeta ? this.cb.relationMeta(link.get("ciRel")) : null;
            if (link && meta && meta.name) {
                link.set("labels", [{
                    position: { distance: 0.5 },
                    attrs: {
                        text: { text: meta.name, fill: "#e8f6ff", fontSize: 10, fontFamily: "monospace" },
                        rect: { fill: "#070b16", stroke: meta.implied ? "#9d8cff" : "#4fd8ff", strokeWidth: 0.8 },
                    },
                }]);
            }
        }
        this._labeled = want;
    }

    // ------------------------------------------------------------------ viewport culling
    _updateViewArea() {
        const area = this.paper.getArea(); // visible area in local (graph) coordinates
        const margin = this.policy.culling.margin;
        const mx = area.width * margin;
        const my = area.height * margin;
        this._view = { x: area.x - mx, y: area.y - my, width: area.width + 2 * mx, height: area.height + 2 * my };
    }

    _cellVisible(cell) {
        if (!this.culling) {
            return true;
        }
        if (this._lit.has(cell.id)) {
            return true; // emphasised cells stay mounted (their classes must be visible)
        }
        const box = cell.isElement() ? cell.getBBox() : cell.get("ciBox");
        return box ? intersects(box, this._view) : true;
    }

    _onTransform() {
        if (this._raf) {
            return;
        }
        this._raf = requestAnimationFrame(() => {
            this._raf = null;
            this._updateViewArea();
            const lod = lodForScale(this.paper.scale().sx, this.policy.lod);
            if (lod !== this.lod) {
                this.setLod(lod);
            }
            if (this.culling) {
                this.paper.wakeUp(); // async loop re-checks cellVisibility in batches (non-blocking)
            }
            if (this.cb.onViewChange) {
                this.cb.onViewChange(this.viewInfo());
            }
        });
    }

    viewInfo() {
        const s = this.paper.scale().sx;
        return { scale: s, lod: this.lod, area: this.paper.getArea() };
    }

    // ------------------------------------------------------------------ events
    _wireEvents() {
        const p = this.paper;
        p.on("transform", () => this._onTransform());
        p.on("element:pointerclick", (view) => this.cb.onSelectTable && this.cb.onSelectTable(view.model.get("ciTable")));
        p.on("element:pointerdblclick", (view) => this.cb.onActivateTable && this.cb.onActivateTable(view.model.get("ciTable")));
        p.on("link:pointerclick", (view) => this.cb.onSelectRelation && this.cb.onSelectRelation(view.model.get("ciRel")));
        p.on("element:mouseenter", (view) => this.cb.onHover && this.cb.onHover({ table: view.model.get("ciTable") }));
        p.on("element:mouseleave", () => this.cb.onHover && this.cb.onHover(null));
        p.on("link:mouseenter", (view) => this.cb.onHover && this.cb.onHover({ relation: view.model.get("ciRel") }));
        p.on("link:mouseleave", () => this.cb.onHover && this.cb.onHover(null));
        p.on("blank:pointerclick", () => this.cb.onBlank && this.cb.onBlank());

        // Pan on blank drag; zoom on wheel around the cursor.
        p.on("blank:pointerdown", (evt) => {
            const start = { x: evt.clientX, y: evt.clientY, t: p.translate() };
            this._pan = start;
            p.el.classList.add("is-panning");
        });
        this._onMove = (evt) => {
            if (!this._pan) {
                return;
            }
            p.translate(this._pan.t.tx + evt.clientX - this._pan.x, this._pan.t.ty + evt.clientY - this._pan.y);
        };
        this._onUp = () => {
            if (this._pan) {
                this._pan = null;
                p.el.classList.remove("is-panning");
            }
        };
        window.addEventListener("mousemove", this._onMove);
        window.addEventListener("mouseup", this._onUp);
        this._onWheel = (evt) => {
            evt.preventDefault();
            const factor = Math.exp(-evt.deltaY * (evt.deltaMode === 1 ? 0.05 : 0.0015));
            const s = Math.min(SCALE_MAX, Math.max(SCALE_MIN, p.scale().sx * factor));
            p.scaleUniformAtPoint(s, p.clientToLocalPoint(evt.clientX, evt.clientY));
        };
        p.el.addEventListener("wheel", this._onWheel, { passive: false });

        // Manual moves: reroute connected relations orthogonally (ELK routes no longer apply) and
        // report the final position (visualization state — never written to schema facts).
        this.graph.on("change:position", (el, _pos, opt) => {
            if (!opt || !opt.ui) {
                return;
            }
            for (const link of this.graph.getConnectedLinks(el)) {
                if (this.edgeStyle === "straight") {
                    // straight edges: keep the row anchors, re-pick the side facing the other table
                    const sb = this.graph.getCell(link.get("source").id).getBBox();
                    const tb = this.graph.getCell(link.get("target").id).getBBox();
                    link.set({
                        source: straightEnd(link.get("source").id, link.get("ciSrcDy"), sb, tb),
                        target: straightEnd(link.get("target").id, link.get("ciTgtDy"), tb, sb),
                    });
                } else if (!this._movedLinks.has(link.id)) {
                    // Anchors are relative to their tables, so they stay on the right column rows.
                    this._movedLinks.add(link.id);
                    link.set({ vertices: [], router: RIGHT_ANGLE });
                }
                const s = this.graph.getCell(link.get("source").id).getBBox();
                const t = this.graph.getCell(link.get("target").id).getBBox();
                link.set("ciBox", s.clone().union(t).toJSON(), { silent: true });
            }
        });
        p.on("element:pointerup", (view) => {
            if (view._ciMoved) {
                view._ciMoved = false;
                this.cb.onMoved && this.cb.onMoved(view.model.get("ciTable"), view.model.position());
            }
        });
        p.on("element:pointermove", (view) => {
            view._ciMoved = true;
        });
        p.on("element:pointerdown", (view) => {
            view._ciMoved = false;
        });
    }

    // ------------------------------------------------------------------ emphasis
    /**
     * Emphasise a set of tables/relations and dim everything else. `rows` = Map<tableId, Set<colId>>.
     * `classes` lets the caller distinguish selected / incoming / outgoing.
     */
    emphasize({ tables = new Map(), relations = new Map(), rows = new Map() } = {}) {
        const prev = [...this._lit.keys()];
        this._lit.clear();
        for (const [tid, cls] of tables) {
            this._lit.set(`t${tid}`, cls);
        }
        for (const [rid, cls] of relations) {
            this._lit.set(`r${rid}`, cls);
        }
        this._rowLit = new Map([...rows].map(([tid, cols]) => [`t${tid}`, cols]));
        this._focusActive = this._lit.size > 0;
        this.paper.el.classList.toggle("ci-has-focus", this._focusActive);
        for (const id of new Set([...prev, ...this._lit.keys()])) {
            const view = this._viewOf(id);
            if (view) {
                this._decorate(view);
            }
        }
        if (this.culling && this._lit.size) {
            this.paper.wakeUp(); // make sure emphasised cells get mounted
        }
        this._applyRelationLabels();
    }

    clearEmphasis() {
        this.emphasize();
    }

    _viewOf(id) {
        const cell = this.graph.getCell(id);
        if (!cell) {
            return null;
        }
        const view = this.paper.findViewByModel(cell);
        return view && view.el ? view : null;
    }

    _decorate(view) {
        const id = view.model.id;
        const el = view.el;
        el.classList.remove("is-lit", "is-selected", "is-in", "is-out", "is-endpoint");
        const change = view.model.get("ciChange");
        if (change) {
            el.classList.add(`ci-diff-${change}`);
        }
        const cls = this._lit.get(id);
        if (cls) {
            el.classList.add("is-lit", ...cls.split(" ").filter(Boolean));
        }
        if (view.model.isElement()) {
            this._decorateRows(view);
        }
    }

    _decorateRows(view) {
        const cols = this._rowLit.get(view.model.id);
        for (const node of view.el.querySelectorAll(".ci-row.is-lit")) {
            node.classList.remove("is-lit");
        }
        if (cols) {
            for (const col of cols) {
                const row = view.el.querySelector(`.ci-row[data-col="${col}"]`);
                if (row) {
                    row.classList.add("is-lit");
                }
            }
        }
    }

    _refreshRowHighlights() {
        for (const id of this._rowLit.keys()) {
            const view = this._viewOf(id);
            if (view) {
                this._decorateRows(view);
            }
        }
    }

    // ------------------------------------------------------------------ navigation
    fit() {
        if (!this.graph.getElements().length) {
            return;
        }
        this.paper.transformToFitContent({
            padding: 40, maxScale: 1.1, minScale: SCALE_MIN, useModelGeometry: true,
            horizontalAlign: "middle", verticalAlign: "middle",
        });
    }

    zoomBy(factor) {
        const { width, height } = this.paper.el.getBoundingClientRect();
        const center = this.paper.clientToLocalPoint(
            this.paper.el.getBoundingClientRect().left + width / 2,
            this.paper.el.getBoundingClientRect().top + height / 2);
        const s = Math.min(SCALE_MAX, Math.max(SCALE_MIN, this.paper.scale().sx * factor));
        this.paper.scaleUniformAtPoint(s, center);
    }

    /** Center a table in the viewport, optionally at a given scale. */
    focusTable(tableId, { scale } = {}) {
        const cell = this.graph.getCell(`t${tableId}`);
        if (!cell) {
            return false;
        }
        const s = scale || Math.max(this.paper.scale().sx, 0.8);
        const c = cell.getBBox().center();
        const { width, height } = this.paper.el.getBoundingClientRect();
        this.paper.scale(s, s);
        this.paper.translate(width / 2 - c.x * s, height / 2 - c.y * s);
        return true;
    }

    /** Center on a local point (minimap navigation). */
    centerOn(x, y) {
        const s = this.paper.scale().sx;
        const { width, height } = this.paper.el.getBoundingClientRect();
        this.paper.translate(width / 2 - x * s, height / 2 - y * s);
    }

    hasTable(tableId) {
        return !!this.graph.getCell(`t${tableId}`);
    }

    // ------------------------------------------------------------------ overview + stats
    /** Draw a cheap canvas overview (no SVG): tables as rects, the viewport as a frame. */
    drawMinimap(canvas) {
        if (!canvas || !this.spec) {
            return null;
        }
        const ctx = canvas.getContext("2d");
        const W = canvas.width;
        const H = canvas.height;
        ctx.clearRect(0, 0, W, H);
        const els = this.graph.getElements();
        if (!els.length) {
            return null;
        }
        const bbox = this.graph.getBBox();
        const k = Math.min((W - 8) / bbox.width, (H - 8) / bbox.height);
        const ox = (W - bbox.width * k) / 2 - bbox.x * k;
        const oy = (H - bbox.height * k) / 2 - bbox.y * k;
        const level = this.policy.minimap;   // 'low' | 'normal' | 'detailed' ('off' = not drawn at all)
        if (level === "detailed") {
            // Relation lines between table centres (canvas, not SVG), capped.
            ctx.strokeStyle = "rgba(79, 216, 255, 0.18)";
            ctx.lineWidth = 0.6;
            ctx.beginPath();
            for (const link of this.graph.getLinks().slice(0, 4000)) {
                const s = this.graph.getCell(link.get("source").id);
                const t = this.graph.getCell(link.get("target").id);
                if (s && t) {
                    const a = s.getBBox().center();
                    const b = t.getBBox().center();
                    ctx.moveTo(ox + a.x * k, oy + a.y * k);
                    ctx.lineTo(ox + b.x * k, oy + b.y * k);
                }
            }
            ctx.stroke();
        }
        for (const el of els) {
            const b = el.getBBox();
            const change = el.get("ciChange");
            ctx.fillStyle = isChanged(change) ? MINIMAP_DIFF[change]    // diff colours at every level
                : level === "low" ? "rgba(90, 170, 230, 0.5)"
                : this._lit.has(el.id) ? "rgba(90, 225, 255, 0.95)"
                    : (this._focusActive ? "rgba(90, 140, 200, 0.25)" : "rgba(90, 170, 230, 0.55)");
            ctx.fillRect(ox + b.x * k, oy + b.y * k, Math.max(1.5, b.width * k), Math.max(1.5, b.height * k));
        }
        const a = this.paper.getArea();
        ctx.strokeStyle = "rgba(255, 90, 220, 0.9)";
        ctx.lineWidth = 1;
        ctx.strokeRect(ox + a.x * k, oy + a.y * k, a.width * k, a.height * k);
        return { k, ox, oy };
    }

    stats() {
        const svg = this.paper.svg;
        return {
            cells: this.graph.getCells().length,
            elements: this.graph.getElements().length,
            links: this.graph.getLinks().length,
            mounted: this.paper.el.querySelectorAll(".joint-cell").length,
            svgNodes: svg ? svg.getElementsByTagName("*").length : 0,
            culling: this.culling,
            renderMs: this.renderMs,
            lod: this.lod,
            scale: this.paper.scale().sx,
        };
    }

    destroy() {
        window.removeEventListener("mousemove", this._onMove);
        window.removeEventListener("mouseup", this._onUp);
        if (this.paper && this.paper.el) {
            this.paper.el.removeEventListener("wheel", this._onWheel);
        }
        if (this._raf) {
            cancelAnimationFrame(this._raf);
        }
        this.paper.remove(); // removes this.el (our own child), not the OWL host
        this.graph.clear();
    }
}

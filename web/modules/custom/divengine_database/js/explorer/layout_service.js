/* Layout service — ELK (Eclipse Layout Kernel, elkjs) computes node positions AND orthogonal edge routes
 * for a GraphSpec. Knows ELK; knows nothing about Drupal, SQL or JointJS.
 *
 * Execution: ELK runs in a dedicated Web Worker (`elk-worker.min.js`), so a large layout never blocks
 * the UI thread. If a worker cannot be created, it falls back to ELK's in-thread "fake worker". A newer
 * request supersedes an older one: the busy worker is terminated and recreated (ELK has no cancel).
 *
 * Algorithm: `layered` (Sugiyama) — the right family for FK graphs: edges flow child -> parent in one
 * direction, crossings are minimized layer by layer, and edges are routed ORTHOGONALLY into fixed
 * column ports (portConstraints FIXED_POS), so a relationship visibly leaves `child.fk_col` and enters
 * `parent.pk_col`. Disconnected components are packed separately. Heavier optimisation passes are
 * relaxed as the graph grows (see `optionsFor`).
 *
 * Layout algorithms (user choice, see layout_shapes.js):
 *   layered   ELK layered, orthogonal routes into column ports (above)
 *   organic   ELK `stress` (graph-distance preserving, straight edges) + ELK `sporeOverlap`, because
 *             stress places node CENTRES and tables are large rectangles (measured on a 300-table schema: 0
 *             overlaps, ~1.5-2.5 s for 118 connected tables)
 *   radial    pure JS (layout_shapes.radialLayout): focus in the centre, rings by FK distance
 * In every mode, tables with no drawn relation are packed in a name-ordered grid below the drawing
 * instead of being laid out as a long strip.
 */

import { ALGORITHMS, radialLayout, splitIsolated, withIsolatedGrid } from "./layout_shapes.js";

export const ELK_VERSION = "0.12.0";

export const DIRECTIONS = Object.freeze({ RIGHT: "RIGHT", DOWN: "DOWN" });

// Organic (stress) grows super-linearly. Measured (elkjs 0.12, Node, synthetic schemas): 129 connected
// tables 2.2 s, 276 -> 9 s, 404 -> 11 s, 602 -> 59 s (stress + overlap removal). Beyond this bound the
// service falls back to layered and says so (result.fallback) instead of stalling the worker.
export const ORGANIC_MAX = 300;

/**
 * ELK layout options tuned by graph size. Pure (unit-tested).
 * @param {{nodes: number, edges: number}} size
 * @param {{direction?: string}} opts
 */
export function optionsFor(size, { direction = DIRECTIONS.RIGHT } = {}) {
    const n = size.nodes;
    const e = size.edges || 0;
    // Tiers measured with the CodeIntel ELK benchmark + elkjs 0.12: the quality passes
    // (model-order stability, network-simplex placement, high thoroughness) are affordable only for
    // small neighborhoods — on a 198-table / 222-FK neighborhood they cost 3.9 s vs 0.3 s without.
    const small = n <= 80 && e <= 120;
    const medium = n <= 300;
    const huge = n > 1200;
    return {
        "elk.algorithm": "layered",
        "elk.direction": direction,
        "elk.edgeRouting": "ORTHOGONAL",
        "elk.spacing.nodeNode": huge ? "24" : "40",
        "elk.layered.spacing.nodeNodeBetweenLayers": huge ? "48" : "90",
        "elk.spacing.edgeNode": "18",
        "elk.spacing.edgeEdge": "8",
        "elk.layered.spacing.edgeNodeBetweenLayers": "18",
        "elk.layered.spacing.edgeEdgeBetweenLayers": "8",
        "elk.spacing.componentComponent": huge ? "40" : "70",
        "elk.separateConnectedComponents": "true",
        "elk.layered.crossingMinimization.strategy": "LAYER_SWEEP",
        // Layering: FK neighborhoods are hub-and-spoke; NETWORK_SIMPLEX stacks every child of a hub in one
        // layer (measured aspect ~0.2 — a tall column). MIN_WIDTH bounds layer width (aspect ~1.5-2, i.e.
        // screen-shaped) but costs 4-10 s on 1,000-2,000-table landscapes, where NETWORK_SIMPLEX is
        // fastest and already near-square once components are packed.
        "elk.layered.layering.strategy": medium ? "MIN_WIDTH" : "NETWORK_SIMPLEX",
        // NETWORK_SIMPLEX gives the straightest edges; BRANDES_KOEPF is much cheaper beyond small graphs.
        "elk.layered.nodePlacement.strategy": small ? "NETWORK_SIMPLEX" : "BRANDES_KOEPF",
        "elk.layered.thoroughness": small ? "7" : huge ? "1" : "3",
        // Small graphs: input-order-aware layouts, so the same subgraph lays out the same way on re-runs.
        "elk.layered.considerModelOrder.strategy": small ? "NODES_AND_EDGES" : "NONE",
        // Never MODEL_ORDER: it reversed 70-75% of FK edges (parents placed before their children, every
        // edge wrapping back). GREEDY_MODEL_ORDER reverses none and uses input order only for ties.
        "elk.layered.cycleBreaking.strategy": small ? "GREEDY_MODEL_ORDER" : "GREEDY",
        "elk.layered.crossingMinimization.greedySwitch.type": small ? "TWO_SIDED" : "OFF",
        "elk.layered.unnecessaryBendpoints": "false",
        "elk.layered.mergeEdges": "false",
        "elk.padding": "[top=30,left=30,bottom=30,right=30]",
    };
}

/** Stress options for the organic mode (straight edges; overlap removal is a second pass). */
export function organicOptions(size) {
    return {
        "elk.algorithm": "stress",
        // tables are ~330 px wide: shorter edges only create overlaps for sporeOverlap to undo
        "elk.stress.desiredEdgeLength": size.nodes > 300 ? "220" : "260",
        "elk.stress.iterationLimit": "300",
        "elk.separateConnectedComponents": "true",
        "elk.spacing.componentComponent": "120",
    };
}

export const OVERLAP_OPTIONS = Object.freeze({
    "elk.algorithm": "sporeOverlap",
    "elk.spacing.nodeNode": "36",
    "elk.separateConnectedComponents": "true",
});

/** Simple ELK graph (no ports) for the organic passes; `positions` seeds the overlap-removal pass. */
export function toSimpleElkGraph(nodes, edges, layoutOptions, positions = null) {
    return {
        id: "root",
        layoutOptions,
        children: nodes.map((n) => {
            const c = { id: n.id, width: n.width, height: n.height };
            if (positions && positions.has(n.id)) {
                Object.assign(c, positions.get(n.id));
            }
            return c;
        }),
        edges: edges.filter((e) => e.source !== e.target).map((e) => ({ id: e.id, sources: [e.source], targets: [e.target] })),
    };
}

/** GraphSpec -> ELK JSON graph. Pure (unit-tested). */
export function toElkGraph(spec, opts = {}) {
    const children = spec.nodes.map((n) => {
        const node = {
            id: n.id,
            width: n.width,
            height: n.height,
            layoutOptions: {},
        };
        if (n.ports.length) {
            node.layoutOptions["elk.portConstraints"] = "FIXED_POS";
            node.ports = n.ports.map((p) => ({
                id: `${n.id}|${p.id}`,
                x: p.x,
                y: p.y,
                width: 0,
                height: 0,
                layoutOptions: { "elk.port.side": p.side === "out" ? "EAST" : "WEST" },
            }));
        }
        return node;
    });
    const edges = spec.edges.map((e) => ({
        id: e.id,
        sources: [e.sourcePort ? `${e.source}|${e.sourcePort}` : e.source],
        targets: [e.targetPort ? `${e.target}|${e.targetPort}` : e.target],
    }));
    return {
        id: "root",
        layoutOptions: optionsFor({ nodes: spec.nodes.length, edges: spec.edges.length }, opts),
        children,
        edges,
    };
}

/**
 * ELK result -> {positions: Map<nodeId,{x,y}>, routes: Map<edgeId,{start,end,bends}>, bbox}. Pure.
 * All coordinates are absolute (edges are declared on the root, so ELK reports them in root space).
 */
export function fromElkResult(result) {
    const positions = new Map();
    let maxX = 0;
    let maxY = 0;
    for (const c of result.children || []) {
        positions.set(c.id, { x: c.x || 0, y: c.y || 0 });
        maxX = Math.max(maxX, (c.x || 0) + (c.width || 0));
        maxY = Math.max(maxY, (c.y || 0) + (c.height || 0));
    }
    const routes = new Map();
    for (const e of result.edges || []) {
        const s = (e.sections || [])[0];
        if (s) {
            routes.set(e.id, {
                start: s.startPoint,
                end: s.endPoint,
                bends: (s.bendPoints || []).map((p) => ({ x: p.x, y: p.y })),
            });
        }
    }
    return { positions, routes, bbox: { width: result.width || maxX, height: result.height || maxY } };
}

/** Positions (top-left) -> drawing shifted to the origin with its bounding box. */
function boxOf(positions, nodes) {
    let x1 = Infinity;
    let y1 = Infinity;
    let x2 = -Infinity;
    let y2 = -Infinity;
    for (const n of nodes) {
        const p = positions.get(n.id) || { x: 0, y: 0 };
        x1 = Math.min(x1, p.x);
        y1 = Math.min(y1, p.y);
        x2 = Math.max(x2, p.x + n.width);
        y2 = Math.max(y2, p.y + n.height);
    }
    const out = new Map();
    for (const n of nodes) {
        const p = positions.get(n.id) || { x: 0, y: 0 };
        out.set(n.id, { x: p.x - x1 + 30, y: p.y - y1 + 30 });
    }
    return { positions: out, width: x2 - x1 + 60, height: y2 - y1 + 60 };
}

export class LayoutService {
    /**
     * @param {Function} ElkClass window.ELK (elk.bundled.js)
     * @param {{workerUrl?: string}} options
     */
    constructor(ElkClass, { workerUrl } = {}) {
        this.ElkClass = ElkClass;
        this.workerUrl = workerUrl;
        this.mode = "none";
        this._generation = 0;
        this._requests = 0;
        this._busy = false;
        this._create();
    }

    _create() {
        this.elk = null;
        if (this.workerUrl && typeof Worker !== "undefined") {
            try {
                // Only a factory (no workerUrl): elk.bundled.js would otherwise probe for the Node
                // 'web-worker' package and log a warning. The factory owns the URL.
                const url = this.workerUrl;
                this.elk = new this.ElkClass({ workerFactory: () => new Worker(url) });
                this.mode = "worker";
                return;
            } catch {
                // fall through to the in-thread fallback
            }
        }
        this.elk = new this.ElkClass();
        this.mode = "main-thread";
    }

    /**
     * Lay out a GraphSpec with the chosen algorithm. Isolated tables always go to a grid below.
     * opts = {direction, algorithm: 'layered'|'organic'|'radial', roots: nodeIds (radial centre)}.
     * Resolves to null when superseded by a newer request.
     * @returns {Promise<null|{positions, routes, bbox, ms, mode, algorithm, edgeStyle, fallback?}>}
     */
    async layout(spec, opts = {}) {
        const t0 = performance.now();
        const token = ++this._requests; // a newer layout() call supersedes this one, whatever the algorithm
        const stale = () => token !== this._requests;
        let algorithm = ALGORITHMS.includes(opts.algorithm) ? opts.algorithm : "layered";
        const { connected, isolated } = splitIsolated(spec.nodes, spec.edges);
        const connectedIds = new Set(connected.map((n) => n.id));
        const edges = spec.edges.filter((e) => connectedIds.has(e.source) && connectedIds.has(e.target));
        let fallback = null;
        if (algorithm === "organic" && connected.length > ORGANIC_MAX) {
            fallback = `Organic layout is limited to ${ORGANIC_MAX} connected tables (${connected.length} here): used Hierarchical.`;
            algorithm = "layered";
        }
        let main;
        let routes = new Map();
        let mode = this.mode;
        if (!connected.length) {
            main = { positions: new Map(), width: 0, height: 0 };
        } else if (algorithm === "radial") {
            main = radialLayout(connected, edges, opts.roots || []);
            mode = "main-thread";
        } else if (algorithm === "organic") {
            const first = await this._run(toSimpleElkGraph(connected, edges, organicOptions({ nodes: connected.length })));
            if (!first || stale()) {
                return null;
            }
            const seeded = fromElkResult(first).positions;
            const second = await this._run(toSimpleElkGraph(connected, edges, OVERLAP_OPTIONS, seeded));
            if (!second || stale()) {
                return null;
            }
            main = boxOf(fromElkResult(second).positions, connected);
        } else {
            const sub = { ...spec, nodes: connected, edges };
            const result = await this._run(toElkGraph(sub, opts));
            if (!result || stale()) {
                return null;
            }
            const out = fromElkResult(result);
            routes = out.routes;
            main = { positions: out.positions, width: out.bbox.width, height: out.bbox.height };
        }
        const all = withIsolatedGrid(main, isolated);
        return {
            positions: all.positions,
            routes,
            bbox: { width: all.width, height: all.height },
            isolatedBox: all.isolatedBox || null,
            isolated: isolated.length,
            ms: performance.now() - t0,
            mode,
            algorithm,
            edgeStyle: algorithm === "layered" ? "orthogonal" : "straight",
            fallback,
        };
    }

    /** One ELK run; null when superseded. */
    async _run(graph) {
        const generation = ++this._generation;
        if (this._busy && this.mode === "worker") {
            // ELK cannot cancel: drop the busy worker so the newest request starts immediately.
            try {
                this.elk.terminateWorker();
            } catch {
                // ignore
            }
            this._create();
        }
        this._busy = true;
        let result;
        try {
            result = await this.elk.layout(graph);
        } catch (err) {
            if (generation !== this._generation) {
                return null; // superseded (terminated worker rejects)
            }
            if (this.mode === "worker") {
                // Worker failed to load/run (e.g. blocked by policy): retry once in-thread.
                this.workerUrl = null;
                this._create();
                result = await this.elk.layout(graph);
            } else {
                throw err;
            }
        } finally {
            if (generation === this._generation) {
                this._busy = false;
            }
        }
        if (generation !== this._generation) {
            return null;
        }
        return result;
    }

    destroy() {
        this._generation++;
        try {
            if (this.elk && this.elk.terminateWorker) {
                this.elk.terminateWorker();
            }
        } catch {
            // ignore
        }
        this.elk = null;
    }
}

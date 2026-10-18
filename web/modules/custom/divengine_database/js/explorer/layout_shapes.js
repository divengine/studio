/* Layout shapes — pure geometry helpers the layout service composes around (or instead of) ELK.
 * Knows nothing about Drupal, SQL, ELK or JointJS: nodes are {id, width, height}, edges {source, target}.
 *
 *   splitIsolated   tables with no drawn relation are taken OUT of the graph layout (they only inflate
 *                   it: ELK packs them as a long strip) and packed in a compact grid instead
 *   packGrid        shelf packing in name order, below / beside the main drawing
 *   radialLayout    focus table(s) in the centre, FK neighbours on rings by hop distance; each subtree
 *                   gets an angular wedge proportional to its size, so children sit next to their parent
 *   packBoxes       place independent drawings (components) side by side without overlap
 *
 * All functions are deterministic (same input -> same positions): manual positions and screenshots stay
 * comparable between runs.
 */

export const ALGORITHMS = Object.freeze(["layered", "organic", "radial"]);
export const ALGORITHM_LABEL = Object.freeze({ layered: "Hierarchical", organic: "Organic", radial: "Radial" });

const GAP = 40;

/** Split nodes into those touched by at least one non-self edge and the isolated rest. */
export function splitIsolated(nodes, edges) {
    const touched = new Set();
    for (const e of edges) {
        if (e.source !== e.target) {
            touched.add(e.source);
            touched.add(e.target);
        }
    }
    const connected = [];
    const isolated = [];
    for (const n of nodes) {
        (touched.has(n.id) ? connected : isolated).push(n);
    }
    return { connected, isolated };
}

/**
 * Shelf packing, rows left to right. `order` = nodes already sorted by the caller (name order keeps the
 * grid scannable). Returns {positions: Map<id,{x,y}>, width, height}.
 */
export function packGrid(nodes, { x0 = 0, y0 = 0, maxWidth = 1600, gap = GAP } = {}) {
    const positions = new Map();
    let x = x0;
    let y = y0;
    let rowH = 0;
    let width = 0;
    for (const n of nodes) {
        if (x > x0 && x + n.width > x0 + maxWidth) {
            x = x0;
            y += rowH + gap;
            rowH = 0;
        }
        positions.set(n.id, { x, y });
        x += n.width + gap;
        rowH = Math.max(rowH, n.height);
        width = Math.max(width, x - gap - x0);
    }
    return { positions, width, height: nodes.length ? y + rowH - y0 : 0 };
}

/** Grid width that keeps the isolated block roughly screen-shaped and never narrower than `min`. */
export function gridWidthFor(nodes, min = 1600) {
    const area = nodes.reduce((s, n) => s + (n.width + GAP) * (n.height + GAP), 0);
    return Math.max(min, Math.sqrt(area * 1.8));
}

function components(ids, adj) {
    const seen = new Set();
    const out = [];
    for (const id of ids) {
        if (seen.has(id)) {
            continue;
        }
        const comp = [];
        const stack = [id];
        seen.add(id);
        while (stack.length) {
            const v = stack.pop();
            comp.push(v);
            for (const w of adj.get(v)) {
                if (!seen.has(w)) {
                    seen.add(w);
                    stack.push(w);
                }
            }
        }
        out.push(comp);
    }
    return out;
}

/** One component as a radial tree around `roots`. Returns {positions (top-left), width, height}. */
function radialComponent(roots, adj, byId) {
    // BFS spanning tree: first discoverer is the parent; children in stable (name) order.
    const depth = new Map();
    const parent = new Map();
    const children = new Map();
    const rings = [[]];
    for (const r of roots) {
        depth.set(r, 0);
        children.set(r, []);
        rings[0].push(r);
    }
    let frontier = [...roots];
    while (frontier.length) {
        const next = [];
        for (const v of frontier) {
            const nbrs = [...adj.get(v)].filter((w) => !depth.has(w))
                .sort((a, b) => byId.get(a).name.localeCompare(byId.get(b).name));
            for (const w of nbrs) {
                if (depth.has(w)) {
                    continue;
                }
                const d = depth.get(v) + 1;
                depth.set(w, d);
                parent.set(w, v);
                children.set(w, []);
                children.get(v).push(w);
                (rings[d] = rings[d] || []).push(w);
                next.push(w);
            }
        }
        frontier = next;
    }
    // Wedge weight = leaves in the subtree (classic radial tree drawing).
    const weight = new Map();
    for (let d = rings.length - 1; d >= 0; d--) {
        for (const v of rings[d]) {
            const kids = children.get(v);
            weight.set(v, kids.length ? kids.reduce((s, k) => s + weight.get(k), 0) : 1);
        }
    }
    const angle = new Map();   // centre angle
    const wedge = new Map();   // angular width
    const total = roots.reduce((s, r) => s + weight.get(r), 0);
    let a0 = -Math.PI / 2;
    for (const r of roots) {
        const w = (2 * Math.PI * weight.get(r)) / total;
        angle.set(r, a0 + w / 2);
        wedge.set(r, w);
        a0 += w;
    }
    for (let d = 0; d < rings.length; d++) {
        for (const v of rings[d]) {
            let start = angle.get(v) - wedge.get(v) / 2;
            for (const k of children.get(v)) {
                const w = (wedge.get(v) * weight.get(k)) / weight.get(v);
                angle.set(k, start + w / 2);
                wedge.set(k, w);
                start += w;
            }
        }
    }
    // Ring radii: far enough from the previous ring, and wide enough that every node fits its wedge.
    const size = (id) => Math.max(byId.get(id).width, byId.get(id).height) + GAP;
    const radius = [];
    for (let d = 0; d < rings.length; d++) {
        const ringMax = Math.max(...rings[d].map(size));
        let r;
        if (d === 0) {
            // one root sits in the centre; several roots share a small inner ring
            r = rings[0].length === 1 ? 0 : Math.max(...rings[0].map((id) => size(id) / Math.min(wedge.get(id), Math.PI)));
        } else {
            const prevMax = Math.max(...rings[d - 1].map(size));
            r = radius[d - 1] + (prevMax + ringMax) / 2 + GAP;
            for (const id of rings[d]) {
                r = Math.max(r, size(id) / Math.min(wedge.get(id), Math.PI));
            }
        }
        radius.push(r);
    }
    const centres = new Map();
    for (let d = 0; d < rings.length; d++) {
        for (const id of rings[d]) {
            const t = angle.get(id);
            centres.set(id, { x: radius[d] * Math.cos(t), y: radius[d] * Math.sin(t) });
        }
    }
    return normalize(centres, byId);
}

/** Centre coordinates -> top-left positions shifted to the origin, plus the bounding box. */
function normalize(centres, byId) {
    let x1 = Infinity;
    let y1 = Infinity;
    let x2 = -Infinity;
    let y2 = -Infinity;
    for (const [id, c] of centres) {
        const n = byId.get(id);
        x1 = Math.min(x1, c.x - n.width / 2);
        y1 = Math.min(y1, c.y - n.height / 2);
        x2 = Math.max(x2, c.x + n.width / 2);
        y2 = Math.max(y2, c.y + n.height / 2);
    }
    const positions = new Map();
    for (const [id, c] of centres) {
        const n = byId.get(id);
        positions.set(id, { x: c.x - n.width / 2 - x1, y: c.y - n.height / 2 - y1 });
    }
    return { positions, width: x2 - x1, height: y2 - y1 };
}

/**
 * Radial layout of connected nodes. `rootIds` (e.g. the exploration seeds) centre the component that
 * contains them; every other component is centred on its most connected table. Components are packed
 * side by side, the rooted one first.
 */
export function radialLayout(nodes, edges, rootIds = []) {
    const byId = new Map(nodes.map((n) => [n.id, n]));
    const adj = new Map(nodes.map((n) => [n.id, new Set()]));
    for (const e of edges) {
        if (e.source !== e.target && adj.has(e.source) && adj.has(e.target)) {
            adj.get(e.source).add(e.target);
            adj.get(e.target).add(e.source);
        }
    }
    const ids = [...byId.keys()].sort((a, b) => byId.get(a).name.localeCompare(byId.get(b).name));
    const rootSet = new Set(rootIds.filter((id) => byId.has(id)));
    const comps = components(ids, adj).map((comp) => {
        let roots = comp.filter((id) => rootSet.has(id));
        if (!roots.length) {
            const hub = comp.reduce((best, id) => (adj.get(id).size > adj.get(best).size ? id : best), comp[0]);
            roots = [hub];
        }
        return { rooted: roots.some((id) => rootSet.has(id)), size: comp.length, drawing: radialComponent(roots, adj, byId) };
    });
    comps.sort((a, b) => (b.rooted - a.rooted) || (b.size - a.size));
    return packBoxes(comps.map((c) => c.drawing));
}

/**
 * Place independent drawings ({positions, width, height}) in shelves, largest first; returns the merged
 * {positions, width, height}.
 */
export function packBoxes(drawings, { gap = GAP * 3, maxWidth = null } = {}) {
    const positions = new Map();
    if (!drawings.length) {
        return { positions, width: 0, height: 0 };
    }
    const limit = maxWidth || Math.max(drawings[0].width, gridWidthFor(drawings.map((d) => ({ width: d.width, height: d.height })), 1600));
    let x = 0;
    let y = 0;
    let rowH = 0;
    let width = 0;
    for (const d of drawings) {
        if (x > 0 && x + d.width > limit) {
            x = 0;
            y += rowH + gap;
            rowH = 0;
        }
        for (const [id, p] of d.positions) {
            positions.set(id, { x: p.x + x, y: p.y + y });
        }
        x += d.width + gap;
        rowH = Math.max(rowH, d.height);
        width = Math.max(width, x - gap);
    }
    return { positions, width, height: y + rowH };
}

/** Append the isolated-table grid below a main drawing. */
export function withIsolatedGrid(main, isolated, { gap = GAP * 4 } = {}) {
    if (!isolated.length) {
        return main;
    }
    const sorted = [...isolated].sort((a, b) => a.name.localeCompare(b.name));
    const y0 = main.height ? main.height + gap : 0;
    const grid = packGrid(sorted, { x0: 0, y0, maxWidth: Math.max(main.width, gridWidthFor(sorted)) });
    const positions = new Map(main.positions);
    for (const [id, p] of grid.positions) {
        positions.set(id, p);
    }
    return {
        positions,
        width: Math.max(main.width, grid.width),
        height: y0 + grid.height,
        isolatedBox: { x: 0, y: y0, width: grid.width, height: grid.height },
    };
}

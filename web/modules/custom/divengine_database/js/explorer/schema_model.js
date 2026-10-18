/* Normalized schema model — the client-side mirror of the snapshot index endpoint (SchemaFacts::graphIndex).
 *
 * Pure JavaScript: no Drupal, no JointJS, no ELK. It holds database FACTS only (tables, columns,
 * relationships) plus the adjacency needed to explore them (neighborhoods, degree, isolation) and a
 * local name index. Column rows are loaded lazily per table (`setDetails`) — the model never requires
 * the whole snapshot's columns to be in memory.
 *
 * Direction vocabulary (used everywhere in the explorer):
 *   relation.source = the CHILD table (holds the FK columns)
 *   relation.target = the PARENT table (referenced)
 *   "outgoing" from T = relations where T is the source  (T references others)
 *   "incoming" to   T = relations where T is the target  (others reference T)
 */

export const DIRECTIONS = ["both", "outgoing", "incoming"];
export const KINDS = ["real", "implied", "both"];

export class SchemaModel {
    /**
     * @param {object} index payload of get_schema_graph_index
     */
    constructor(index) {
        this.snapshot = index.snapshot || {};
        // Schema-diff overlay (union A U B from get_schema_diff_graph): change annotations are DATA from the
        // server; the explorer only displays them. null for a normal single-snapshot explorer.
        this.diff = (index.snapshot && index.snapshot.diff) || null;
        this.stats = index.stats || {};
        /** @type {Map<number, object>} */
        this.tables = new Map();
        /** @type {Map<number, object>} */
        this.relations = new Map();
        /** @type {Map<number, number[]>} relation ids where the table is the source (child) */
        this.outgoing = new Map();
        /** @type {Map<number, number[]>} relation ids where the table is the target (parent) */
        this.incoming = new Map();
        const schemas = new Set();
        for (const t of index.tables || []) {
            this.tables.set(t.id, {
                id: t.id,
                key: t.key,
                name: t.name,
                schema: t.schema || "",
                type: t.type || "table",
                columnCount: t.columns || 0,
                pkCount: t.pk || 0,
                fkCount: t.fk || 0,
                // lazily filled by setDetails(): {columns: [...], comment, view_definition}
                details: null,
                change: t.change || null,            // diff mode: added | removed | modified | unchanged
                origin: t.origin || null,            // diff mode: 'to' (drawn from B) | 'from' (ghost from A)
                childChanges: t.child_changes || null,
                // lower-cased search keys, computed once
                _q: `${(t.name || "").toLowerCase()}\u0000${(t.schema || "").toLowerCase()}`,
            });
            schemas.add(t.schema || "");
            this.outgoing.set(t.id, []);
            this.incoming.set(t.id, []);
        }
        for (const r of index.relations || []) {
            if (!this.tables.has(r.source) || !this.tables.has(r.target)) {
                continue; // never render a dangling reference
            }
            this.relations.set(r.id, {
                id: r.id,
                key: r.key,
                name: r.name,
                implied: !!r.implied,
                onDeleteCascade: !!r.on_delete_cascade,
                onUpdate: r.on_update || null,
                source: r.source,
                target: r.target,
                columns: (r.columns || []).map((c) => ({
                    source: c.source, sourceName: c.source_name,
                    target: c.target, targetName: c.target_name,
                })),
                cardinality: r.cardinality || null,   // derived server-side (PK/unique coverage)
                optional: !!r.optional,               // derived server-side (nullable FK columns)
                change: r.change || null,             // diff mode
                origin: r.origin || null,
            });
            this.outgoing.get(r.source).push(r.id);
            this.incoming.get(r.target).push(r.id);
        }
        this.schemas = [...schemas].sort();
    }

    get tableCount() {
        return this.tables.size;
    }

    get relationCount() {
        return this.relations.size;
    }

    table(id) {
        return this.tables.get(id);
    }

    relation(id) {
        return this.relations.get(id);
    }

    // ---------------------------------------------------------------- details (lazy columns)
    hasDetails(tableId) {
        const t = this.tables.get(tableId);
        return !!(t && t.details);
    }

    /** Ids (from `ids`) whose column rows are not loaded yet. */
    missingDetails(ids) {
        const out = [];
        for (const id of ids) {
            const t = this.tables.get(id);
            if (t && !t.details) {
                out.push(id);
            }
        }
        return out;
    }

    /** Merge a get_schema_table_details payload. Keys may be strings (JSON object keys). */
    setDetails(payload) {
        for (const [key, det] of Object.entries((payload && payload.tables) || {})) {
            const t = this.tables.get(Number(key));
            if (t) {
                t.details = {
                    comment: det.comment || null,
                    viewDefinition: det.view_definition || null,
                    change: det.change || null,
                    objectChanges: det.object_changes || [],
                    columns: (det.columns || []).map((c) => ({
                        id: c.id,
                        name: c.name,
                        position: c.position,
                        type: c.type || "?",
                        dataType: c.data_type || null,
                        nullable: !!c.nullable,
                        hasDefault: !!c.has_default,
                        default: c.has_default ? c.default : null,
                        pk: c.pk || 0,
                        fk: !!c.fk,
                        impliedFk: !!c.implied_fk,
                        unique: c.unique || false,
                        autoIncrement: !!c.auto_increment,
                        enumValues: c.enum || null,
                        comment: c.comment || null,
                        change: c.change || null,        // diff mode
                        changes: c.changes || [],
                    })),
                };
            }
        }
    }

    // ---------------------------------------------------------------- relations / adjacency
    relationAllowed(rel, kinds) {
        if (kinds === "real") {
            return !rel.implied;
        }
        if (kinds === "implied") {
            return rel.implied;
        }
        return true;
    }

    /** Relation ids touching `tableId`, filtered by direction and kind. */
    relationsOf(tableId, direction = "both", kinds = "both") {
        const out = [];
        if (direction === "both" || direction === "outgoing") {
            for (const rid of this.outgoing.get(tableId) || []) {
                if (this.relationAllowed(this.relations.get(rid), kinds)) {
                    out.push(rid);
                }
            }
        }
        if (direction === "both" || direction === "incoming") {
            for (const rid of this.incoming.get(tableId) || []) {
                if (this.relationAllowed(this.relations.get(rid), kinds)) {
                    out.push(rid);
                }
            }
        }
        return out;
    }

    degree(tableId, kinds = "both") {
        return this.relationsOf(tableId, "both", kinds).length;
    }

    isIsolated(tableId, kinds = "both") {
        return this.degree(tableId, kinds) === 0;
    }

    /**
     * Breadth-first FK neighborhood of `seedIds` up to `depth` hops.
     * `direction` follows relations from each frontier table: "outgoing" walks towards referenced
     * parents, "incoming" towards referencing children, "both" either way.
     * Bounded by `maxTables` (the result reports whether it was truncated). Tables in `skip` are never
     * entered (seeds are always included).
     * @returns {{ids: Set<number>, depthOf: Map<number, number>, truncated: boolean}}
     */
    neighborhood(seedIds, { depth = 1, direction = "both", kinds = "both", maxTables = Infinity, skip = null } = {}) {
        const ids = new Set();
        const depthOf = new Map();
        let frontier = [];
        for (const id of seedIds) {
            if (this.tables.has(id) && !ids.has(id)) {
                ids.add(id);
                depthOf.set(id, 0);
                frontier.push(id);
            }
        }
        let truncated = false;
        for (let hop = 1; hop <= depth && frontier.length; hop++) {
            const next = [];
            for (const tid of frontier) {
                for (const rid of this.relationsOf(tid, direction, kinds)) {
                    const rel = this.relations.get(rid);
                    const other = rel.source === tid ? rel.target : rel.source;
                    if (ids.has(other) || (skip && skip.has(other))) {
                        continue; // `skip`: tables never entered (hub tables, see graph_adapter.hubTables)
                    }
                    if (ids.size >= maxTables) {
                        truncated = true;
                        break;
                    }
                    ids.add(other);
                    depthOf.set(other, hop);
                    next.push(other);
                }
                if (truncated) {
                    break;
                }
            }
            if (truncated) {
                break;
            }
            frontier = next;
        }
        return { ids, depthOf, truncated };
    }

    /** Relations whose both endpoints are inside `tableIds` (and pass the kind filter). */
    relationsWithin(tableIds, kinds = "both") {
        const out = [];
        for (const rel of this.relations.values()) {
            if (tableIds.has(rel.source) && tableIds.has(rel.target) && this.relationAllowed(rel, kinds)) {
                out.push(rel);
            }
        }
        return out;
    }

    // ---------------------------------------------------------------- local search (names only)
    /**
     * Instant client-side search on table name and schema (column names are searched server-side —
     * they are not part of the index payload). Prefix matches rank before substring matches.
     */
    searchLocal(query, limit = 20) {
        const q = (query || "").trim().toLowerCase();
        if (!q) {
            return [];
        }
        const prefix = [];
        const inner = [];
        for (const t of this.tables.values()) {
            const name = t.name.toLowerCase();
            if (name.startsWith(q)) {
                prefix.push(t);
            } else if (t._q.includes(q)) {
                inner.push(t);
            }
        }
        const byName = (a, b) => a.name.localeCompare(b.name);
        prefix.sort(byName);
        inner.sort(byName);
        return prefix.concat(inner).slice(0, limit).map((t) => ({
            tableId: t.id,
            kind: t.name.toLowerCase().includes(q) ? "table" : "schema",
            text: t.schema ? `${t.schema}.${t.name}` : t.name,
        }));
    }

    /** The most connected tables — a sensible default focus for a large schema. */
    hubs(limit = 1, kinds = "both") {
        return [...this.tables.keys()]
            .map((id) => [id, this.degree(id, kinds)])
            .sort((a, b) => b[1] - a[1] || a[0] - b[0])
            .slice(0, limit)
            .map(([id]) => id);
    }
}

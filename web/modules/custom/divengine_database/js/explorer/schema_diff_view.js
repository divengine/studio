/* Schema diff presentation — pure functions over the structured SchemaDiff returned by the server
 * (models/schema_diff.py). Never computes differences: it only filters, groups and phrases them.
 * The change list, the in-app report and the Markdown export all come from here, so the report is not a
 * second calculation pipeline.
 */

export const TYPE_LABEL = Object.freeze({
    table: "Table", column: "Column", primary_key: "Primary key", index: "Index",
    foreign_key: "Foreign key", implied_relation: "Implied relation", routine: "Routine",
});

export const TYPE_GROUPS = Object.freeze({
    tables: ["table"],
    columns: ["column"],
    relationships: ["foreign_key", "implied_relation"],
    indexes: ["index", "primary_key"],
    routines: ["routine"],
});

export const CHANGE_SIGN = Object.freeze({ added: "+", removed: "−", modified: "~", unchanged: "=" });

export const SEVERITY_LABEL = Object.freeze({
    destructive: "Destructive", compatibility: "Compatibility", structural: "Structural",
    additive: "Additive", cosmetic: "Cosmetic",
});

/** Filter diff objects. filters = {change: 'all'|'added'|'removed'|'modified', groups: Set|null, query}. */
export function filterObjects(objects, { change = "all", groups = null, query = "" } = {}) {
    const types = groups && groups.size ? new Set([...groups].flatMap((g) => TYPE_GROUPS[g] || [])) : null;
    const q = (query || "").trim().toLowerCase();
    return objects.filter((o) => (change === "all" || o.change === change)
        && (!types || types.has(o.type))
        && (!q || `${o.table || ""}.${o.name}`.toLowerCase().includes(q) || (o.target_table || "").toLowerCase().includes(q)));
}

/**
 * Change tree grouped by table: [{key, table, change, tableObject, children[], counts}] + a 'routines'
 * group. A table whose own record is unchanged but has changed children is shown as MODIFIED.
 */
export function buildTree(objects) {
    const byTable = new Map();
    const loose = [];
    for (const o of objects) {
        if (!o.table) {
            loose.push(o);
            continue;
        }
        if (!byTable.has(o.table)) {
            byTable.set(o.table, { key: o.table, table: o.table, tableObject: null, children: [] });
        }
        const g = byTable.get(o.table);
        if (o.type === "table") {
            g.tableObject = o;
        } else {
            g.children.push(o);
        }
    }
    const groups = [...byTable.values()].map((g) => {
        const counts = { added: 0, removed: 0, modified: 0, unchanged: 0 };
        for (const c of g.children) {
            counts[c.change]++;
        }
        const own = g.tableObject ? g.tableObject.change : null;
        const change = own && own !== "unchanged" ? own
            : (counts.added || counts.removed || counts.modified ? "modified" : "unchanged");
        return { ...g, change, counts };
    });
    groups.sort((a, b) => a.table.localeCompare(b.table));
    if (loose.length) {
        groups.push({ key: "__routines__", table: "Routines", tableObject: null, children: loose,
            change: "modified", counts: {}, isRoutines: true });
    }
    return groups;
}

// ------------------------------------------------------------------------------------ phrasing
function fmt(value) {
    if (value === null || value === undefined) {
        return "∅";
    }
    if (typeof value === "boolean") {
        return value ? "yes" : "no";
    }
    if (Array.isArray(value)) {
        return `(${value.join(", ")})`;
    }
    if (typeof value === "object" && "has" in value) {
        return value.has ? `DEFAULT ${value.value === null ? "NULL" : value.value}` : "no default";
    }
    return String(value);
}

export function formatValue(value) {
    return fmt(value);
}

/** One changed property as text, e.g. "type: VARCHAR(50) → VARCHAR(100)". */
export function describeProperty(change) {
    const label = { nullable: "nullable", type: "type", default: "default", unique: "unique",
        on_delete_cascade: "on delete cascade", position: "position" }[change.property] || change.property;
    return `${label}: ${fmt(change.before)} → ${fmt(change.after)}`;
}

/** Display name of an object in the context of its table. */
export function objectLabel(o) {
    if (o.type === "foreign_key" || o.type === "implied_relation") {
        const p = o.after || o.before || {};
        return `${o.table}(${(p.child_columns || []).join(", ")}) → ${p.parent}(${(p.parent_columns || []).join(", ")})`;
    }
    if (o.type === "index") {
        const p = o.after || o.before || {};
        return `${o.name} (${(p.columns || []).join(", ")})${p.unique ? " UNIQUE" : ""}`;
    }
    if (o.type === "primary_key") {
        const p = o.after || o.before || {};
        return `PRIMARY KEY (${(p.display_columns || p.columns || []).join(", ")})`;
    }
    if (o.type === "column") {
        return `${o.table}.${o.name}`;
    }
    return o.name;
}

/** One-line summary of a change, e.g. "~ Column client.role — type: VARCHAR(50) → VARCHAR(100)". */
export function describeObject(o) {
    const head = `${CHANGE_SIGN[o.change]} ${TYPE_LABEL[o.type]} ${objectLabel(o)}`;
    if (o.change === "modified") {
        return `${head} — ${o.changes.map(describeProperty).join("; ")}`;
    }
    if (o.change === "added" && o.type === "column" && o.after) {
        return `${head} — ${o.after.type}${o.after.nullable ? "" : " NOT NULL"}`;
    }
    return head;
}

/** Ordered BEFORE/AFTER property rows for the detail panel (changed rows flagged). */
export function propertyRows(o) {
    const before = o.before || {};
    const after = o.after || {};
    const changed = new Set((o.changes || []).map((c) => c.property));
    const skip = new Set(["display_columns", "child", "parent"]);
    const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])].filter((k) => !skip.has(k));
    return keys.map((k) => ({
        property: k,
        before: o.before ? fmt(before[k]) : "—",
        after: o.after ? fmt(after[k]) : "—",
        changed: changed.has(k) || (o.change !== "modified" && o.change !== "unchanged"),
    }));
}

// ------------------------------------------------------------------------------------ report
/**
 * Report sections generated from the SchemaDiff (the in-app report and the Markdown export both render
 * these). Only changed objects; each item is a describeObject() line.
 */
export function buildReport(diff) {
    const changed = diff.objects.filter((o) => o.change !== "unchanged");
    const pick = (types, change) => changed.filter((o) => types.includes(o.type) && (!change || o.change === change));
    const lines = (list) => list.map(describeObject);
    const sections = [
        { title: "Tables added", items: lines(pick(["table"], "added")) },
        { title: "Tables removed", items: lines(pick(["table"], "removed")) },
        { title: "Tables modified", items: lines(pick(["table"], "modified")) },
        { title: "Column changes", items: lines(pick(["column"])) },
        { title: "Relationship changes", items: lines(pick(["foreign_key", "implied_relation"])) },
        { title: "Key and index changes", items: lines(pick(["primary_key", "index"])) },
        { title: "Routine changes", items: lines(pick(["routine"])) },
    ].filter((s) => s.items.length);
    const bySeverity = {};
    for (const o of changed) {
        if (o.severity) {
            (bySeverity[o.severity] = bySeverity[o.severity] || []).push(describeObject(o));
        }
    }
    const severityOrder = ["destructive", "compatibility", "structural", "additive", "cosmetic"];
    return {
        title: "Snapshot comparison",
        from: diff.from,
        to: diff.to,
        summary: diff.summary,
        notes: diff.notes || [],
        sections,
        risk: severityOrder.filter((s) => bySeverity[s]).map((s) => ({ severity: s, label: SEVERITY_LABEL[s], items: bySeverity[s] })),
        notCompared: diff.not_compared || [],
    };
}

function snapLine(s) {
    return `${s.key || s.id}${s.captured_at ? ` · ${s.captured_at}` : ""}${s.source_revision ? ` · rev ${String(s.source_revision).slice(0, 12)}` : ""}`;
}

/** Markdown export of the same report sections. */
export function toMarkdown(diff) {
    const r = buildReport(diff);
    const out = [`# ${r.title}`, "", `- **From:** ${snapLine(r.from)}`, `- **To:** ${snapLine(r.to)}`, ""];
    out.push("## Summary", "", "| Object | Added | Removed | Modified | Unchanged |", "|---|---:|---:|---:|---:|");
    for (const [type, c] of Object.entries(r.summary.by_type)) {
        if (c.added + c.removed + c.modified + c.unchanged) {
            out.push(`| ${TYPE_LABEL[type]} | ${c.added} | ${c.removed} | ${c.modified} | ${c.unchanged} |`);
        }
    }
    out.push("", `Compared ${r.summary.compared} objects · changed ${r.summary.changed} · unchanged ${r.summary.unchanged}`, "");
    for (const n of r.notes) {
        out.push(`> ${n}`, "");
    }
    if (r.risk.length) {
        out.push("## Technical impact (deterministic, not business impact)", "");
        for (const g of r.risk) {
            out.push(`### ${g.label} (${g.items.length})`, "", ...g.items.map((i) => `- ${i}`), "");
        }
    }
    for (const s of r.sections) {
        out.push(`## ${s.title} (${s.items.length})`, "", ...s.items.map((i) => `- ${i}`), "");
    }
    if (r.notCompared.length) {
        out.push(`_Not captured by the snapshot collector, therefore not compared: ${r.notCompared.join(", ")}._`, "");
    }
    return out.join("\n");
}

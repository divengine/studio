/* Schema Evolution — OWL page of a database (mounted by explorer.js).
 *
 * Diff (V1): pick FROM and TO snapshots, the server computes the structured SchemaDiff
 * (the diff endpoint -> SchemaDiff) and this page only presents it:
 * summary, filterable change tree, BEFORE/AFTER detail, report and JSON / Markdown export — all from the
 * same SchemaDiff object (schema_diff_view.js). "Open visual diff" hands the pair to the Schema Explorer,
 * which draws the union FROM ∪ TO with the same change annotations.
 *
 * Object History and Drift Detection are announced as future sections; they are not implemented.
 *
 * Params: {from_id, to_id}. Without them the newest ready snapshot and its predecessor are preselected.
 */
import {
    CHANGE_SIGN, SEVERITY_LABEL, TYPE_GROUPS, TYPE_LABEL, buildReport, buildTree, describeObject,
    filterObjects, objectLabel, propertyRows, toMarkdown,
} from "./schema_diff_view.js";

const { Component, onWillStart, useState } = window.owl;

const GROUP_LABEL = { tables: "Tables", columns: "Columns", relationships: "Relationships", indexes: "Keys & indexes", routines: "Routines" };
const TREE_OPEN_MAX = 40;  // expand every table group when the tree is this small

export class SchemaEvolution extends Component {
    static template = "divengine_database.SchemaEvolution";
    static props = ["*"];

    setup() {
        this.api = this.props.api;
        const params = this.props.params || {};
        this.groupKeys = Object.keys(TYPE_GROUPS);
        this.groupLabel = GROUP_LABEL;
        this.severityLabel = SEVERITY_LABEL;
        this.typeLabel = TYPE_LABEL;
        this.state = useState({
            section: "diff",            // diff | history | drift  (history / drift: future)
            phase: "idle",              // idle | loading | ready | error
            error: null,
            snapshotGroups: [],
            fromId: params.from_id || null,
            toId: params.to_id || null,
            diff: null,
            includeUnchanged: false,
            filters: { change: "all", groups: {}, query: "" },
            tab: "changes",             // changes | report
            selectedKey: null,
            open: {},                   // table group key -> expanded
        });

        onWillStart(async () => {
            try {
                this.state.snapshotGroups = await this.api.snapshots();
            } catch (e) {
                this._fail(e);
                return;
            }
            this._defaultPair();
            if (this.state.fromId && this.state.toId) {
                await this.compare();
            }
        });
    }

    // ------------------------------------------------------------------ snapshots
    _snapshot(id) {
        for (const g of this.state.snapshotGroups) {
            const s = g.snapshots.find((x) => x.id === id);
            if (s) {
                return { ...s, database: g.database, code: g.code };
            }
        }
        return null;
    }

    _defaultPair() {
        const s = this.state;
        if (s.toId && !s.fromId) {
            const to = this._snapshot(s.toId);
            s.fromId = to && to.previous_id;
        }
        if (!s.toId) {
            const first = s.snapshotGroups.find((g) => g.snapshots.length > 1) || s.snapshotGroups[0];
            if (first && first.snapshots.length) {
                s.toId = first.snapshots[0].id;
                s.fromId = first.snapshots[0].previous_id;
            }
        }
    }

    snapshotLabel(s) {
        return `${s.key}${s.captured_at ? `  ·  ${s.captured_at}` : ""}${s.source_revision ? `  ·  ${String(s.source_revision).slice(0, 10)}` : ""}`;
    }

    setFrom(ev) {
        this.state.fromId = Number(ev.target.value) || null;
    }

    setTo(ev) {
        this.state.toId = Number(ev.target.value) || null;
    }

    swap() {
        const s = this.state;
        [s.fromId, s.toId] = [s.toId, s.fromId];
        if (s.diff) {
            this.compare();
        }
    }

    /** FROM := the snapshot preceding TO in the same database. */
    compareWithPrevious() {
        const to = this._snapshot(this.state.toId);
        if (to && to.previous_id) {
            this.state.fromId = to.previous_id;
            this.compare();
        } else {
            this.state.error = "The selected TO snapshot has no earlier ready snapshot in its database.";
            this.state.phase = "error";
        }
    }

    get hasPrevious() {
        const to = this._snapshot(this.state.toId);
        return !!(to && to.previous_id);
    }

    get canCompare() {
        const s = this.state;
        return !!(s.fromId && s.toId && s.fromId !== s.toId);
    }

    // ------------------------------------------------------------------ diff
    async compare() {
        const s = this.state;
        if (!this.canCompare) {
            s.phase = "error";
            s.error = "Choose two different snapshots (FROM and TO).";
            return;
        }
        s.phase = "loading";
        s.error = null;
        try {
            const diff = await this.api.diff(s.fromId, s.toId, s.includeUnchanged);
            s.diff = diff;
            s.selectedKey = null;
            const tree = buildTree(diff.objects.filter((o) => o.change !== "unchanged"));
            s.open = tree.length <= TREE_OPEN_MAX ? Object.fromEntries(tree.map((g) => [g.key, true])) : {};
            s.phase = "ready";
        } catch (e) {
            this._fail(e);
        }
    }

    toggleUnchanged() {
        this.state.includeUnchanged = !this.state.includeUnchanged;
        if (this.state.diff) {
            this.compare();
        }
    }

    _fail(e) {
        this.state.phase = "error";
        this.state.error = (e && (e.data && e.data.message || e.message)) || String(e);
    }

    // ------------------------------------------------------------------ filters / tree
    setChangeFilter(change) {
        this.state.filters.change = change;
    }

    toggleGroup(g) {
        this.state.filters.groups[g] = !this.state.filters.groups[g];
    }

    onQuery(ev) {
        this.state.filters.query = ev.target.value;
    }

    get filtered() {
        const s = this.state;
        if (!s.diff) {
            return [];
        }
        const groups = new Set(Object.keys(s.filters.groups).filter((g) => s.filters.groups[g]));
        const objs = s.includeUnchanged ? s.diff.objects : s.diff.objects.filter((o) => o.change !== "unchanged");
        return filterObjects(objs, { change: s.filters.change, groups, query: s.filters.query });
    }

    get tree() {
        return buildTree(this.filtered);
    }

    toggleOpen(key) {
        this.state.open[key] = !this.state.open[key];
    }

    expandAll(on) {
        this.state.open = on ? Object.fromEntries(this.tree.map((g) => [g.key, true])) : {};
    }

    select(o) {
        this.state.selectedKey = o.key;
    }

    get selected() {
        const s = this.state;
        return s.diff && s.selectedKey ? s.diff.objects.find((o) => o.key === s.selectedKey) || null : null;
    }

    rows(o) {
        return propertyRows(o);
    }

    // ------------------------------------------------------------------ summary / report
    get summaryRows() {
        const by = (this.state.diff && this.state.diff.summary.by_type) || {};
        return Object.entries(by)
            .filter(([, c]) => c.added + c.removed + c.modified + c.unchanged)
            .map(([type, c]) => ({ type, label: TYPE_LABEL[type], ...c }));
    }

    get severityRows() {
        const by = (this.state.diff && this.state.diff.summary.by_severity) || {};
        return ["destructive", "compatibility", "structural", "additive", "cosmetic"]
            .filter((k) => by[k]).map((k) => ({ key: k, label: SEVERITY_LABEL[k], count: by[k] }));
    }

    get report() {
        return this.state.diff ? buildReport(this.state.diff) : null;
    }

    sign(change) {
        return CHANGE_SIGN[change] || "";
    }

    label(o) {
        return o.type === "column" ? o.name : objectLabel(o);
    }

    line(o) {
        return describeObject(o);
    }

    // ------------------------------------------------------------------ export / navigation
    _fileBase() {
        const d = this.state.diff;
        return `schema-diff_${d.from.database_code || "db"}_${d.from.key}_to_${d.to.key}`;
    }

    _download(name, mime, text) {
        const url = URL.createObjectURL(new Blob([text], { type: mime }));
        const a = document.createElement("a");
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 0);
    }

    exportJson() {
        if (this.state.diff) {
            this._download(`${this._fileBase()}.json`, "application/json", JSON.stringify(this.state.diff, null, 2));
        }
    }

    exportMarkdown() {
        if (this.state.diff) {
            this._download(`${this._fileBase()}.md`, "text/markdown", toMarkdown(this.state.diff));
        }
    }

    openVisualDiff() {
        const s = this.state;
        if (!this.canCompare) {
            return;
        }
        this.api.go("diffExplorer", { from: s.fromId, to: s.toId });
    }

    /** The table page of the object on one side ('from' | 'to'); navigation only, never identity. */
    recordUrl(o, side) {
        const ids = o.ids && o.ids[side];
        const snapshot = side === "from" ? this.state.diff.from.id : this.state.diff.to.id;
        if (!ids || !ids.table) {
            return null;
        }
        const anchor = { column: "#columns", index: "#indexes", primary_key: "#columns", foreign_key: "#relationships", implied_relation: "#relationships" }[o.type] || "";
        return this.api.url("table", { snapshot, table: ids.table }) + anchor;
    }

    openRecord(o, side) {
        const url = this.recordUrl(o, side);
        if (url) {
            window.open(url, "_blank");
        }
    }

    setSection(section) {
        this.state.section = section;
    }

    fmt(n) {
        return typeof n === "number" ? n.toLocaleString() : n;
    }
}


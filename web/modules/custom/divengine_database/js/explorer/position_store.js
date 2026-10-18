/* Manual table positions — VISUALIZATION STATE, kept strictly apart from schema facts.
 *
 * Positions are keyed by the logical database code + a "view signature" (scope, seeds, depth, direction,
 * relation kinds, density, layout direction) + the table's stable logical key. A manual arrangement is
 * therefore remembered for the view it was made in, and survives new snapshots of the same database
 * (logical keys are stable across snapshots), without ever touching the snapshot facts.
 *
 * Storage backend: the browser's localStorage (per user/browser). The interface (load/save/clear) is
 * the seam for a future server-side store (e.g. a diagram layout entity) — see the
 * module docs; that needs a new model and is intentionally not introduced here.
 */
const PREFIX = "divengine_database.schema_explorer.positions";

export function viewSignature(state, seedKeys, layoutDirection) {
    return [
        state.scope,
        [...seedKeys].sort().join(","),
        state.depth,
        state.direction,
        state.kinds,
        state.density,
        layoutDirection,
    ].join("|");
}

export class LocalPositionStore {
    constructor(storage = typeof window !== "undefined" ? window.localStorage : null) {
        this.storage = storage;
    }

    _key(databaseCode, signature) {
        return `${PREFIX}:${databaseCode}:${signature}`;
    }

    /** @returns {Object<string, {x:number,y:number}>} logicalKey -> position */
    load(databaseCode, signature) {
        try {
            const raw = this.storage && this.storage.getItem(this._key(databaseCode, signature));
            return raw ? JSON.parse(raw) : {};
        } catch {
            return {};
        }
    }

    save(databaseCode, signature, logicalKey, position) {
        try {
            const all = this.load(databaseCode, signature);
            all[logicalKey] = { x: Math.round(position.x), y: Math.round(position.y) };
            this.storage && this.storage.setItem(this._key(databaseCode, signature), JSON.stringify(all));
        } catch {
            // storage full / disabled: positions are a convenience, never an error
        }
    }

    clear(databaseCode, signature) {
        try {
            this.storage && this.storage.removeItem(this._key(databaseCode, signature));
        } catch {
            // ignore
        }
    }
}

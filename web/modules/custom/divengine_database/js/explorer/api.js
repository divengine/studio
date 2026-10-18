/* Studio API client of the schema explorer and schema evolution pages.
 *
 * The pages pass their endpoints in drupalSettings.divengineDatabase; every call is a same-origin GET
 * that returns JSON, or throws an Error with the server's message.
 */
export class StudioApi {
    constructor(settings) {
        this.endpoints = settings.endpoints || {};
        this.urls = settings.urls || {};
    }

    async get(name, params = {}) {
        const url = new URL(this.endpoints[name], window.location.origin);
        for (const [key, value] of Object.entries(params)) {
            if (value !== undefined && value !== null) {
                url.searchParams.set(key, Array.isArray(value) ? value.join(",") : String(value));
            }
        }
        const response = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
        let body = null;
        try {
            body = await response.json();
        } catch {
            body = null;
        }
        if (!response.ok) {
            throw new Error((body && (body.message || body.error)) || `${response.status} ${response.statusText}`);
        }
        return body;
    }

    index(snapshotId) {
        return this.get("index", { snapshot: snapshotId });
    }

    details(snapshotId, tableIds) {
        return this.get("details", { snapshot: snapshotId, ids: tableIds });
    }

    search(snapshotId, query, limit = 30) {
        return this.get("search", { snapshot: snapshotId, q: query, limit });
    }

    snapshots() {
        return this.get("snapshots");
    }

    diff(fromId, toId, includeUnchanged = false) {
        return this.get("diff", { from: fromId, to: toId, unchanged: includeUnchanged ? 1 : 0 });
    }

    diffGraph(fromId, toId) {
        return this.get("diffGraph", { from: fromId, to: toId });
    }

    diffDetails(fromId, toId, tableIds) {
        return this.get("diffDetails", { from: fromId, to: toId, ids: tableIds });
    }

    /** A page URL from a pattern with {placeholders}. */
    url(name, values = {}) {
        let url = this.urls[name] || "";
        const query = {};
        for (const [key, value] of Object.entries(values)) {
            if (url.includes(`{${key}}`)) {
                url = url.replace(`{${key}}`, encodeURIComponent(value));
            } else if (value !== undefined && value !== null) {
                query[key] = value;
            }
        }
        const qs = new URLSearchParams(query).toString();
        return qs ? `${url}?${qs}` : url;
    }

    go(name, values = {}) {
        window.location.assign(this.url(name, values));
    }
}

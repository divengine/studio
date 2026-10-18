/* Graphics quality — the ONE place that decides how expensive the schema explorer's rendering is.
 *
 *     preset (AUTO/LOW/BALANCED/HIGH/ULTRA/CUSTOM)  -> GraphicsSettings (flat knobs, persisted for CUSTOM)
 *     GraphicsSettings                               -> resolvePolicy() -> RenderPolicy (renderer-ready)
 *
 * Only rendering cost and visual detail change. Never the data: the same tables, relations, filters,
 * search results and FKs exist at every level. A cheaper level draws less of each table (with an honest
 * "+N more" / counts), later, or without effects — it never hides or invents schema facts.
 *
 * Diagnostic ladder (keep presets meaningfully different): a rendering glitch that appears in ULTRA but
 * not in HIGH points at the ULTRA-only items (selection pulse, widest culling buffer, detailed minimap);
 * HIGH-only vs BALANCED points at shadows / related glow / edge animation / glass blur / richer LOD; and
 * so on. See PRESETS below — every knob is documented where it is defined.
 *
 * Pure JavaScript (no Drupal, no JointJS).
 */

export const LEVELS = ["low", "balanced", "high", "ultra"];
export const PRESET_CHOICES = ["auto", "low", "balanced", "high", "ultra", "custom"];
export const LEVEL_LABEL = Object.freeze({
    auto: "Auto", low: "Low", balanced: "Balanced", high: "High", ultra: "Ultra", custom: "Custom",
});

/*
 * Knobs (GraphicsSettings). Approximate cost order, most expensive first:
 *   background   'flat' | 'grid' | 'rich'   rich = full-screen radial gradients + backdrop-filter blur on
 *                                           the glass panels (blur re-composites the SVG under every panel
 *                                           on each pan frame — the most compositor-sensitive effect)
 *   glow         'off' | 'selected' | 'related'  SVG drop-shadow filter on the selected table (+ its
 *                                           related tables for 'related'); filters are re-rasterised on
 *                                           every repaint of that element
 *   shadows      bool   depth drop-shadow on emphasised tables (another filter pass)
 *   selectionPulse bool continuous stroke animation on the selected table (repaints it every frame)
 *   edgeAnimation bool  flowing dashes on emphasised relations only (a handful of paths)
 *   transitions  'off' | 'fast' | 'smooth'   CSS opacity transitions when emphasis changes (applied to
 *                                           every mounted cell at once)
 *   lod          'aggressive' | 'normal' | 'relaxed' | 'max'   zoom thresholds of the existing LOD
 *                                           (earlier/later switch to names-only and key-columns-only),
 *                                           structure-level placeholder bars, max rows per full table
 *   culling      'tight' | 'normal' | 'wide' | 'max'   viewport buffer around the screen + the graph size
 *                                           below which culling is not worth it
 *   detailedNodes number  visible tables up to which tables are laid out with ALL columns (x4 = key
 *                                           columns); beyond that only names + counts (layout geometry)
 *   relationLabels number max FK-name labels drawn on emphasised relations (0 = inspector only)
 *   columnTypes  bool   data type text in column rows (detail zoom)
 *   secondary    bool   per-row tooltips (nullability, defaults, comments…) — one <title> per row
 *   minimap      'off' | 'low' | 'normal' | 'detailed'
 */
export const PRESETS = Object.freeze({
    // Cheapest: nothing continuous, nothing filtered, fewest SVG nodes, tight culling.
    low: Object.freeze({
        background: "flat", glow: "off", shadows: false, selectionPulse: false, edgeAnimation: false,
        transitions: "off", lod: "aggressive", culling: "tight", detailedNodes: 30, relationLabels: 0,
        columnTypes: true, secondary: false, minimap: "low",
    }),
    // Default feel: one subtle glow on the selection, grid background, no continuous animation.
    balanced: Object.freeze({
        background: "grid", glow: "selected", shadows: false, selectionPulse: false, edgeAnimation: false,
        transitions: "fast", lod: "normal", culling: "normal", detailedNodes: 60, relationLabels: 8,
        columnTypes: true, secondary: true, minimap: "normal",
    }),
    // Richer emphasis: related tables glow + depth, flowing emphasised edges, glass panels, later LOD.
    high: Object.freeze({
        background: "rich", glow: "related", shadows: true, selectionPulse: false, edgeAnimation: true,
        transitions: "smooth", lod: "relaxed", culling: "wide", detailedNodes: 100, relationLabels: 24,
        columnTypes: true, secondary: true, minimap: "normal",
    }),
    // Everything: selection pulse, widest culling buffer, longest detail range, relation-line minimap.
    ultra: Object.freeze({
        background: "rich", glow: "related", shadows: true, selectionPulse: true, edgeAnimation: true,
        transitions: "smooth", lod: "max", culling: "max", detailedNodes: 160, relationLabels: 60,
        columnTypes: true, secondary: true, minimap: "detailed",
    }),
});

// Enum knob -> numbers the renderer / adapter use.
const LOD_TABLE = Object.freeze({
    //            names-only below | key columns below | placeholder bars | max rows (full table)
    aggressive: { structure: 0.5, detail: 1.0, structureBars: false, maxRows: 40 },
    normal: { structure: 0.35, detail: 0.75, structureBars: true, maxRows: 80 },
    relaxed: { structure: 0.28, detail: 0.6, structureBars: true, maxRows: 120 },
    max: { structure: 0.2, detail: 0.45, structureBars: true, maxRows: 200 },
});
const CULLING_TABLE = Object.freeze({
    //     viewport buffer (fraction of the screen) | no culling below N cells
    tight: { margin: 0.1, minCells: 60 },
    normal: { margin: 0.35, minCells: 150 },
    wide: { margin: 0.6, minCells: 250 },
    max: { margin: 0.9, minCells: 400 },
});

export const KNOB_OPTIONS = Object.freeze({
    background: ["flat", "grid", "rich"],
    glow: ["off", "selected", "related"],
    transitions: ["off", "fast", "smooth"],
    lod: Object.keys(LOD_TABLE),
    culling: Object.keys(CULLING_TABLE),
    minimap: ["off", "low", "normal", "detailed"],
    detailedNodes: [20, 30, 60, 100, 160, 250],
    relationLabels: [0, 8, 24, 60],
});

/** Settings for a preset; CUSTOM uses the stored knobs (validated), AUTO uses the resolved level. */
export function settingsFor(preset, { custom = null, autoLevel = "balanced" } = {}) {
    if (preset === "custom") {
        return sanitizeSettings(custom, PRESETS.balanced);
    }
    const level = preset === "auto" ? autoLevel : preset;
    return { ...(PRESETS[level] || PRESETS.balanced) };
}

/** Keep only known knobs with valid values (persisted data may be stale or hand-edited). */
export function sanitizeSettings(raw, base = PRESETS.balanced) {
    const out = { ...base };
    for (const [key, value] of Object.entries(raw || {})) {
        if (!(key in base)) {
            continue;
        }
        const allowed = KNOB_OPTIONS[key];
        if (typeof base[key] === "boolean") {
            out[key] = !!value;
        } else if (allowed && allowed.includes(value)) {
            out[key] = value;
        }
    }
    return out;
}

/** GraphicsSettings -> RenderPolicy (what the adapter/renderer consume). Pure. */
export function resolvePolicy(settings) {
    const s = sanitizeSettings(settings);
    const lod = LOD_TABLE[s.lod];
    return {
        effects: {
            glow: s.glow,
            shadows: s.shadows,
            selectionPulse: s.selectionPulse,
            edgeAnimation: s.edgeAnimation,
            transitions: s.transitions,
            background: s.background,
        },
        // Integrates with the EXISTING LOD (lod.js thresholds) — not a second detail system.
        lod: { structure: lod.structure, detail: lod.detail },
        markup: { structureBars: lod.structureBars, rowTooltips: s.secondary, columnTypes: s.columnTypes },
        // Layout geometry (graph adapter): applied when the graph is (re)laid out.
        geometry: { full: s.detailedNodes, structure: s.detailedNodes * 4, maxRows: lod.maxRows },
        culling: { ...CULLING_TABLE[s.culling] },
        labels: { relations: s.relationLabels },
        minimap: s.minimap,
    };
}

export function geometryKey(policy) {
    const g = policy.geometry;
    return `${g.full}/${g.structure}/${g.maxRows}`;
}

// ---------------------------------------------------------------------------------------------- AUTO
const AUTO_LEVELS = ["low", "balanced", "high"];   // AUTO never picks ULTRA (manual only)

export const AUTO_TUNING = Object.freeze({
    slowFrameMs: 28,        // EMA above this = struggling (< ~35 fps)
    fastFrameMs: 14,        // EMA below this = headroom (> ~70 fps)
    minSlowSamples: 45,     // frames of evidence before degrading (~1.5 s of activity)
    goodForMs: 5000,        // EMA must stay fast this long before upgrading
    settleMs: 1000,         // ignore frames right after a change (restyling cost is not a signal)
    degradeCooldownMs: 4000,
    upgradeCooldownMs: 10000,
    upgradeAfterDegradeMs: 30000,  // after a degrade, wait long before trying to go up again
    emaAlpha: 0.1,
    maxFrameMs: 250,        // longer gaps = tab hidden / idle, not a render measurement
});

/** Upper bound for AUTO from graph complexity (visible tables + mounted SVG cells). */
export function complexityCap({ nodes = 0, cells = 0 } = {}) {
    if (nodes <= 80 && cells <= 400) {
        return "high";
    }
    if (nodes <= 400 && cells <= 1500) {
        return "balanced";
    }
    return "low";
}

/**
 * AUTO level controller with hysteresis. Pure: time is passed in, so it is deterministic in tests.
 *   - complexity sets a CAP (and the conservative initial level: never above balanced at start);
 *   - sustained slow frames degrade ONE level (cooldown), sustained fast frames upgrade ONE level
 *     (longer cooldown, and never soon after a degrade) — so it cannot flip every few frames.
 */
export class AutoQuality {
    constructor(tuning = AUTO_TUNING) {
        this.t = tuning;
        this.level = null;
        this.cap = "balanced";
        this.ema = null;
        this.samples = 0;
        this.goodSince = null;
        this.lastChange = -Infinity;
        this.lastDegrade = -Infinity;
        this.lastAction = "initial";
    }

    _idx(level) {
        return AUTO_LEVELS.indexOf(level);
    }

    _set(level, now, action) {
        const changed = level !== this.level;
        this.level = level;
        this.lastChange = now;
        this.ema = null;
        this.samples = 0;
        this.goodSince = null;
        this.lastAction = action;
        return changed;
    }

    /** Call after each (re)layout. Returns true when the level changed. */
    setComplexity(complexity, now) {
        this.cap = complexityCap(complexity);
        if (this.level === null) {
            const start = AUTO_LEVELS[Math.min(this._idx(this.cap), this._idx("balanced"))];
            return this._set(start, now, `start ${start} (graph ${complexity.nodes} tables / ${complexity.cells} cells)`);
        }
        if (this._idx(this.level) > this._idx(this.cap)) {
            return this._set(this.cap, now, `capped to ${this.cap} (graph size)`);
        }
        return false;
    }

    /** Feed one rAF frame interval. Returns true when the level changed. */
    addFrame(ms, now) {
        if (this.level === null || ms <= 0 || ms > this.t.maxFrameMs) {
            return false;
        }
        if (now - this.lastChange < this.t.settleMs) {
            return false;
        }
        this.ema = this.ema === null ? ms : this.ema + this.t.emaAlpha * (ms - this.ema);
        this.samples++;
        const i = this._idx(this.level);
        if (this.ema > this.t.slowFrameMs) {
            this.goodSince = null;
            if (i > 0 && this.samples >= this.t.minSlowSamples && now - this.lastChange >= this.t.degradeCooldownMs) {
                this.lastDegrade = now;
                return this._set(AUTO_LEVELS[i - 1], now,
                    `degraded ${this.level} → ${AUTO_LEVELS[i - 1]} (avg ${this.ema.toFixed(0)} ms/frame)`);
            }
            return false;
        }
        if (this.ema < this.t.fastFrameMs) {
            if (this.goodSince === null) {
                this.goodSince = now;
            }
            const canUp = i < this._idx(this.cap)
                && now - this.goodSince >= this.t.goodForMs
                && now - this.lastChange >= this.t.upgradeCooldownMs
                && now - this.lastDegrade >= this.t.upgradeAfterDegradeMs;
            if (canUp) {
                return this._set(AUTO_LEVELS[i + 1], now,
                    `upgraded ${this.level} → ${AUTO_LEVELS[i + 1]} (avg ${this.ema.toFixed(0)} ms/frame)`);
            }
            return false;
        }
        this.goodSince = null; // in between: hold
        return false;
    }

    fps() {
        return this.ema ? 1000 / this.ema : null;
    }
}

/**
 * Measures requestAnimationFrame intervals only while the user interacts (pan, zoom, hover, drag) and
 * for a short tail afterwards — no permanent rAF loop burning CPU on an idle page.
 */
export class FrameMeter {
    constructor(onFrame, { tailMs = 2000, raf, now } = {}) {
        this.onFrame = onFrame;
        this.tailMs = tailMs;
        this.raf = raf || ((cb) => requestAnimationFrame(cb));
        this.now = now || (() => performance.now());
        this.running = false;
        this.lastActivity = 0;
        this.prev = null;
        this.lastMs = null;
    }

    poke() {
        this.lastActivity = this.now();
        if (!this.running) {
            this.running = true;
            this.prev = null;
            this.raf((t) => this._tick(t));
        }
    }

    _tick(t) {
        if (this.prev !== null) {
            this.lastMs = t - this.prev;
            this.onFrame(this.lastMs, t);
        }
        this.prev = t;
        if (this.now() - this.lastActivity > this.tailMs) {
            this.running = false;
            return;
        }
        this.raf((t2) => this._tick(t2));
    }

    stop() {
        this.lastActivity = -Infinity;
    }
}

// ----------------------------------------------------------------------------------------- persistence
const STORE_KEY = "divengine_database.schema_explorer.graphics";

/** Per-browser preference (same convention as the manual-position store). AUTO is stored as AUTO. */
export class GraphicsPreferenceStore {
    constructor(storage = typeof window !== "undefined" ? window.localStorage : null) {
        this.storage = storage;
    }

    load() {
        try {
            const raw = this.storage && this.storage.getItem(STORE_KEY);
            const data = raw ? JSON.parse(raw) : {};
            return {
                preset: PRESET_CHOICES.includes(data.preset) ? data.preset : "auto",
                custom: data.custom ? sanitizeSettings(data.custom) : null,
            };
        } catch {
            return { preset: "auto", custom: null };
        }
    }

    save({ preset, custom }) {
        try {
            this.storage && this.storage.setItem(STORE_KEY, JSON.stringify({ preset, custom }));
        } catch {
            // preference only — never an error
        }
    }
}

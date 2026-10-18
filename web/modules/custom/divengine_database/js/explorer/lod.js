/* Zoom level-of-detail (LOD). Pure; shared by the renderer (what to draw) and the controller (HUD).
 *
 * LOD is decided by the on-screen scale only. It changes WHAT is drawn inside each table box, never the
 * box geometry (fixed by the graph adapter), so zooming never triggers a relayout.
 *
 *   LANDSCAPE  (< 0.35)  table name + counts only; no column rows, no ports, thin edges, no effects
 *   STRUCTURE  (< 0.75)  + key columns (PK/FK) as text, other rows as faint bars; ports visible
 *   DETAIL     (>= 0.75) every visible row with name, type, nullability, defaults (tooltips)
 */
export const LOD = Object.freeze({ LANDSCAPE: 0, STRUCTURE: 1, DETAIL: 2 });

export const LOD_LABEL = Object.freeze({ 0: "Landscape", 1: "Structure", 2: "Detail" });

export const LOD_THRESHOLDS = Object.freeze({ structure: 0.35, detail: 0.75 });

/** `thresholds` come from the graphics RenderPolicy (policy.lod); defaults = the Balanced values. */
export function lodForScale(scale, thresholds = LOD_THRESHOLDS) {
    if (scale < thresholds.structure) {
        return LOD.LANDSCAPE;
    }
    if (scale < thresholds.detail) {
        return LOD.STRUCTURE;
    }
    return LOD.DETAIL;
}

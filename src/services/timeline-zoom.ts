import { ref } from 'vue';

const STORAGE_KEY = 'memories_timeline_zoom';

/** Zoom steps around the default (0); positive shows more photos per row */
export const ZOOM_MIN = -2;
export const ZOOM_MAX = 4;

/** Column counts of the square grid on phones */
const MOBILE_COLS = [1, 2, 3, 4, 5, 7, 10];

/** Thumbnail size factor per zoom step on desktop */
const DESKTOP_STEP = 0.8;

const clamp = (zoom: number) => Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom));

function load(): number {
  try {
    const zoom = Number(localStorage.getItem(STORAGE_KEY));
    return Number.isInteger(zoom) ? clamp(zoom) : 0;
  } catch {
    return 0;
  }
}

/** Timeline zoom of this device */
export const timelineZoom = ref(load());

/** Set the zoom; false if it did not change */
export function setTimelineZoom(zoom: number): boolean {
  zoom = clamp(zoom);
  if (zoom === timelineZoom.value) return false;
  timelineZoom.value = zoom;
  try {
    localStorage.setItem(STORAGE_KEY, String(zoom));
  } catch {
    // Zoom is kept for this page load only
  }
  return true;
}

/** Number of columns on phones for the default column count */
export function zoomCols(baseCols: number): number {
  let base = MOBILE_COLS.findIndex((cols) => cols >= baseCols);
  if (base < 0) base = MOBILE_COLS.length - 1;
  const idx = Math.max(0, Math.min(MOBILE_COLS.length - 1, base + timelineZoom.value));
  return MOBILE_COLS[idx];
}

/** Thumbnail size on desktop for the default size */
export function zoomSize(baseSize: number): number {
  return Math.round(baseSize * DESKTOP_STEP ** timelineZoom.value);
}

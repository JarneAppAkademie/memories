import { ref } from 'vue';

import { dayIdToDate } from '@services/utils/date';

import type { IDay } from '@typings';

export interface IYearCount {
  year: number;
  count: number;
}

/** Years present in the main timeline, newest first */
export const timelineYears = ref<IYearCount[]>([]);

/** Year filter of the main timeline (?year=YYYY), or null */
export function routeYear(): number | null {
  if (_m.route.name !== _m.routes.Base.name) return null;
  const year = Number(_m.route.query.year);
  return Number.isInteger(year) && year > 0 ? year : null;
}

/** Count photos per year, newest first */
export function countYears(days: IDay[]): IYearCount[] {
  const counts = new Map<number, number>();
  for (const day of days) {
    if (!day.count) continue;
    const year = dayIdToDate(day.dayid).getUTCFullYear();
    counts.set(year, (counts.get(year) ?? 0) + day.count);
  }
  return Array.from(counts, ([year, count]) => ({ year, count })).sort((a, b) => b.year - a.year);
}

/** Keep only the days within the given year */
export function filterDaysByYear(days: IDay[], year: number): IDay[] {
  return days.filter((day) => dayIdToDate(day.dayid).getUTCFullYear() === year);
}

/** Open the year view, or the full timeline for null */
export function openYear(year: number | null) {
  const query = { ...(_m.route.query ?? {}) };
  if (year) query.year = String(year);
  else delete query.year;
  return _m.router.push({ name: _m.routes.Base.name, query, hash: '' });
}

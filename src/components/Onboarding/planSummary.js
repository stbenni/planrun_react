/**
 * Сводка готового плана для экрана «План готов» (мок BObReady):
 * недели, число тренировок, пиковый объём, дни/нед, первая неделя точками.
 */

import { parseVolumeKm, parsePlanMetrics, formatWeekRange } from '../Calendar/v3/calV3';

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

export function buildPlanSummary(plan, formData) {
  const weeks = Array.isArray(plan?.weeks_data) ? plan.weeks_data.filter(Boolean) : [];
  if (!weeks.length) return null;

  const sorted = [...weeks].sort((a, b) => String(a.start_date || '').localeCompare(String(b.start_date || '')));
  let workouts = 0;
  let peakKm = 0;
  let daysSum = 0;

  for (const w of sorted) {
    peakKm = Math.max(peakKm, parseVolumeKm(w.total_volume));
    for (const k of DAY_KEYS) {
      const raw = w.days?.[k];
      const items = (Array.isArray(raw) ? raw : raw ? [raw] : []).filter((d) => d && d.type && d.type !== 'rest');
      if (items.length) {
        daysSum += 1;
        workouts += items.length;
      }
    }
  }

  const daysPerWeek = formData?.preferred_days?.length
    || Math.round(daysSum / sorted.length) || null;

  const first = sorted[0];
  const firstWeek = DAY_KEYS.map((k) => {
    const raw = first?.days?.[k];
    const items = (Array.isArray(raw) ? raw : raw ? [raw] : []).filter((d) => d && d.type && d.type !== 'rest');
    const main = items[0];
    if (!main) return { key: k, rest: true, km: null };
    const m = parsePlanMetrics(main.description ?? main.text ?? '');
    return { key: k, rest: false, km: m.km ? Math.round(m.km) : null };
  });

  return {
    weeksTotal: sorted.length,
    workouts,
    peakKm: Math.round(peakKm) || null,
    daysPerWeek,
    firstWeek,
    firstWeekRange: first?.start_date ? formatWeekRange(first.start_date) : null,
  };
}

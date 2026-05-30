/**
 * useCoachStore — состояние тренерского workspace.
 * - Хранит атлетов и группы (загруженные из API).
 * - UI-state: view, выбранные, фильтр, активный атлет, modal'ы.
 * - Селекторы для KPI и фильтрованного списка.
 *
 * Фаза 1 (каркас): только load + UI-state, без bulk-actions и events.
 */

import { create } from 'zustand';

/** Сколько дней назад была дата (Infinity если null/invalid). */
function daysSince(dateStr) {
  if (!dateStr) return Infinity;
  const t = Date.parse(dateStr);
  if (Number.isNaN(t)) return Infinity;
  return Math.floor((Date.now() - t) / 86400000);
}

/** Атлет «требует внимания», если compliance < 50% или >7 дней без активности. */
function isAtRisk(athlete) {
  const total = Number(athlete.week_total || 0);
  const done = Number(athlete.week_completed || 0);
  const compliance = total > 0 ? done / total : null;
  const inactiveDays = daysSince(athlete.last_activity);
  if (compliance != null && compliance < 0.5) return true;
  if (inactiveDays > 7) return true;
  return false;
}

/** Atlet'ы со «свежей» активностью сегодня. */
function hasFreshUpload(athlete) {
  return daysSince(athlete.last_activity) === 0;
}

const useCoachStore = create((set, get) => ({
  // Server data
  athletes: [],
  groups: [],
  templates: [],
  events: [],
  requestsCount: 0,

  // UI state
  loading: true,
  loadError: null,
  view: 'table', // 'table' | 'grid' | 'stream'
  selected: new Set(),
  filterGroup: 'all', // 'all' | groupId | 'risk' | 'fresh'
  activeAthleteId: null,
  bulkAssignOpen: false,

  // Actions
  async loadAll(api) {
    if (!api) return;
    set({ loading: true, loadError: null });
    try {
      const [athRes, reqRes, grpRes, tplRes, evRes] = await Promise.all([
        api.getCoachAthletes(),
        api.getCoachRequests({ status: 'pending' }),
        api.getCoachGroups(),
        api.listWorkoutTemplates().catch(() => null),
        api.getCoachEvents(48).catch(() => null),
      ]);
      const athletes = athRes?.data?.athletes || athRes?.athletes || [];
      const reqs = reqRes?.data?.requests || reqRes?.requests || [];
      const groups = grpRes?.data?.groups || grpRes?.groups || [];
      const templates = tplRes?.data?.templates || tplRes?.templates || [];
      const events = evRes?.data?.events || evRes?.events || [];
      set({
        athletes,
        groups,
        templates,
        events,
        requestsCount: reqs.length,
        loading: false,
      });
    } catch (e) {
      console.error('useCoachStore.loadAll error:', e);
      set({ loading: false, loadError: e?.message || 'Ошибка загрузки' });
    }
  },

  async reloadEvents(api) {
    if (!api) return;
    try {
      const res = await api.getCoachEvents(48);
      const events = res?.data?.events || res?.events || [];
      set({ events });
    } catch (e) {
      console.error('reloadEvents error:', e);
    }
  },

  async reloadTemplates(api) {
    if (!api) return;
    try {
      const res = await api.listWorkoutTemplates();
      const templates = res?.data?.templates || res?.templates || [];
      set({ templates });
    } catch (e) {
      console.error('reloadTemplates error:', e);
    }
  },

  setView(view) {
    set({ view });
  },

  setFilterGroup(filterGroup) {
    set({ filterGroup });
  },

  setActiveAthleteId(id) {
    set({ activeAthleteId: id });
  },

  toggleSelected(id) {
    const next = new Set(get().selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    set({ selected: next });
  },

  selectMany(ids, on) {
    const next = new Set(get().selected);
    if (on) ids.forEach((i) => next.add(i));
    else ids.forEach((i) => next.delete(i));
    set({ selected: next });
  },

  clearSelected() {
    set({ selected: new Set() });
  },

  openBulkAssign() {
    set({ bulkAssignOpen: true });
  },

  closeBulkAssign() {
    set({ bulkAssignOpen: false });
  },
}));

// Селекторы экспортируются отдельно, чтобы не пересоздавать функции на каждый render.

export function selectFilteredAthletes(state) {
  const { athletes, filterGroup } = state;
  if (filterGroup === 'all') return athletes;
  if (filterGroup === 'risk') return athletes.filter(isAtRisk);
  if (filterGroup === 'fresh') return athletes.filter(hasFreshUpload);
  return athletes.filter((a) => {
    const groups = Array.isArray(a.groups) ? a.groups : [];
    return groups.some((g) => String(g.id) === String(filterGroup));
  });
}

export function selectKpi(state) {
  const athletes = state.athletes;
  const events = Array.isArray(state.events) ? state.events : [];
  const risk = athletes.filter(isAtRisk).length;
  const fresh = athletes.filter(hasFreshUpload).length;
  const questions = events.filter((e) => e.kind === 'question').length;
  let complianceSum = 0;
  let complianceN = 0;
  athletes.forEach((a) => {
    const total = Number(a.week_total || 0);
    if (total > 0) {
      complianceSum += Number(a.week_completed || 0) / total;
      complianceN += 1;
    }
  });
  const avgCompliance = complianceN > 0 ? Math.round((complianceSum / complianceN) * 100) : 0;
  return { risk, fresh, questions, avgCompliance };
}

export const coachHelpers = { daysSince, isAtRisk, hasFreshUpload };

export default useCoachStore;

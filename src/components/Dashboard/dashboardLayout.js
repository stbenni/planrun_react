import { DASHBOARD_MODULE_IDS, PAIRABLE_MODULE_IDS, STORAGE_KEY } from './dashboardConfig';

export function orderToLayout(order) {
  const rows = [];
  let index = 0;
  while (index < order.length) {
    const id = order[index];
    const nextId = order[index + 1];
    const canPair = PAIRABLE_MODULE_IDS.has(id) && nextId != null && PAIRABLE_MODULE_IDS.has(nextId);
    if (canPair) {
      rows.push([id, nextId]);
      index += 2;
      continue;
    }
    rows.push([id]);
    index += 1;
  }
  return rows;
}

export function getDefaultLayout() {
  return [
    ['today_workout', 'next_workout'],
    ['calendar'],
    ['stats'],
  ];
}

export function getStoredLayout() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return getDefaultLayout();
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed) || parsed.length === 0) return getDefaultLayout();
    const first = parsed[0];
    if (typeof first === 'string') {
      return orderToLayout(parsed.filter((id) => DASHBOARD_MODULE_IDS.includes(id)));
    }
    const layout = parsed.filter((row) => Array.isArray(row) && row.length >= 1 && row.length <= 2);
    const valid = layout.flat().filter((id) => DASHBOARD_MODULE_IDS.includes(id));
    const seen = new Set();
    const deduped = valid.filter((id) => !seen.has(id) && seen.add(id));
    const missing = DASHBOARD_MODULE_IDS.filter((id) => !deduped.includes(id));
    const fixed = layout
      .map((row) => row.filter((id) => DASHBOARD_MODULE_IDS.includes(id)).slice(0, 2))
      .filter((row) => row.length > 0);
    if (fixed.length === 0) return getDefaultLayout();
    const used = new Set(fixed.flat());
    for (const id of missing) {
      if (!used.has(id)) fixed.push([id]);
    }
    return fixed;
  } catch {
    return getDefaultLayout();
  }
}

export function layoutToOrder(layout) {
  return layout.flat();
}

export function saveLayout(layout) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(layout));
  } catch (error) {
    console.warn('Dashboard: could not save layout', error);
  }
}

export function layoutRemoveId(layout, id) {
  const next = [];
  for (const row of layout) {
    const filtered = row.filter((item) => item !== id);
    if (filtered.length > 0) next.push(filtered);
  }
  return next;
}

export function layoutInsertRow(layout, rowIndex, id) {
  return layout.slice(0, rowIndex).concat([[id]], layout.slice(rowIndex));
}

export function layoutMergeIntoRow(layout, targetRowIndex, id) {
  const row = layout[targetRowIndex];
  if (!row || row.length !== 1) return layout;
  const next = layout.slice();
  next[targetRowIndex] = [row[0], id];
  return next;
}

export function layoutExpandSlot(layout, rowIndex, slotIndex) {
  const row = layout[rowIndex];
  if (!row || row.length !== 2) return layout;
  const id = row[slotIndex];
  const other = row[1 - slotIndex];
  return layout.slice(0, rowIndex).concat([[other], [id]], layout.slice(rowIndex + 1));
}

export function expandLayoutForMobile(layout) {
  const result = [];
  for (const row of layout) {
    for (const id of row) result.push([id]);
  }
  return result;
}

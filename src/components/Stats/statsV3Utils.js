import { getDaysFromRange, formatPace, formatDateStr } from './StatsUtils';

const NON_RUN = new Set(['walking', 'hiking', 'cycling', 'biking', 'bike', 'swimming', 'swim', 'other', 'sbu']);
const WALK_TYPES = new Set(['walking', 'hiking']);
const dayMs = 24 * 60 * 60 * 1000;
const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

export function matchesSport(activityType, sport) {
  const t = String(activityType || 'running').toLowerCase().trim();
  if (sport === 'all') return true;
  if (sport === 'walk') return WALK_TYPES.has(t);
  if (sport === 'ofp') return t === 'other';
  if (sport === 'sbu') return t === 'sbu';
  return !NON_RUN.has(t); // run (по умолчанию) — только беговые типы
}

function bucketUnit(range) {
  if (range === 'week') return 'день';
  if (range === 'year') return 'мес';
  return 'нед';
}

function workoutDateStr(w) {
  if (w?.start_time) return String(w.start_time).split('T')[0];
  return w?.date || null;
}

function workoutSeconds(w) {
  if (w?.duration_seconds != null && w.duration_seconds > 0) return Number(w.duration_seconds);
  if (w?.duration_minutes != null && w.duration_minutes > 0) return Number(w.duration_minutes) * 60;
  return 0;
}

function km(w) {
  const v = parseFloat(w?.distance_km);
  return Number.isFinite(v) ? v : 0;
}

function mondayOf(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  const dow = d.getDay();
  d.setDate(d.getDate() - (dow === 0 ? 6 : dow - 1));
  return d;
}

const round1 = (arr) => arr.map((v) => Math.round(v * 10) / 10);

function planTypeMap(plan) {
  const map = {};
  const weeks = plan?.weeks_data;
  if (!Array.isArray(weeks)) return map;
  weeks.forEach((week) => {
    if (!week?.days || !week.start_date) return;
    const start = new Date(week.start_date + 'T00:00:00');
    DAY_KEYS.forEach((k, i) => {
      const day = week.days[k];
      if (day && day.type && day.type !== 'rest') {
        const dd = new Date(start);
        dd.setDate(start.getDate() + i);
        map[formatDateStr(dd)] = day.type;
      }
    });
  });
  return map;
}

function buildSeries(inRange, range, cutoff, rangeEnd) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  if (range === 'week') {
    const series = new Array(7).fill(0);
    inRange.forEach((w) => {
      const ds = workoutDateStr(w);
      if (!ds) return;
      const idx = Math.round((new Date(ds + 'T00:00:00') - cutoff) / dayMs);
      if (idx >= 0 && idx < 7) series[idx] += km(w);
    });
    let hi = Math.round((today - cutoff) / dayMs);
    hi = Math.max(0, Math.min(6, hi));
    return { series: round1(series), highlightIdx: hi };
  }

  if (range === 'month') {
    const first = new Date(cutoff.getFullYear(), cutoff.getMonth(), 1);
    const last = new Date(cutoff.getFullYear(), cutoff.getMonth() + 1, 0);
    const firstMonday = mondayOf(first);
    const weeks = Math.max(1, Math.ceil((last - firstMonday) / (7 * dayMs)));
    const series = new Array(weeks).fill(0);
    inRange.forEach((w) => {
      const ds = workoutDateStr(w);
      if (!ds) return;
      const idx = Math.floor((new Date(ds + 'T00:00:00') - firstMonday) / (7 * dayMs));
      if (idx >= 0 && idx < weeks) series[idx] += km(w);
    });
    let hi = Math.floor((today - firstMonday) / (7 * dayMs));
    hi = Math.max(0, Math.min(weeks - 1, hi));
    return { series: round1(series), highlightIdx: hi };
  }

  if (range === 'year') {
    const startY = cutoff.getFullYear();
    const startM = cutoff.getMonth();
    const series = new Array(12).fill(0);
    inRange.forEach((w) => {
      const ds = workoutDateStr(w);
      if (!ds) return;
      const d = new Date(ds + 'T00:00:00');
      const idx = (d.getFullYear() - startY) * 12 + (d.getMonth() - startM);
      if (idx >= 0 && idx < 12) series[idx] += km(w);
    });
    return { series: series.map((v) => Math.round(v)), highlightIdx: 11 };
  }

  const firstMonday = mondayOf(cutoff);
  const weeks = Math.max(1, Math.ceil((rangeEnd - firstMonday) / (7 * dayMs)));
  const series = new Array(weeks).fill(0);
  inRange.forEach((w) => {
    const ds = workoutDateStr(w);
    if (!ds) return;
    const idx = Math.floor((new Date(ds + 'T00:00:00') - firstMonday) / (7 * dayMs));
    if (idx >= 0 && idx < weeks) series[idx] += km(w);
  });
  return { series: round1(series), highlightIdx: weeks - 1 };
}

function sumKmInRange(workouts, from, to) {
  let sum = 0;
  workouts.forEach((w) => {
    const ds = workoutDateStr(w);
    if (!ds) return;
    const d = new Date(ds + 'T00:00:00');
    if (d >= from && d <= to) sum += km(w);
  });
  return Math.round(sum * 10) / 10;
}

export function processOverviewV3(workoutsList, plan, range, sport = 'run') {
  const list = Array.isArray(workoutsList) ? workoutsList : [];
  const types = planTypeMap(plan);
  const { days, startDate } = getDaysFromRange(range);
  const cutoff = new Date(startDate);
  cutoff.setHours(0, 0, 0, 0);
  const rangeEnd = new Date(cutoff);
  rangeEnd.setDate(rangeEnd.getDate() + Math.max(0, days - 1));
  rangeEnd.setHours(23, 59, 59, 999);

  const bySport = list.filter((w) => matchesSport(w?.activity_type, sport));
  const inRange = bySport.filter((w) => {
    const ds = workoutDateStr(w);
    if (!ds) return false;
    const d = new Date(ds + 'T00:00:00');
    return d >= cutoff && d <= rangeEnd;
  });

  const totalDistance = Math.round(inRange.reduce((s, w) => s + km(w), 0) * 10) / 10;
  const totalSeconds = inRange.reduce((s, w) => s + workoutSeconds(w), 0);
  const totalTimeMin = Math.round(totalSeconds / 60);
  const totalWorkouts = inRange.length;

  let paceKm = 0;
  let paceSec = 0;
  inRange.forEach((w) => {
    const s = workoutSeconds(w);
    const d = km(w);
    if (s > 0 && d > 0) { paceKm += d; paceSec += s; }
  });
  const avgPaceSeconds = paceKm > 0 ? Math.round(paceSec / paceKm) : 0;

  const { series, highlightIdx } = buildSeries(inRange, range, cutoff, rangeEnd);
  const nonZero = series.filter((v) => v > 0);
  const avgPerBucket = nonZero.length
    ? Math.round((nonZero.reduce((s, v) => s + v, 0) / nonZero.length) * 10) / 10
    : 0;
  const bestBucket = series.length ? Math.max(...series) : 0;

  const prevEnd = new Date(cutoff.getTime() - 1);
  const prevStart = new Date(cutoff);
  prevStart.setDate(prevStart.getDate() - days);
  const prevTotal = sumKmInRange(bySport, prevStart, prevEnd);
  const deltaPct = prevTotal > 0
    ? Math.round(((totalDistance - prevTotal) / prevTotal) * 100)
    : (totalDistance > 0 ? null : 0);

  const dailyMap = {};
  inRange.forEach((w) => {
    const ds = workoutDateStr(w);
    if (!ds) return;
    if (!dailyMap[ds]) dailyMap[ds] = { distance: 0 };
    dailyMap[ds].distance += km(w);
  });
  const chartData = [];
  for (let i = 0; i < days; i++) {
    const date = new Date(cutoff);
    date.setDate(cutoff.getDate() + i);
    date.setHours(0, 0, 0, 0);
    const ds = formatDateStr(date);
    const dm = dailyMap[ds];
    chartData.push({ date: ds, distance: dm ? Math.round(dm.distance * 10) / 10 : 0 });
  }

  const heatMax = Math.max(1, ...chartData.map((dd) => dd.distance));
  const heat = chartData.map((dd) => {
    if (dd.distance <= 0) return 0;
    const r = dd.distance / heatMax;
    if (r < 0.34) return 1;
    if (r < 0.67) return 2;
    return 3;
  });
  const useHeat = range === 'week' || range === 'month';

  const recent = [...inRange]
    .sort((a, b) => String(workoutDateStr(b)).localeCompare(String(workoutDateStr(a))))
    .map((w) => {
      const ds = workoutDateStr(w);
      return {
        id: w.id,
        date: ds,
        start_time: w.start_time || (ds ? ds + 'T00:00:00' : null),
        distance_km: w.distance_km ?? 0,
        avg_pace: w.avg_pace ?? null,
        avg_heart_rate: w.avg_heart_rate ?? null,
        activity_type: w.activity_type || 'running',
        detected_type: w.detected_type || null,
        plan_type: types[ds] || null,
        is_manual: w.is_manual ?? false,
      };
    });

  return {
    totalDistance,
    totalTimeMin,
    totalWorkouts,
    avgPace: formatPace(avgPaceSeconds),
    series,
    highlightIdx,
    avgPerBucket,
    bestBucket,
    prevTotal,
    deltaPct,
    bucketUnit: bucketUnit(range),
    startLabel: range === 'week' ? 'ПН' : range === 'year' ? 'мес 1' : 'нед 1',
    endLabel: range === 'week' ? 'сегодня' : 'сейчас',
    chartData,
    heat,
    useHeat,
    recent,
    hasData: totalWorkouts > 0,
  };
}

export function formatHoursMinutes(totalMinutes) {
  const min = Math.max(0, Math.round(totalMinutes || 0));
  const h = Math.floor(min / 60);
  const m = min % 60;
  return `${h}:${String(m).padStart(2, '0')}`;
}

function carryForward(arr) {
  const out = [];
  let last = null;
  arr.forEach((v) => { if (v != null) last = v; out.push(last); });
  let offset = 0;
  while (offset < out.length && out[offset] == null) offset += 1;
  return { data: out.slice(offset), offset };
}

export function vdotEstimate(distanceKm, seconds) {
  const d = Number(distanceKm);
  const t = Number(seconds);
  if (!(d > 0) || !(t > 0)) return null;
  const tMin = t / 60;
  const v = (d * 1000) / tMin;
  const vo2 = -4.60 + 0.182258 * v + 0.000104 * v * v;
  const pct = 0.8 + 0.1894393 * Math.exp(-0.012778 * tMin) + 0.2989558 * Math.exp(-0.1932605 * tMin);
  const vdot = vo2 / pct;
  return Number.isFinite(vdot) && vdot > 0 ? vdot : null;
}

function ymd(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function trendXLabels(weeks) {
  return [`${weeks} нед`, `${Math.round((weeks * 2) / 3)} нед`, `${Math.round(weeks / 3)} нед`, 'сейчас'];
}

export function processTrendsV3(workoutsList, sport = 'run', weeks = 12) {
  const list = (Array.isArray(workoutsList) ? workoutsList : [])
    .filter((w) => matchesSport(w?.activity_type, sport));

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const firstMonday = mondayOf(today);
  firstMonday.setDate(firstMonday.getDate() - (weeks - 1) * 7);

  const wk = Array.from({ length: weeks }, () => ({ km: 0, sec: 0, hrSum: 0, hrCnt: 0, vdot: 0 }));
  list.forEach((w) => {
    const ds = workoutDateStr(w);
    if (!ds) return;
    const idx = Math.floor((new Date(ds + 'T00:00:00') - firstMonday) / (7 * dayMs));
    if (idx < 0 || idx >= weeks) return;
    const b = wk[idx];
    const dist = km(w);
    const sec = workoutSeconds(w);
    b.km += dist;
    b.sec += sec;
    const hr = Number(w?.avg_heart_rate);
    if (Number.isFinite(hr) && hr > 0) { b.hrSum += hr; b.hrCnt += 1; }
    if (dist >= 2) {
      const vd = vdotEstimate(dist, sec);
      if (vd && vd > b.vdot) b.vdot = vd;
    }
  });

  const weekDates = wk.map((_, i) => {
    const d = new Date(firstMonday);
    d.setDate(firstMonday.getDate() + i * 7);
    return ymd(d);
  });
  const xLabels = trendXLabels(weeks);
  const volume = wk.map((b) => Math.round(b.km));
  const paceRaw = wk.map((b) => (b.km > 0 && b.sec > 0 ? Math.round(b.sec / b.km) : null));
  const hrRaw = wk.map((b) => (b.hrCnt > 0 ? Math.round(b.hrSum / b.hrCnt) : null));
  const vdotRaw = wk.map((b) => (b.vdot > 0 ? Math.round(b.vdot) : null));

  const metrics = [];

  if (vdotRaw.filter((v) => v != null).length >= 2) {
    const cf = carryForward(vdotRaw);
    const last = cf.data[cf.data.length - 1];
    const first = cf.data[0];
    metrics.push({
      key: 'vdot', label: 'VDOT', value: String(last), unit: '', data: cf.data,
      dates: weekDates.slice(cf.offset), isPace: false,
      delta: `${last - first >= 0 ? '+' : ''}${last - first}`, deltaLabel: 'за период',
      good: last - first >= 0, color: 'var(--primary-500)', startLbl: `было ${first}`,
      goal: null, goalLbl: null, xLabels,
    });
  }

  if (paceRaw.filter((v) => v != null).length >= 3) {
    const cf = carryForward(paceRaw);
    const last = cf.data[cf.data.length - 1];
    const first = cf.data[0];
    const diff = first - last;
    metrics.push({
      key: 'pace', label: 'Темп (сред.)', value: formatPace(last), unit: '/км', data: cf.data,
      dates: weekDates.slice(cf.offset), isPace: true,
      delta: `${diff >= 0 ? '−' : '+'}${Math.abs(diff)}с`, deltaLabel: diff >= 0 ? 'быстрее' : 'медленнее',
      good: diff >= 0, color: 'var(--success-500)', startLbl: `было ${formatPace(first)}`,
      goal: null, goalLbl: null, xLabels,
    });
  }

  const volNonZero = volume.filter((v) => v > 0);
  if (volNonZero.length >= 2) {
    const last = volume[volume.length - 1];
    const firstNZ = volNonZero[0];
    metrics.push({
      key: 'volume', label: 'Объём / неделя', value: String(last), unit: 'км', data: volume,
      dates: weekDates, isPace: false,
      delta: `${last - firstNZ >= 0 ? '+' : ''}${last - firstNZ}`, deltaLabel: 'км',
      good: last - firstNZ >= 0, color: 'var(--info-500, #3B82F6)', startLbl: `было ${firstNZ}`,
      goal: null, goalLbl: null, xLabels,
    });
  }

  if (hrRaw.filter((v) => v != null).length >= 3) {
    const cf = carryForward(hrRaw);
    const last = cf.data[cf.data.length - 1];
    const first = cf.data[0];
    metrics.push({
      key: 'hr', label: 'Ср. пульс / неделя', value: String(last), unit: 'уд/м', data: cf.data,
      dates: weekDates.slice(cf.offset), isPace: false,
      delta: `${last - first >= 0 ? '+' : '−'}${Math.abs(last - first)}`, deltaLabel: 'уд/м',
      good: last - first <= 0, color: 'var(--workout-control, #8B5CF6)', startLbl: `было ${first}`,
      goal: null, goalLbl: null, xLabels,
    });
  }

  return metrics;
}

export function processLoadV3(loadData, maxDays = 30) {
  if (!loadData?.available || !Array.isArray(loadData.daily) || loadData.daily.length === 0) {
    return { available: false };
  }
  const series = loadData.daily.slice(-maxDays);
  const cur = loadData.current || {};
  return {
    available: true,
    dates: series.map((d) => d.date),
    ctl: series.map((d) => Math.round(Number(d.ctl) || 0)),
    atl: series.map((d) => Math.round(Number(d.atl) || 0)),
    tsb: series.map((d) => Math.round(Number(d.tsb) || 0)),
    curCtl: Math.round(Number(cur.ctl) || 0),
    curAtl: Math.round(Number(cur.atl) || 0),
    curTsb: Math.round(Number(cur.tsb) || 0),
  };
}

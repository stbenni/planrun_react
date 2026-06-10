const DAY_MS = 24 * 60 * 60 * 1000;

function workoutDateStr(w) {
  if (w?.start_time) return String(w.start_time).split('T')[0];
  return w?.date || null;
}
function km(w) {
  const v = parseFloat(w?.distance_km);
  return Number.isFinite(v) ? v : 0;
}
function weekIndex(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const dow = d.getDay();
  d.setDate(d.getDate() - (dow === 0 ? 6 : dow - 1));
  d.setHours(0, 0, 0, 0);
  return Math.floor(d.getTime() / (7 * DAY_MS));
}

function longestRun(sortedUniqueInts) {
  let max = 0;
  let cur = 0;
  let prev = null;
  sortedUniqueInts.forEach((n) => {
    cur = (prev != null && n - prev === 1) ? cur + 1 : 1;
    prev = n;
    if (cur > max) max = cur;
  });
  return max;
}

const LEVELS = [
  { name: 'Новичок', min: 0 },
  { name: 'Бегун', min: 100 },
  { name: 'Атлет', min: 300 },
  { name: 'Чемпион', min: 600 },
];

function badgeDefs({ totalDistance, totalWorkouts, maxDayStreak, maxWeekStreak, vdot, best5k, bestHalf, hasPR, freshPR }) {
  return [
    {
      cat: 'Дистанция',
      badges: [
        { ic: '🥉', title: 'Первые 100 км', tier: 'bronze', pts: 10, dir: 'up', metric: totalDistance, t: 100 },
        { ic: '🥈', title: '500 км', tier: 'silver', pts: 25, dir: 'up', metric: totalDistance, t: 500 },
        { ic: '🥇', title: '1000 км', tier: 'gold', pts: 50, dir: 'up', metric: totalDistance, t: 1000 },
        { ic: '💎', title: '2500 км', tier: 'platinum', pts: 100, dir: 'up', metric: totalDistance, t: 2500 },
      ],
    },
    {
      cat: 'Постоянство',
      badges: [
        { ic: '🔥', title: 'Серия 7 дней', tier: 'bronze', pts: 10, dir: 'up', metric: maxDayStreak, t: 7 },
        { ic: '📅', title: '4 недели подряд', tier: 'silver', pts: 25, dir: 'up', metric: maxWeekStreak, t: 4 },
        { ic: '🏅', title: '100 тренировок', tier: 'gold', pts: 50, dir: 'up', metric: totalWorkouts, t: 100 },
        { ic: '🔥', title: 'Серия 30 дней', tier: 'gold', pts: 50, dir: 'up', metric: maxDayStreak, t: 30 },
      ],
    },
    {
      cat: 'Скорость и форма',
      badges: [
        { ic: '⚡', title: 'VDOT 50+', tier: 'silver', pts: 25, dir: 'up', metric: vdot, t: 50 },
        { ic: '📈', title: 'Личный рекорд', tier: 'bronze', pts: 10, dir: 'bool', got: hasPR, fresh: freshPR },
        { ic: '🚀', title: '5 км из 25:00', tier: 'gold', pts: 50, dir: 'down', metric: best5k, t: 25 * 60 },
        { ic: '🏔', title: 'Полу из 2:00', tier: 'platinum', pts: 100, dir: 'down', metric: bestHalf, t: 2 * 3600 },
      ],
    },
  ];
}

function resolveBadge(b) {
  let got = false;
  let pct = 0;
  if (b.dir === 'bool') {
    got = !!b.got;
    pct = got ? 1 : 0;
  } else if (b.dir === 'down') {
    got = b.metric > 0 && b.metric <= b.t;
    pct = b.metric > 0 ? Math.min(1, b.t / b.metric) : 0;
  } else {
    got = (b.metric || 0) >= b.t;
    pct = b.t > 0 ? Math.min(1, (b.metric || 0) / b.t) : 0;
  }
  return { ic: b.ic, title: b.title, tier: b.tier, pts: b.pts, got, pct, fresh: !!b.fresh && got };
}

export function computeAchievements({ workoutsList = [], vdot = null, records = null } = {}) {
  const list = Array.isArray(workoutsList) ? workoutsList : [];

  const totalDistance = Math.round(list.reduce((s, w) => s + km(w), 0));
  const totalWorkouts = list.length;

  const dates = [...new Set(list.map(workoutDateStr).filter(Boolean))].sort();
  const dayInts = dates.map((ds) => Math.floor(new Date(ds + 'T00:00:00').getTime() / DAY_MS));
  const maxDayStreak = longestRun([...new Set(dayInts)].sort((a, b) => a - b));
  const weekInts = [...new Set(dates.map(weekIndex))].sort((a, b) => a - b);
  const maxWeekStreak = longestRun(weekInts);

  const rec = records || {};
  const best5k = rec['5k']?.time_sec || 0;
  const bestHalf = rec.half?.time_sec || 0;
  const prList = Object.values(rec).filter((r) => r && r.time_sec > 0);
  const hasPR = prList.length > 0;
  const freshPR = prList.some((r) => {
    const t = r.date ? new Date(r.date + 'T00:00:00').getTime() : NaN;
    return Number.isFinite(t) && (Date.now() - t) < 14 * DAY_MS;
  });

  const defs = badgeDefs({
    totalDistance, totalWorkouts, maxDayStreak, maxWeekStreak,
    vdot: Number(vdot) || 0, best5k, bestHalf, hasPR, freshPR,
  });

  const categories = defs.map((c) => ({ cat: c.cat, badges: c.badges.map(resolveBadge) }));
  const all = categories.flatMap((c) => c.badges);
  const got = all.filter((b) => b.got);
  const totalPoints = got.reduce((s, b) => s + b.pts, 0);

  let levelIdx = 0;
  LEVELS.forEach((l, i) => { if (totalPoints >= l.min) levelIdx = i; });
  const level = LEVELS[levelIdx];
  const next = LEVELS[levelIdx + 1] || null;
  const pointsToNext = next ? Math.max(0, next.min - totalPoints) : 0;
  const progressPct = next
    ? Math.round(((totalPoints - level.min) / (next.min - level.min)) * 100)
    : 100;

  return {
    categories,
    totalPoints,
    gotCount: got.length,
    allCount: all.length,
    level: level.name,
    nextLevel: next?.name || null,
    pointsToNext,
    progressPct,
  };
}

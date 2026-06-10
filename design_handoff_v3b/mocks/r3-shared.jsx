// ============================================================
// PlanRun Redesign v3 — общие данные и примитивы
// ============================================================

const R3 = {
  user: { name: 'Иван', streak: 14 },
  today: {
    dow: 'Четверг', date: '11 июня', week: 'Неделя 24 · Build',
    type: 'Темповый бег', dist: '10 км', pace: '4:35', paceRange: '4:30–4:40', hrZone: 'Z3 · 152–164',
    durMin: 48,
    steps: [
      { n: '01', name: 'Разминка', detail: '2 км · 5:50–6:10 /км' },
      { n: '02', name: 'Темповый блок', detail: '6 км · 4:30–4:40 /км' },
      { n: '03', name: 'Заминка', detail: '2 км · 6:00 /км + растяжка' },
    ],
    why: 'Порог растёт. Держим контролируемый дискомфорт 26 минут — это даст +0,4 VDOT к концу цикла.',
  },
  week: [
    { d: 'Пн', label: 'Отдых', km: 0, state: 'rest' },
    { d: 'Вт', label: 'Интервалы', km: 9, state: 'done' },
    { d: 'Ср', label: 'Лёгкий', km: 8, state: 'done' },
    { d: 'Чт', label: 'Темповый', km: 10, state: 'today' },
    { d: 'Пт', label: 'Отдых', km: 0, state: 'rest' },
    { d: 'Сб', label: 'Длительная', km: 22, state: 'plan' },
    { d: 'Вс', label: 'Восстан.', km: 6, state: 'plan' },
  ],
  metrics: {
    form: 82, formLabel: 'Свежий',
    vdot: 52.1, vdotDelta: '+0.8',
    weekKm: 17, weekPlanKm: 55,
    rhr: 48, paceAvg: '5:12',
    loadSpark: [34, 42, 38, 51, 46, 55, 49, 58],
    paceSpark: [330, 326, 328, 321, 318, 319, 315, 312],
    vdotSpark: [49.8, 50.2, 50.1, 50.9, 51.2, 51.6, 51.8, 52.1],
  },
  goal: {
    race: 'Московский марафон', date: '04.10.2026', target: '3:29:59',
    daysLeft: 117, progress: 0.64, vdotNow: 52.1, vdotNeed: 53.4, predict: '3:34:10',
  },
  prs: [
    { dist: '5 км', time: '21:48' },
    { dist: '10 км', time: '45:32' },
    { dist: '21,1', time: '1:43:05' },
  ],
  athletes: [
    { id: 1, name: 'Алексей Петров', ini: 'АП', compl: 92, km: 48, plan: 55, vdot: 52.1, pace: '5:12', last: 'Темповый 10 км · 07:40', state: 'done', trend: [40, 44, 43, 48, 47, 52], risk: false },
    { id: 2, name: 'Мария Соколова', ini: 'МС', compl: 88, km: 39, plan: 42, vdot: 47.3, pace: '5:38', last: 'Лёгкий 8 км · вчера', state: 'today', trend: [30, 34, 36, 35, 38, 39], risk: false },
    { id: 3, name: 'Дмитрий Ким', ini: 'ДК', compl: 54, km: 18, plan: 46, vdot: 49.0, pace: '5:25', last: 'Пропуск · интервалы', state: 'missed', trend: [42, 38, 30, 26, 22, 18], risk: true },
    { id: 4, name: 'Анна Волкова', ini: 'АВ', compl: 96, km: 52, plan: 54, vdot: 54.6, pace: '4:48', last: 'Длительная 24 км · вс', state: 'done', trend: [44, 46, 49, 50, 51, 52], risk: false },
    { id: 5, name: 'Сергей Лебедев', ini: 'СЛ', compl: 71, km: 28, plan: 40, vdot: 45.2, pace: '5:55', last: 'Лёгкий 6 км · вт', state: 'today', trend: [30, 32, 29, 31, 28, 28], risk: false },
    { id: 6, name: 'Ольга Мороз', ini: 'ОМ', compl: 83, km: 35, plan: 42, vdot: 48.8, pace: '5:30', last: 'Фартлек 9 км · ср', state: 'done', trend: [28, 33, 34, 36, 35, 35], risk: false },
    { id: 7, name: 'Павел Громов', ini: 'ПГ', compl: 41, km: 12, plan: 38, vdot: 43.5, pace: '6:10', last: 'Нет данных 5 дней', state: 'missed', risk: true, trend: [30, 26, 22, 18, 14, 12] },
    { id: 8, name: 'Екатерина Юдина', ini: 'ЕЮ', compl: 90, km: 44, plan: 48, vdot: 50.4, pace: '5:05', last: 'Интервалы 6×800 · 06:55', state: 'done', trend: [36, 38, 41, 42, 43, 44], risk: false },
  ],
  events: [
    { t: '07:40', who: 'Алексей Петров', ini: 'АП', kind: 'done', text: 'Темповый 10 км — попал в зоны, ср. темп 4:34', meta: '10,2 км · 46:51 · ЧСС 158' },
    { t: '06:55', who: 'Екатерина Юдина', ini: 'ЕЮ', kind: 'pr', text: 'Интервалы 6×800 — личный рекорд отрезка: 3:08', meta: '9,4 км · ЧСС 172 макс' },
    { t: 'вчера', who: 'Дмитрий Ким', ini: 'ДК', kind: 'missed', text: 'Пропустил интервалы — 3-й пропуск за 2 недели', meta: 'Риск срыва цикла' },
    { t: 'вчера', who: 'Мария Соколова', ini: 'МС', kind: 'msg', text: '«Колено побаливает после длительной, перенести темповый?»', meta: 'Ждёт ответа 14 ч' },
    { t: 'вт', who: 'Павел Громов', ini: 'ПГ', kind: 'missed', text: 'Нет синхронизации 5 дней', meta: 'Garmin отключён' },
  ],
  coachKpi: { athletes: 8, compliance: 87, km: 276, risk: 2 },
};

// ---------- примитивы ----------

function R3Spark({ data, w = 72, h = 24, color = '#FF4D00', fill = 'none', sw = 2 }) {
  const min = Math.min(...data), max = Math.max(...data);
  const pts = data.map((v, i) => {
    const x = (i / (data.length - 1)) * (w - 2) + 1;
    const y = h - 2 - ((v - min) / (max - min || 1)) * (h - 4);
    return `${x},${y}`;
  });
  return (
    <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} style={{ display: 'block' }}>
      {fill !== 'none' && (
        <polygon points={`1,${h - 1} ${pts.join(' ')} ${w - 1},${h - 1}`} fill={fill} stroke="none"></polygon>
      )}
      <polyline points={pts.join(' ')} fill="none" stroke={color} strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round"></polyline>
    </svg>
  );
}

function R3Ring({ pct, size = 120, stroke = 10, color = '#FF4D00', track = 'rgba(127,127,127,0.18)', children, round = true }) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const [on, setOn] = React.useState(false);
  React.useEffect(() => { const t = setTimeout(() => setOn(true), 60); return () => clearTimeout(t); }, []);
  return (
    <div style={{ position: 'relative', width: size, height: size, flexShrink: 0 }}>
      <svg width={size} height={size} style={{ transform: 'rotate(-90deg)', display: 'block' }}>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={track} strokeWidth={stroke}></circle>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth={stroke}
          strokeLinecap={round ? 'round' : 'butt'}
          strokeDasharray={c}
          strokeDashoffset={on ? c * (1 - pct) : c}
          style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(.22,1,.36,1)' }}></circle>
      </svg>
      <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>{children}</div>
    </div>
  );
}

// Тонкие иконки
const R3Icon = {
  bell: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.7 21a2 2 0 0 1-3.4 0"></path>
    </svg>
  ),
  arrow: (c, s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M5 12h14M13 6l6 6-6 6"></path>
    </svg>
  ),
  check: (c, s = 16) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M4 12.5l5 5L20 6.5"></path>
    </svg>
  ),
  flame: (c, s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 22c4.4 0 7-2.8 7-6.5 0-2.5-1.4-4.7-3-6.5-.4 1.3-1.3 2.4-2.5 3C13.7 9 13 5.5 9.5 2c.3 3-1 5-2.6 6.8C5.3 10.6 5 12.4 5 13.9 5 19.2 7.6 22 12 22z"></path>
    </svg>
  ),
  search: (c, s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="2" strokeLinecap="round">
      <circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>
    </svg>
  ),
  plus: (c, s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="2.4" strokeLinecap="round">
      <path d="M12 5v14M5 12h14"></path>
    </svg>
  ),
  chat: (c, s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 12a8 8 0 0 1-8 8H4l2.2-2.7A8 8 0 1 1 21 12z"></path>
    </svg>
  ),
  home: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 10.5L12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path>
    </svg>
  ),
  cal: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.9" strokeLinecap="round">
      <rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 10h18M8 3v4M16 3v4"></path>
    </svg>
  ),
  stats: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="2" strokeLinecap="round">
      <path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path>
    </svg>
  ),
  run: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="15" cy="5" r="2.2"></circle><path d="M9 20l2.5-5L9 12l3-4 3 2.5 3 .5"></path><path d="M6 14l3-2M12 8L9 7 6.5 9"></path>
    </svg>
  ),
  dots: (c, s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill={c}>
      <circle cx="5" cy="12" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="19" cy="12" r="1.8"></circle>
    </svg>
  ),
  user: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="8" r="3.6"></circle><path d="M5 20.5a7 7 0 0 1 14 0"></path>
    </svg>
  ),
  gear: (c, s = 20) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="3.4"></circle>
      <path d="M12 2.5v2.8M12 18.7v2.8M2.5 12h2.8M18.7 12h2.8M5.2 5.2l2 2M16.8 16.8l2 2M18.8 5.2l-2 2M7.2 16.8l-2 2"></path>
    </svg>
  ),
};

// Бегущая строка (тикер)
function R3Ticker({ items, style, sep = '·', speed = 26 }) {
  const line = items.join(`  ${sep}  `) + `  ${sep}  `;
  return (
    <div style={{ overflow: 'hidden', whiteSpace: 'nowrap', ...style }}>
      <div className="r3-ticker-inner" style={{ display: 'inline-block', animation: `r3ticker ${speed}s linear infinite` }}>
        <span>{line}</span><span>{line}</span>
      </div>
    </div>
  );
}

// Навигация прототипа: no-op в канвасе, работает в PlanRun Prototype.html
const prNav = (id) => { if (window.__prNavigate) window.__prNavigate(id); };

Object.assign(window, { R3, R3Spark, R3Ring, R3Icon, R3Ticker, prNav });

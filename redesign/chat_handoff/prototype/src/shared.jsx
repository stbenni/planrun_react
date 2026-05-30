/* Shared: design tokens, fake data, helper components.
   All directions consume from this. */

// ── Tokens (matched to planrun sports-colors.css) ─────────────────────
window.PR_TOKENS = {
  primary: '#FC4C02',
  primary400: '#FF6B3D',
  primary600: '#E03D00',
  primary50: '#FFF4F0',
  success: '#22C55E',
  warning: '#EAB308',
  danger: '#EF4444',
  info: '#3B82F6',
  violet: '#8B5CF6',
  ink: '#0F172A',
  ink2: '#475569',
  ink3: '#64748B',
  ink4: '#94A3B8',
  line: '#E2E8F0',
  line2: '#CBD5E1',
  surf: '#FFFFFF',
  surf2: '#F8FAFC',
  surf3: '#F1F5F9',
  surf4: '#F4F7FB',
  // workout colors
  wEasy: '#22C55E',
  wTempo: '#EAB308',
  wInterval: '#EF4444',
  wLong: '#3B82F6',
  wControl: '#8B5CF6',
  wRest: '#A3A3A3',
  wSbu: '#8B5CF6',
};

window.PR_TYPE_LABEL = {
  easy: 'Лёгкий',
  long: 'Длительный',
  tempo: 'Темповый',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  control: 'Контрольный',
  race: 'Гонка',
  sbu: 'СБУ',
  other: 'ОФП',
  rest: 'Отдых',
};

window.PR_TYPE_COLOR = (t) => ({
  easy: PR_TOKENS.wEasy,
  long: PR_TOKENS.wLong,
  tempo: PR_TOKENS.wTempo,
  interval: PR_TOKENS.wInterval,
  fartlek: PR_TOKENS.wInterval,
  control: PR_TOKENS.wControl,
  race: PR_TOKENS.primary,
  sbu: PR_TOKENS.wSbu,
  other: PR_TOKENS.danger,
  rest: PR_TOKENS.wRest,
}[t] || PR_TOKENS.ink4);

// ── Fake athletes ──────────────────────────────────────────────────────
window.PR_ATHLETES = [
  {
    id: 1, name: 'Алексей Петров', initials: 'АП', tone: '#FFD9C9',
    goal: 'Марафон', target: '3:15', raceDate: '12 окт', daysToRace: 42,
    weekDone: 5, weekTotal: 5, compliance: 1.0, lastActivity: 'Сегодня',
    activityDays: 0, atRisk: false, freshUpload: true, paceTrend: '+2%',
    spark: [8,10,6,14,9,12,18], unread: 2, group: 'Марафон-осень',
    todayPlan: { type: 'tempo', label: '4×1 км', pace: '4:30', distance: 8 },
    week: [
      { type: 'easy', km: 8, status: 'done' },
      { type: 'tempo', km: 8, status: 'today' },
      { type: 'rest', km: 0, status: 'planned' },
      { type: 'easy', km: 10, status: 'planned' },
      { type: 'interval', km: 12, status: 'planned' },
      { type: 'rest', km: 0, status: 'planned' },
      { type: 'long', km: 22, status: 'planned' },
    ],
    note: 'Загрузил темповую на 12 сек/км быстрее плана',
  },
  {
    id: 2, name: 'Мария Соколова', initials: 'МС', tone: '#FFE0F0',
    goal: 'Полумарафон', target: '1:35', raceDate: '28 сен', daysToRace: 28,
    weekDone: 1, weekTotal: 4, compliance: 0.25, lastActivity: '3 дн. назад',
    activityDays: 3, atRisk: true, missed: 2, paceTrend: '−1%',
    spark: [6,8,4,0,0,5,0], unread: 0, group: 'Полумарафон',
    todayPlan: { type: 'easy', label: 'Восстановит.', pace: '6:00', distance: 6 },
    note: 'Пропустила 2 тренировки подряд',
  },
  {
    id: 3, name: 'Игорь Лебедев', initials: 'ИЛ', tone: '#D9E8FF',
    goal: '10 км', target: '38:00', raceDate: '5 ноя', daysToRace: 66,
    weekDone: 4, weekTotal: 4, compliance: 1.0, lastActivity: 'Вчера',
    activityDays: 1, freshUpload: true, paceTrend: '+5%',
    spark: [10,8,12,7,11,9,13], unread: 1, group: 'Спринт-группа',
    todayPlan: { type: 'interval', label: '6×400 м', pace: '3:40', distance: 7 },
    note: 'Спросил про подводку к гонке',
  },
  {
    id: 4, name: 'Анна Кузнецова', initials: 'АК', tone: '#D9F5E5',
    goal: 'Здоровье', target: null, raceDate: null, daysToRace: null,
    weekDone: 3, weekTotal: 4, compliance: 0.75, lastActivity: 'Сегодня',
    activityDays: 0, paceTrend: '0%',
    spark: [6,7,5,6,8,5,7], unread: 0, group: 'База',
    todayPlan: { type: 'easy', label: 'Лёгкий', pace: '5:50', distance: 7 },
  },
  {
    id: 5, name: 'Дмитрий Орлов', initials: 'ДО', tone: '#FFE9B3',
    goal: 'Марафон', target: '2:58', raceDate: '12 окт', daysToRace: 42,
    weekDone: 4, weekTotal: 5, compliance: 0.8, lastActivity: 'Сегодня',
    activityDays: 0, freshUpload: true, paceTrend: '+3%',
    spark: [12,14,10,16,11,15,20], unread: 0, group: 'Марафон-осень',
    todayPlan: { type: 'long', label: 'Длительный', pace: '5:10', distance: 28 },
    note: 'Длительная завтра — 28 км',
  },
  {
    id: 6, name: 'Екатерина Волкова', initials: 'ЕВ', tone: '#E8D9FF',
    goal: 'Полумарафон', target: '1:42', raceDate: '28 сен', daysToRace: 28,
    weekDone: 3, weekTotal: 5, compliance: 0.6, lastActivity: '2 дн. назад',
    activityDays: 2, paceTrend: '+1%',
    spark: [7,9,6,8,0,0,5], unread: 0, group: 'Полумарафон',
    todayPlan: { type: 'tempo', label: '3×1.5 км', pace: '4:45', distance: 8 },
  },
  {
    id: 7, name: 'Сергей Иванов', initials: 'СИ', tone: '#FFCDD2',
    goal: 'Марафон', target: '3:30', raceDate: '12 окт', daysToRace: 42,
    weekDone: 0, weekTotal: 5, compliance: 0.0, lastActivity: '9 дн. назад',
    activityDays: 9, atRisk: true, missed: 5, paceTrend: '—',
    spark: [4,0,0,0,0,0,0], unread: 3, group: 'Марафон-осень',
    todayPlan: { type: 'easy', label: 'Лёгкий', pace: '5:40', distance: 8 },
    note: '9 дней без активности',
  },
  {
    id: 8, name: 'Татьяна Морозова', initials: 'ТМ', tone: '#D9F0F5',
    goal: '10 км', target: '44:00', raceDate: '5 ноя', daysToRace: 66,
    weekDone: 4, weekTotal: 4, compliance: 1.0, lastActivity: 'Сегодня',
    activityDays: 0, freshUpload: true, paceTrend: '+4%',
    spark: [6,8,7,9,8,10,11], unread: 0, group: 'Спринт-группа',
    todayPlan: { type: 'rest', label: 'Отдых', pace: null, distance: 0 },
  },
  {
    id: 9, name: 'Павел Зайцев', initials: 'ПЗ', tone: '#FFD0B5',
    goal: 'Марафон', target: '3:00', raceDate: '12 окт', daysToRace: 42,
    weekDone: 3, weekTotal: 5, compliance: 0.6, lastActivity: 'Вчера',
    activityDays: 1, paceTrend: '+2%',
    spark: [10,12,8,11,9,0,14], unread: 0, group: 'Марафон-осень',
    todayPlan: { type: 'interval', label: '8×400 м', pace: '3:30', distance: 9 },
  },
  {
    id: 10, name: 'Олеся Никитина', initials: 'ОН', tone: '#FFE3D9',
    goal: 'Полумарафон', target: '1:50', raceDate: '28 сен', daysToRace: 28,
    weekDone: 4, weekTotal: 4, compliance: 1.0, lastActivity: 'Сегодня',
    activityDays: 0, paceTrend: '+6%',
    spark: [8,9,7,10,8,11,12], unread: 1, group: 'Полумарафон',
    todayPlan: { type: 'tempo', label: '5 км темпа', pace: '5:00', distance: 8 },
  },
  {
    id: 11, name: 'Артём Ковалёв', initials: 'АК', tone: '#D9D9FF',
    goal: '10 км', target: '40:00', raceDate: '5 ноя', daysToRace: 66,
    weekDone: 2, weekTotal: 3, compliance: 0.67, lastActivity: 'Вчера',
    activityDays: 1, paceTrend: '+1%',
    spark: [5,6,7,5,6,0,8], unread: 0, group: 'Спринт-группа',
    todayPlan: { type: 'rest', label: 'Отдых', pace: null, distance: 0 },
  },
  {
    id: 12, name: 'Юлия Беляева', initials: 'ЮБ', tone: '#FFD9E8',
    goal: 'Здоровье', target: null, raceDate: null, daysToRace: null,
    weekDone: 2, weekTotal: 3, compliance: 0.67, lastActivity: '2 дн. назад',
    activityDays: 2, paceTrend: '0%',
    spark: [4,5,3,4,0,0,3], unread: 0, group: 'База',
  },
];

// ── Coach inbox events (for Direction B) ───────────────────────────────
window.PR_EVENTS = [
  { id: 1, athleteId: 1, kind: 'upload', icon: '↑', tone: 'success',
    headline: 'Темповая 4×1 км завершена', detail: '8.2 км · 4:18 /км · −12 сек/км к плану',
    time: '12 мин назад', accent: 'Перевыполнил темп' },
  { id: 2, athleteId: 7, kind: 'miss', icon: '!', tone: 'danger',
    headline: 'Пропуск 3-ю тренировку подряд', detail: 'Последняя активность 9 дней назад',
    time: '2 ч назад', accent: 'Связаться' },
  { id: 3, athleteId: 3, kind: 'question', icon: '?', tone: 'info',
    headline: 'Спросил про подводку к 10 км', detail: '«Снижать ли объём за неделю до гонки?»',
    time: '3 ч назад', accent: 'Ответить' },
  { id: 4, athleteId: 2, kind: 'miss', icon: '!', tone: 'warn',
    headline: 'Пропустила интервалы (3-й день)', detail: 'Compliance 25% · план под угрозой',
    time: '5 ч назад', accent: 'Скорректировать план' },
  { id: 5, athleteId: 5, kind: 'upload', icon: '↑', tone: 'success',
    headline: 'Длительный 22 км', detail: '5:08 /км · ЧСС в зоне 2 · готовность 92%',
    time: 'вчера', accent: 'Молодец' },
  { id: 6, athleteId: 10, kind: 'pr', icon: '★', tone: 'primary',
    headline: 'Личный рекорд на 5 км', detail: '22:14 · −34 сек к прошлому PR',
    time: 'вчера', accent: 'Поздравить' },
  { id: 7, athleteId: 8, kind: 'upload', icon: '↑', tone: 'success',
    headline: 'Лёгкий 6 км', detail: '5:48 /км · ЧСС 138', time: 'вчера' },
  { id: 8, athleteId: 6, kind: 'question', icon: '?', tone: 'info',
    headline: 'Уточнила пейс на интервалах', detail: '«4:45 или 4:30?»', time: 'вчера' },
];

// ── Today (athlete view) ───────────────────────────────────────────────
window.PR_TODAY = {
  type: 'tempo',
  title: '4×1 км в темпе',
  date: 'Вт · 12 мая',
  distance: 8.0,
  pace: '4:30',
  durationMin: 42,
  segments: [
    { label: 'Разминка',      km: 1.5, pace: '5:30', type: 'easy' },
    { label: '1 км в темпе',  km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Восст. трусцой',km: 0.4, pace: '6:00', type: 'easy' },
    { label: '1 км в темпе',  km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Восст. трусцой',km: 0.4, pace: '6:00', type: 'easy' },
    { label: '1 км в темпе',  km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Восст. трусцой',km: 0.4, pace: '6:00', type: 'easy' },
    { label: '1 км в темпе',  km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Заминка',       km: 1.3, pace: '5:40', type: 'easy' },
  ],
};

window.PR_WEEK = [
  { day: 'ПН', date: 11, type: 'easy', km: 8,  label: 'Лёгкий 8 км',         status: 'done' },
  { day: 'ВТ', date: 12, type: 'tempo', km: 8, label: '4×1 км в темпе',      status: 'today' },
  { day: 'СР', date: 13, type: 'rest', km: 0,  label: 'Отдых',               status: 'planned' },
  { day: 'ЧТ', date: 14, type: 'easy', km: 10, label: 'Лёгкий 10 км',        status: 'planned' },
  { day: 'ПТ', date: 15, type: 'interval', km: 12, label: '6×800 м',         status: 'planned' },
  { day: 'СБ', date: 16, type: 'rest', km: 0,  label: 'Отдых',               status: 'planned' },
  { day: 'ВС', date: 17, type: 'long', km: 22, label: 'Длительный 22 км',    status: 'planned' },
];

window.PR_GOAL = {
  title: 'Москва · полумарафон',
  date: '28 сен',
  daysLeft: 28,
  target: '1:35:00',
  predicted: '1:36:42',
  trend: '−42 сек/нед',
  progress: 0.74,
  weeksTotal: 16,
  weeksDone: 12,
};

// ── helpers ────────────────────────────────────────────────────────────
window.PR_Sparkline = function Sparkline({ data, w = 80, h = 24, color = '#FC4C02', bg }) {
  const max = Math.max(...data, 1);
  const step = w / (data.length - 1);
  const pts = data.map((v, i) => [i * step, h - (v / max) * (h - 2) - 1]);
  const d = 'M ' + pts.map(p => p.join(' ')).join(' L ');
  const area = d + ` L ${w} ${h} L 0 ${h} Z`;
  return (
    <svg width={w} height={h} style={{ display: 'block', overflow: 'visible' }}>
      {bg && <path d={area} fill={color} opacity="0.12" />}
      <path d={d} stroke={color} strokeWidth="1.5" fill="none" strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={pts[pts.length-1][0]} cy={pts[pts.length-1][1]} r="2" fill={color} />
    </svg>
  );
};

window.PR_Avatar = function Avatar({ a, size = 36, ring }) {
  return (
    <div style={{
      width: size, height: size, borderRadius: '50%',
      background: a.tone,
      color: '#0F172A',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      fontWeight: 700, fontSize: size * 0.36,
      fontFamily: '"Jost", sans-serif', flexShrink: 0,
      boxShadow: ring ? `0 0 0 2px ${ring}, 0 0 0 4px white` : 'none',
      letterSpacing: '0.02em',
    }}>{a.initials}</div>
  );
};

window.PR_ComplianceBar = function ComplianceBar({ done, total, w = 60 }) {
  const pct = total ? done / total : 0;
  const color = pct >= 0.8 ? PR_TOKENS.success : pct >= 0.5 ? PR_TOKENS.warning : PR_TOKENS.danger;
  return (
    <div style={{ width: w }}>
      <div style={{ height: 4, background: PR_TOKENS.line, borderRadius: 999, overflow: 'hidden' }}>
        <div style={{ height: '100%', width: `${pct * 100}%`, background: color, borderRadius: 999 }} />
      </div>
    </div>
  );
};

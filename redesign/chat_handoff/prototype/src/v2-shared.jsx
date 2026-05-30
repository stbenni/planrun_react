/* v2-shared: расширенные данные + helpers для финального редизайна */

window.V2 = {
  // Tokens
  T: {
    primary: '#FC4C02', primary400: '#FF6B3D', primary600: '#E03D00',
    primaryWash: '#FFF4F0', primarySoft: '#FFE5D9',
    success: '#22C55E', successWash: '#DCFCE7',
    warning: '#EAB308', warningWash: '#FEF9C3',
    danger: '#EF4444', dangerWash: '#FEE2E2',
    info: '#3B82F6', infoWash: '#DBEAFE',
    violet: '#8B5CF6', violetWash: '#EDE9FE',
    ink: '#0F172A', ink2: '#475569', ink3: '#64748B', ink4: '#94A3B8',
    line: '#E2E8F0', line2: '#CBD5E1',
    surf: '#FFFFFF', surf2: '#F8FAFC', surf3: '#F1F5F9', surf4: '#F4F7FB',
  },
  TYPE_LABEL: {
    easy: 'Лёгкий', long: 'Длительный', tempo: 'Темповый',
    interval: 'Интервалы', fartlek: 'Фартлек', control: 'Контрольный',
    race: 'Гонка', sbu: 'СБУ', other: 'ОФП', rest: 'Отдых',
  },
};

V2.typeColor = (t) => ({
  easy: V2.T.success, long: V2.T.info, tempo: V2.T.warning,
  interval: V2.T.danger, fartlek: V2.T.danger, control: V2.T.violet,
  race: V2.T.primary, sbu: V2.T.violet, other: V2.T.danger, rest: '#A3A3A3',
}[t] || V2.T.ink4);

// ── Groups ────────────────────────────────────────────────────────────
V2.GROUPS = [
  { id: 'marathon', name: 'Марафон-осень', color: '#FC4C02', count: 5 },
  { id: 'half',     name: 'Полумарафон',   color: '#EC4899', count: 3 },
  { id: 'sprint',   name: 'Спринт 10k',    color: '#3B82F6', count: 3 },
  { id: 'base',     name: 'База',          color: '#22C55E', count: 1 },
];

// ── Athletes ──────────────────────────────────────────────────────────
V2.ATHLETES = [
  { id: 1, name: 'Алексей Петров',     initials: 'АП', tone: '#FFD9C9',
    goal: 'Марафон', target: '3:15', raceDate: '12 окт', daysToRace: 42,
    weekDone: 5, weekTotal: 5, compliance: 1.0, lastActivity: 'Сегодня', activityDays: 0,
    freshUpload: true, paceTrend: '+2%', vdot: 52, ftp: '4:08',
    spark: [8,10,6,14,9,12,18], unread: 2, group: 'marathon',
    todayPlan: { type: 'tempo', label: '4×1 км в темпе', pace: '4:30', distance: 8 },
    note: 'Загрузил темповую на 12 сек/км быстрее плана',
  },
  { id: 2, name: 'Мария Соколова',     initials: 'МС', tone: '#FFE0F0',
    goal: 'Полумарафон', target: '1:35', raceDate: '28 сен', daysToRace: 28,
    weekDone: 1, weekTotal: 4, compliance: 0.25, lastActivity: '3 дн. назад', activityDays: 3,
    atRisk: true, missed: 2, paceTrend: '−1%', vdot: 48, ftp: '4:32',
    spark: [6,8,4,0,0,5,0], unread: 0, group: 'half',
    todayPlan: { type: 'easy', label: 'Восстановит.', pace: '6:00', distance: 6 },
    note: 'Пропустила 2 тренировки подряд',
  },
  { id: 3, name: 'Игорь Лебедев',      initials: 'ИЛ', tone: '#D9E8FF',
    goal: '10 км', target: '38:00', raceDate: '5 ноя', daysToRace: 66,
    weekDone: 4, weekTotal: 4, compliance: 1.0, lastActivity: 'Вчера', activityDays: 1,
    freshUpload: true, paceTrend: '+5%', vdot: 55, ftp: '3:54',
    spark: [10,8,12,7,11,9,13], unread: 1, group: 'sprint',
    todayPlan: { type: 'interval', label: '6×400 м', pace: '3:40', distance: 7 },
    note: 'Спросил про подводку к гонке',
  },
  { id: 4, name: 'Анна Кузнецова',     initials: 'АК', tone: '#D9F5E5',
    goal: 'Здоровье', target: null, raceDate: null, daysToRace: null,
    weekDone: 3, weekTotal: 4, compliance: 0.75, lastActivity: 'Сегодня', activityDays: 0,
    paceTrend: '0%', vdot: 41, ftp: '5:18',
    spark: [6,7,5,6,8,5,7], unread: 0, group: 'base',
    todayPlan: { type: 'easy', label: 'Лёгкий', pace: '5:50', distance: 7 },
  },
  { id: 5, name: 'Дмитрий Орлов',      initials: 'ДО', tone: '#FFE9B3',
    goal: 'Марафон', target: '2:58', raceDate: '12 окт', daysToRace: 42,
    weekDone: 4, weekTotal: 5, compliance: 0.8, lastActivity: 'Сегодня', activityDays: 0,
    freshUpload: true, paceTrend: '+3%', vdot: 58, ftp: '3:42',
    spark: [12,14,10,16,11,15,20], unread: 0, group: 'marathon',
    todayPlan: { type: 'long', label: 'Длительный', pace: '5:10', distance: 28 },
    note: 'Длительная завтра — 28 км',
  },
  { id: 6, name: 'Екатерина Волкова',  initials: 'ЕВ', tone: '#E8D9FF',
    goal: 'Полумарафон', target: '1:42', raceDate: '28 сен', daysToRace: 28,
    weekDone: 3, weekTotal: 5, compliance: 0.6, lastActivity: '2 дн. назад', activityDays: 2,
    paceTrend: '+1%', vdot: 46, ftp: '4:48',
    spark: [7,9,6,8,0,0,5], unread: 0, group: 'half',
    todayPlan: { type: 'tempo', label: '3×1.5 км', pace: '4:45', distance: 8 },
  },
  { id: 7, name: 'Сергей Иванов',      initials: 'СИ', tone: '#FFCDD2',
    goal: 'Марафон', target: '3:30', raceDate: '12 окт', daysToRace: 42,
    weekDone: 0, weekTotal: 5, compliance: 0.0, lastActivity: '9 дн. назад', activityDays: 9,
    atRisk: true, missed: 5, paceTrend: '—', vdot: 44, ftp: '4:58',
    spark: [4,0,0,0,0,0,0], unread: 3, group: 'marathon',
    todayPlan: { type: 'easy', label: 'Лёгкий', pace: '5:40', distance: 8 },
    note: '9 дней без активности · 3 непрочитанных сообщения',
  },
  { id: 8, name: 'Татьяна Морозова',   initials: 'ТМ', tone: '#D9F0F5',
    goal: '10 км', target: '44:00', raceDate: '5 ноя', daysToRace: 66,
    weekDone: 4, weekTotal: 4, compliance: 1.0, lastActivity: 'Сегодня', activityDays: 0,
    freshUpload: true, paceTrend: '+4%', vdot: 49, ftp: '4:24',
    spark: [6,8,7,9,8,10,11], unread: 0, group: 'sprint',
    todayPlan: { type: 'rest', label: 'Отдых', pace: null, distance: 0 },
  },
  { id: 9, name: 'Павел Зайцев',       initials: 'ПЗ', tone: '#FFD0B5',
    goal: 'Марафон', target: '3:00', raceDate: '12 окт', daysToRace: 42,
    weekDone: 3, weekTotal: 5, compliance: 0.6, lastActivity: 'Вчера', activityDays: 1,
    paceTrend: '+2%', vdot: 54, ftp: '3:58',
    spark: [10,12,8,11,9,0,14], unread: 0, group: 'marathon',
    todayPlan: { type: 'interval', label: '8×400 м', pace: '3:30', distance: 9 },
  },
  { id:10, name: 'Олеся Никитина',     initials: 'ОН', tone: '#FFE3D9',
    goal: 'Полумарафон', target: '1:50', raceDate: '28 сен', daysToRace: 28,
    weekDone: 4, weekTotal: 4, compliance: 1.0, lastActivity: 'Сегодня', activityDays: 0,
    paceTrend: '+6%', vdot: 44, ftp: '5:02',
    spark: [8,9,7,10,8,11,12], unread: 1, group: 'half',
    todayPlan: { type: 'tempo', label: '5 км темпа', pace: '5:00', distance: 8 },
    note: 'PR 5 км · 22:14',
  },
  { id:11, name: 'Артём Ковалёв',      initials: 'АК2', tone: '#D9D9FF',
    goal: '10 км', target: '40:00', raceDate: '5 ноя', daysToRace: 66,
    weekDone: 2, weekTotal: 3, compliance: 0.67, lastActivity: 'Вчера', activityDays: 1,
    paceTrend: '+1%', vdot: 47, ftp: '4:34',
    spark: [5,6,7,5,6,0,8], unread: 0, group: 'sprint',
    todayPlan: { type: 'rest', label: 'Отдых', pace: null, distance: 0 },
  },
  { id:12, name: 'Юлия Беляева',       initials: 'ЮБ', tone: '#FFD9E8',
    goal: 'Здоровье', target: null, raceDate: null, daysToRace: null,
    weekDone: 2, weekTotal: 3, compliance: 0.67, lastActivity: '2 дн. назад', activityDays: 2,
    paceTrend: '0%', vdot: 38, ftp: '5:42',
    spark: [4,5,3,4,0,0,3], unread: 0, group: 'base',
  },
];

V2.athleteById = (id) => V2.ATHLETES.find(a => a.id === id);
V2.athletesInGroup = (groupId) => V2.ATHLETES.filter(a => a.group === groupId);

// ── Events ────────────────────────────────────────────────────────────
V2.EVENTS = [
  { id: 1, athleteId: 1, kind: 'upload', tone: 'success', time: '12 мин',
    title: 'Темповая 4×1 км завершена',
    detail: '8.2 км · 4:18 /км · −12 сек/км к плану', cta: 'Похвалить' },
  { id: 2, athleteId: 7, kind: 'risk', tone: 'danger', time: '2 ч',
    title: 'Пропуск 3-ю тренировку подряд',
    detail: 'Последняя активность 9 дней назад', cta: 'Связаться' },
  { id: 3, athleteId: 3, kind: 'question', tone: 'info', time: '3 ч',
    title: 'Вопрос про подводку',
    detail: '«Снижать ли объём за неделю до гонки?»', cta: 'Ответить' },
  { id: 4, athleteId: 2, kind: 'risk', tone: 'warn', time: '5 ч',
    title: 'Compliance 25% — план под угрозой',
    detail: 'Пропустила интервалы 3-й день', cta: 'Скорректировать план' },
  { id: 5, athleteId: 5, kind: 'upload', tone: 'success', time: 'вчера',
    title: 'Длительный 22 км',
    detail: '5:08 /км · ЧСС в зоне 2 · готовность 92%', cta: 'Молодец' },
  { id: 6, athleteId:10, kind: 'pr', tone: 'primary', time: 'вчера',
    title: 'Личный рекорд на 5 км',
    detail: '22:14 · −34 сек к прошлому PR', cta: 'Поздравить' },
  { id: 7, athleteId: 8, kind: 'upload', tone: 'success', time: 'вчера',
    title: 'Лёгкий 6 км',
    detail: '5:48 /км · ЧСС 138', cta: null },
  { id: 8, athleteId: 6, kind: 'question', tone: 'info', time: '2 дня',
    title: 'Уточнила пейс на интервалах',
    detail: '«4:45 или 4:30?»', cta: 'Ответить' },
];

// ── Workout templates ─────────────────────────────────────────────────
V2.TEMPLATES = [
  { id: 't1', name: 'Темповый 4×1 км',     type: 'tempo',    distance: 8,  emoji: '⚡',
    desc: 'Разм 1.5к + 4×(1к@4:30 / 400м трусца) + зам 1.3к', uses: 24 },
  { id: 't2', name: 'Интервалы 6×400 м',   type: 'interval', distance: 7,  emoji: '🔥',
    desc: 'Разм 2к + 6×(400м@3:40 / 200м трусца) + зам 1к', uses: 18 },
  { id: 't3', name: 'Лёгкий восстановительный', type: 'easy',     distance: 6,  emoji: '🟢',
    desc: '6 км @ 6:00 /км, ЧСС зона 2', uses: 42 },
  { id: 't4', name: 'Длительный 22 км',    type: 'long',     distance: 22, emoji: '🛣',
    desc: '22 км @ 5:10 /км, последние 5к в марафонском темпе', uses: 9 },
  { id: 't5', name: 'Фартлек 8 км',        type: 'fartlek',  distance: 8,  emoji: '🎲',
    desc: 'Разм 2к + 6×(2 мин быстро / 2 мин медленно) + зам', uses: 12 },
  { id: 't6', name: 'СБУ × 5 серий',       type: 'sbu',      distance: 0,  emoji: '🦵',
    desc: 'А-скип, B-скип, прыжки, выпады — 5×30м каждое', uses: 6 },
  { id: 't7', name: 'Отдых',               type: 'rest',     distance: 0,  emoji: '💤',
    desc: 'Полный день отдыха', uses: 36 },
];

// ── Today + Week + Goal (athlete view) ────────────────────────────────
V2.TODAY = {
  type: 'tempo', title: '4×1 км в темпе', date: 'Вт · 12 мая',
  distance: 8.0, pace: '4:30', durationMin: 42, hr: 165,
  segments: [
    { label: 'Разминка',       km: 1.5, pace: '5:30', type: 'easy' },
    { label: '1 км в темпе',   km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Восстановление', km: 0.4, pace: '6:00', type: 'easy' },
    { label: '1 км в темпе',   km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Восстановление', km: 0.4, pace: '6:00', type: 'easy' },
    { label: '1 км в темпе',   km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Восстановление', km: 0.4, pace: '6:00', type: 'easy' },
    { label: '1 км в темпе',   km: 1.0, pace: '4:30', type: 'tempo' },
    { label: 'Заминка',        km: 1.3, pace: '5:40', type: 'easy' },
  ],
  coachNote: 'Темповая — про контроль. Старт спокойно, держи 4:30 ровно. Восстановление — в медленном беге, не в шаге.',
};

V2.WEEK = [
  { day: 'ПН', date: 11, type: 'easy',     km: 8,  label: 'Лёгкий 8 км',     status: 'done' },
  { day: 'ВТ', date: 12, type: 'tempo',    km: 8,  label: '4×1 км в темпе',  status: 'today', key: true },
  { day: 'СР', date: 13, type: 'rest',     km: 0,  label: 'Отдых',           status: 'planned' },
  { day: 'ЧТ', date: 14, type: 'easy',     km: 10, label: 'Лёгкий 10 км',    status: 'planned' },
  { day: 'ПТ', date: 15, type: 'interval', km: 12, label: '6×800 м',         status: 'planned', key: true },
  { day: 'СБ', date: 16, type: 'rest',     km: 0,  label: 'Отдых',           status: 'planned' },
  { day: 'ВС', date: 17, type: 'long',     km: 22, label: 'Длительный 22 км',status: 'planned', key: true },
];

V2.GOAL = {
  title: 'Москва · полумарафон', date: '28 сентября', daysLeft: 28,
  target: '1:35:00', predicted: '1:36:42', trend: '−42 сек/нед',
  progress: 0.74, weeksTotal: 16, weeksDone: 12, phase: 'build',
};

V2.PHASES = {
  base: 'База', build: 'Развивающая', peak: 'Пиковая', taper: 'Подводка',
  recovery: 'Восстановление', race: 'Старт',
};

// ── Components ────────────────────────────────────────────────────────
V2.Sparkline = function Sparkline({ data, w = 80, h = 24, color = '#FC4C02', bg, thick = false, responsive = true }) {
  const max = Math.max(...data, 1);
  const step = w / (data.length - 1);
  const pts = data.map((v, i) => [i * step, h - (v / max) * (h - 2) - 1]);
  const d = 'M ' + pts.map(p => p.join(' ')).join(' L ');
  const area = d + ` L ${w} ${h} L 0 ${h} Z`;
  const svgProps = responsive
    ? { width: '100%', height: h, viewBox: `0 0 ${w} ${h}`, preserveAspectRatio: 'none' }
    : { width: w, height: h };
  return (
    <svg {...svgProps} style={{ display: 'block', overflow: 'visible', maxWidth: '100%' }}>
      {bg && <path d={area} fill={color} opacity="0.12" />}
      <path d={d} stroke={color} strokeWidth={thick ? 2 : 1.5} fill="none" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
      <circle cx={pts[pts.length-1][0]} cy={pts[pts.length-1][1]} r={thick ? 3 : 2} fill={color} />
    </svg>
  );
};

V2.Avatar = function Avatar({ a, size = 36, ring }) {
  if (!a) return null;
  return (
    <div style={{
      width: size, height: size, borderRadius: '50%', background: a.tone, color: '#0F172A',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      fontWeight: 700, fontSize: size * 0.36, fontFamily: '"Jost", sans-serif',
      flexShrink: 0, letterSpacing: '0.02em',
      boxShadow: ring ? `0 0 0 2px ${ring}, 0 0 0 4px white` : 'none',
    }}>{a.initials}</div>
  );
};

V2.Compliance = function Compliance({ done, total, w = 60 }) {
  const pct = total ? done / total : 0;
  const color = pct >= 0.8 ? V2.T.success : pct >= 0.5 ? V2.T.warning : V2.T.danger;
  return (
    <div style={{ width: w }}>
      <div style={{ height: 4, background: V2.T.line, borderRadius: 999, overflow: 'hidden' }}>
        <div style={{ height: '100%', width: `${pct * 100}%`, background: color, borderRadius: 999 }} />
      </div>
    </div>
  );
};

V2.GroupTag = function GroupTag({ id, size = 'sm' }) {
  const g = V2.GROUPS.find(x => x.id === id);
  if (!g) return null;
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5,
      padding: size === 'sm' ? '2px 7px' : '4px 10px',
      borderRadius: 999, fontSize: size === 'sm' ? 11 : 12, fontWeight: 600,
      background: g.color + '15', color: g.color, lineHeight: 1.4,
    }}>
      <span style={{ width: 5, height: 5, borderRadius: 999, background: g.color }} />
      {g.name}
    </span>
  );
};

V2.toneStyles = (tone) => {
  const map = {
    danger:  { bg: V2.T.dangerWash,  color: V2.T.danger,  solid: V2.T.danger },
    warn:    { bg: V2.T.warningWash, color: '#92400E',    solid: V2.T.warning },
    success: { bg: V2.T.successWash, color: '#166534',    solid: V2.T.success },
    info:    { bg: V2.T.infoWash,    color: '#1E40AF',    solid: V2.T.info },
    primary: { bg: V2.T.primaryWash, color: V2.T.primary, solid: V2.T.primary },
  };
  return map[tone] || { bg: V2.T.surf3, color: V2.T.ink, solid: V2.T.ink3 };
};

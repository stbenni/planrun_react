/* v3 Calendar — редизайн календаря бегуна.
   6 артбордов:
   1. Неделя мобайл
   2. Неделя десктоп
   3. Месяц мобайл
   4. Месяц десктоп
   5. DayModal (bottom-sheet на мобайле, side-drawer на десктопе)
   6. Empty-states (нет плана, генерируется)

   Решает 3 главные боли:
   - Фаза плана видна всегда (macro-cycle ribbon на десктопе, бейдж на мобайле)
   - Splaв объёма по неделям (sparkline + bars)
   - DayModal чище, меньше кликов
*/

const { useState: useStateCAL } = React;
const TC = V2.T;

// ── Macro-cycle data ────────────────────────────────────────────────
const PHASES = {
  base:     { name: 'База',         color: '#22C55E', desc: 'Адаптация и объём' },
  build:    { name: 'Развивающая',  color: '#FC4C02', desc: 'Скорость + сила' },
  peak:     { name: 'Пиковая',      color: '#8B5CF6', desc: 'Максимальная нагрузка' },
  taper:    { name: 'Подводка',     color: '#3B82F6', desc: 'Снижение объёма' },
  race:     { name: 'Старт',        color: '#EF4444', desc: 'Гонка' },
  recovery: { name: 'Восст.',       color: '#A3A3A3', desc: 'После гонки' },
};

const MACRO_WEEKS = [
  { n: 1,  phase: 'base',  km: 38, done: true,  current: false },
  { n: 2,  phase: 'base',  km: 42, done: true,  current: false },
  { n: 3,  phase: 'base',  km: 48, done: true,  current: false },
  { n: 4,  phase: 'base',  km: 36, done: true,  current: false },
  { n: 5,  phase: 'build', km: 52, done: true,  current: false },
  { n: 6,  phase: 'build', km: 58, done: true,  current: false },
  { n: 7,  phase: 'build', km: 62, done: true,  current: false },
  { n: 8,  phase: 'build', km: 48, done: true,  current: false },
  { n: 9,  phase: 'build', km: 66, done: true,  current: false },
  { n: 10, phase: 'build', km: 72, done: true,  current: false },
  { n: 11, phase: 'build', km: 78, done: true,  current: false },
  { n: 12, phase: 'build', km: 60, done: false, current: true },
  { n: 13, phase: 'peak',  km: 84, done: false, current: false },
  { n: 14, phase: 'peak',  km: 88, done: false, current: false },
  { n: 15, phase: 'taper', km: 60, done: false, current: false },
  { n: 16, phase: 'race',  km: 42, done: false, current: false },
];

// ── Mock week data ──────────────────────────────────────────────────
const CAL_WEEK = [
  { day: 'ПН', date: 11, dateStr: '2026-05-11', items: [{ type: 'easy', km: 8, label: 'Лёгкий 8 км' }], status: 'done' },
  { day: 'ВТ', date: 12, dateStr: '2026-05-12', items: [{ type: 'tempo', km: 8, label: '4×1 км в темпе', key: true }], status: 'today' },
  { day: 'СР', date: 13, dateStr: '2026-05-13', items: [{ type: 'rest', km: 0, label: 'Отдых' }], status: 'planned' },
  { day: 'ЧТ', date: 14, dateStr: '2026-05-14', items: [{ type: 'easy', km: 10, label: 'Лёгкий 10 км' }, { type: 'sbu', km: 0, label: 'СБУ ×5' }], status: 'planned' },
  { day: 'ПТ', date: 15, dateStr: '2026-05-15', items: [{ type: 'interval', km: 12, label: '6×800 м', key: true }], status: 'planned' },
  { day: 'СБ', date: 16, dateStr: '2026-05-16', items: [{ type: 'rest', km: 0, label: 'Отдых' }], status: 'planned' },
  { day: 'ВС', date: 17, dateStr: '2026-05-17', items: [{ type: 'long', km: 22, label: 'Длительный 22 км', key: true }], status: 'planned' },
];

// ─────────────────────────────────────────────────────────────────────
// MACRO RIBBON — фаза плана видна всегда
// ─────────────────────────────────────────────────────────────────────
function MacroRibbon({ compact }) {
  const current = MACRO_WEEKS.find(w => w.current);
  const phaseName = PHASES[current.phase].name;
  const phaseColor = PHASES[current.phase].color;
  const phaseDesc = PHASES[current.phase].desc;

  if (compact) {
    // Mobile: только бейдж + название фазы
    return (
      <div style={CL.macroCompact}>
        <span style={{ width: 8, height: 8, borderRadius: 999, background: phaseColor, boxShadow: `0 0 8px ${phaseColor}50` }} />
        <span style={{ fontSize: 11, color: TC.ink3, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>
          Фаза: <span style={{ color: phaseColor }}>{phaseName}</span>
        </span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 11, color: TC.ink3, fontWeight: 600 }}>Неделя {current.n}/16</span>
      </div>
    );
  }

  const maxKm = Math.max(...MACRO_WEEKS.map(w => w.km));

  return (
    <div style={CL.macroFull}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>МАКРОЦИКЛ · 16 НЕДЕЛЬ</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 11, color: phaseColor, fontWeight: 700 }}>{phaseDesc}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 56 }}>
        {MACRO_WEEKS.map(w => {
          const c = PHASES[w.phase].color;
          const h = (w.km / maxKm) * 100;
          return (
            <div key={w.n} title={`Неделя ${w.n} · ${PHASES[w.phase].name} · ${w.km} км`}
              style={{
                flex: 1, height: `${h}%`, position: 'relative',
                background: w.current ? c : w.done ? c : c + '40',
                borderRadius: '4px 4px 0 0',
                boxShadow: w.current ? `0 0 16px ${c}80` : 'none',
                border: w.current ? `2px solid ${c}` : 'none',
                minHeight: 6,
                transition: 'all 0.2s',
              }}>
              {w.current && (
                <span style={{
                  position: 'absolute', top: -16, left: '50%', transform: 'translateX(-50%)',
                  fontSize: 9, fontWeight: 800, color: c, letterSpacing: '0.05em',
                }}>СЕЙЧАС</span>
              )}
            </div>
          );
        })}
      </div>
      {/* Phase legend */}
      <div style={{ display: 'flex', gap: 2, marginTop: 6 }}>
        {MACRO_WEEKS.map(w => (
          <div key={w.n} style={{
            flex: 1, height: 4, background: PHASES[w.phase].color,
            opacity: w.current ? 1 : 0.5,
          }} />
        ))}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 10, color: TC.ink3, fontWeight: 600 }}>
        <span>Сен</span>
        <span style={{ color: phaseColor, fontWeight: 700 }}>Сейчас · {phaseName}</span>
        <span>Янв · 🏁 Москва</span>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// VOLUME CHART — splat объёма по неделям
// ─────────────────────────────────────────────────────────────────────
function VolumeChart({ compact }) {
  const weeks = MACRO_WEEKS;
  const max = Math.max(...weeks.map(w => w.km));
  const cur = weeks.find(w => w.current);

  return (
    <div style={CL.card}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ОБЪЁМ ПО НЕДЕЛЯМ</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: 700, color: TC.ink }}>
          {cur.km}<span style={{ color: TC.ink3, fontWeight: 500 }}> км · эта неделя</span>
        </span>
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: compact ? 50 : 80, marginTop: 14 }}>
        {weeks.map(w => {
          const c = PHASES[w.phase].color;
          const h = (w.km / max) * 100;
          return (
            <div key={w.n} style={{ flex: 1, position: 'relative', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', height: '100%' }}>
              <div style={{
                width: '100%', height: `${h}%`,
                background: w.current ? c : w.done ? `linear-gradient(180deg, ${c}, ${c}80)` : c + '30',
                borderRadius: '4px 4px 0 0',
                border: w.current ? `1.5px solid ${c}` : 'none',
                boxShadow: w.current ? `0 4px 12px ${c}60` : 'none',
                minHeight: 4,
              }} />
            </div>
          );
        })}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 9, color: TC.ink3, fontFamily: '"Jost", sans-serif', fontWeight: 600 }}>
        {weeks.filter((_, i) => i % 3 === 0 || i === weeks.length - 1).map(w => <span key={w.n}>{w.n}</span>)}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// 1. WEEK MOBILE
// ─────────────────────────────────────────────────────────────────────
function CalWeekMobile() {
  const [selected, setSelected] = useStateCAL(1); // index in CAL_WEEK

  const day = CAL_WEEK[selected];
  const totalKm = CAL_WEEK.reduce((s, d) => s + d.items.reduce((a, b) => a + b.km, 0), 0);
  const doneKm = CAL_WEEK.filter(d => d.status === 'done').reduce((s, d) => s + d.items.reduce((a, b) => a + b.km, 0), 0);

  return (
    <div style={CL.mobShell}>
      <div style={CL.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      {/* Header */}
      <div style={CL.mobHeader}>
        <div>
          <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>МАЙ 2026 · НЕДЕЛЯ 12</div>
          <div style={{ fontSize: 22, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', marginTop: 2 }}>11 — 17 мая</div>
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button style={CL.viewToggleBtn}>📅</button>
          <button style={CL.viewToggleBtn}>⋯</button>
        </div>
      </div>

      {/* View tabs (week / month) */}
      <div style={CL.viewTabs}>
        <button style={{ ...CL.viewTab, ...CL.viewTabActive }}>Неделя</button>
        <button style={CL.viewTab}>Месяц</button>
      </div>

      <div style={CL.mobScroll}>
        {/* Macro phase ribbon */}
        <div style={CL.section}>
          <MacroRibbon compact />
        </div>

        {/* Week summary */}
        <div style={CL.section}>
          <div style={CL.card}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: TC.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{totalKm}</span>
              <span style={{ fontSize: 13, color: TC.ink3 }}>км запланировано</span>
              <span style={{ flex: 1 }} />
              <span style={{ fontSize: 13, color: TC.success, fontWeight: 700 }}>✓ {doneKm}/{totalKm} км</span>
            </div>
            {/* Mini progress */}
            <div style={{ height: 4, background: TC.surf3, borderRadius: 999, overflow: 'hidden', marginTop: 8 }}>
              <div style={{ width: `${doneKm / totalKm * 100}%`, height: '100%', background: TC.success, boxShadow: `0 0 8px ${TC.success}80` }} />
            </div>
            <div style={{ display: 'flex', gap: 16, marginTop: 12, paddingTop: 12, borderTop: `1px solid ${TC.line}` }}>
              <MiniCALStat label="КЛЮЧЕВЫЕ" val={`${CAL_WEEK.filter(d => d.items.some(i => i.key)).length}/3`} />
              <MiniCALStat label="ТРЕНИРОВОК" val={`${CAL_WEEK.filter(d => d.items.some(i => i.type !== 'rest')).length}`} />
              <MiniCALStat label="ОТДЫХ" val={`${CAL_WEEK.filter(d => d.items.every(i => i.type === 'rest')).length} дн`} />
            </div>
          </div>
        </div>

        {/* Week strip — horizontal pills */}
        <div style={CL.section}>
          <div style={CL.weekStripWrap}>
            <button style={CL.weekNavBtn}>‹</button>
            <div style={CL.weekStrip}>
              {CAL_WEEK.map((d, i) => {
                const primary = d.items.find(it => it.type !== 'rest') || d.items[0];
                const c = V2.typeColor(primary.type);
                const isSel = selected === i;
                const isToday = d.status === 'today';
                const isDone = d.status === 'done';
                return (
                  <button key={i} onClick={() => setSelected(i)} style={{
                    ...CL.dayPill,
                    background: isSel ? c : isToday ? TC.primaryWash : isDone ? TC.surf3 : 'transparent',
                    borderColor: isSel ? c : isToday ? TC.primary : 'transparent',
                    color: isSel ? 'white' : TC.ink,
                    boxShadow: isSel ? `0 6px 16px ${c}50` : 'none',
                  }}>
                    <span style={{ fontSize: 9, fontWeight: 700, letterSpacing: '0.08em', opacity: isSel ? 0.85 : 0.6 }}>{d.day}</span>
                    <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 17, fontWeight: 800, letterSpacing: '-0.02em', lineHeight: 1, marginTop: 1 }}>{d.date}</span>
                    {primary.type !== 'rest' && (
                      <span style={{ marginTop: 4, width: 6, height: 6, borderRadius: 999, background: isSel ? 'white' : c }} />
                    )}
                    {isDone && <span style={{ position: 'absolute', top: 4, right: 4, fontSize: 9 }}>✓</span>}
                    {d.items.some(i => i.key) && !isSel && (
                      <span style={{ position: 'absolute', top: 4, left: 4, width: 5, height: 5, borderRadius: 999, background: TC.primary }} />
                    )}
                  </button>
                );
              })}
            </div>
            <button style={CL.weekNavBtn}>›</button>
          </div>
        </div>

        {/* Selected day workout */}
        <div style={CL.section}>
          <DayWorkoutCard day={day} />
        </div>

        {/* Volume chart */}
        <div style={CL.section}>
          <VolumeChart compact />
        </div>

        <div style={{ height: 110 }} />
      </div>

      <MobileNav activeIndex={1} />
    </div>
  );
}

function MiniCALStat({ label, val }) {
  return (
    <div style={{ flex: 1 }}>
      <div style={{ fontSize: 9, color: TC.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{label}</div>
      <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 17, fontWeight: 700, color: TC.ink, lineHeight: 1, marginTop: 2 }}>{val}</div>
    </div>
  );
}

function DayWorkoutCard({ day, large }) {
  const primary = day.items.find(it => it.type !== 'rest') || day.items[0];
  const c = V2.typeColor(primary.type);
  const isRest = primary.type === 'rest';

  return (
    <div style={CL.dayCard}>
      {!isRest && (
        <div style={{
          position: 'absolute', top: 0, right: 0, width: 180, height: 180,
          background: `radial-gradient(circle at top right, ${c}25 0%, transparent 65%)`,
          pointerEvents: 'none',
        }} />
      )}

      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ width: 8, height: 8, borderRadius: 999, background: c, boxShadow: !isRest ? `0 0 8px ${c}80` : 'none' }} />
        <span style={{ fontSize: 11, color: TC.ink3, fontWeight: 700, letterSpacing: '0.12em' }}>
          {day.day} · {day.date} мая · {V2.TYPE_LABEL[primary.type]?.toUpperCase() || primary.type.toUpperCase()}
        </span>
        {primary.key && <span style={CL.keyPill}>КЛЮЧ</span>}
      </div>

      <h2 style={{ fontSize: large ? 36 : 26, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', lineHeight: 1.1, marginTop: 8 }}>
        {primary.label}
      </h2>

      {primary.km > 0 && (
        <div style={{ display: 'flex', gap: 16, marginTop: 14 }}>
          <DayStatInline n={String(primary.km).replace('.', ',')} l="км" />
          <DayStatInline n="4:30" l="темп" accent />
          <DayStatInline n="42′" l="время" />
        </div>
      )}

      {/* Multiple items in one day (e.g. easy + sbu) */}
      {day.items.length > 1 && (
        <div style={{ marginTop: 14, padding: 12, background: TC.surf3, borderRadius: 10 }}>
          <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>ТАКЖЕ В ЭТОТ ДЕНЬ</div>
          {day.items.slice(1).map((it, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 8 }}>
              <span style={{ width: 3, height: 18, background: V2.typeColor(it.type), borderRadius: 4 }} />
              <span style={{ fontSize: 13, fontWeight: 600 }}>{it.label}</span>
            </div>
          ))}
        </div>
      )}

      {/* AI tip inline */}
      {!isRest && (
        <div style={CL.aiInline}>
          <div style={CL.aiAvatar}>AI</div>
          <div style={{ flex: 1, fontSize: 12.5, color: TC.ink, lineHeight: 1.45 }}>
            <b>AI · 7:42:</b> темповая — про контроль. Старт спокойно, держи ровный темп.
          </div>
        </div>
      )}

      <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
        <button style={CL.cta}>Открыть детали →</button>
        {!isRest && <button style={CL.ctaIcon}>✓</button>}
      </div>
    </div>
  );
}

function DayStatInline({ n, l, accent }) {
  return (
    <div>
      <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: accent ? TC.primary : TC.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{n}</div>
      <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 600, letterSpacing: '0.04em', marginTop: 3 }}>{l}</div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// 2. WEEK DESKTOP
// ─────────────────────────────────────────────────────────────────────
function CalWeekDesktop() {
  const [selected, setSelected] = useStateCAL(1);
  const day = CAL_WEEK[selected];
  const totalKm = CAL_WEEK.reduce((s, d) => s + d.items.reduce((a, b) => a + b.km, 0), 0);
  const doneKm = CAL_WEEK.filter(d => d.status === 'done').reduce((s, d) => s + d.items.reduce((a, b) => a + b.km, 0), 0);

  return (
    <div style={CL.deskShell}>
      {/* Top bar */}
      <div style={CL.deskTop}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 30, height: 30, borderRadius: 9, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15 }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em', color: TC.ink }}>planrun</span>
        </div>
        <nav style={{ display: 'flex', gap: 2, marginLeft: 24 }}>
          {[['Дэшборд', false], ['Календарь', true], ['Чат', false], ['Прогресс', false], ['Настройки', false]].map(([l, on]) => (
            <a key={l} style={{ padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: on ? 700 : 500, color: on ? TC.ink : TC.ink2, background: on ? TC.surf3 : 'transparent', cursor: 'pointer' }}>{l}</a>
          ))}
        </nav>
        <div style={{ flex: 1 }} />
        <button style={CL.deskGhost}>Скопировать неделю</button>
        <button style={CL.deskGhost}>↻ Пересчитать</button>
        <button style={CL.deskPrimary}>+ Тренировка</button>
      </div>

      {/* Header with date + view toggle */}
      <div style={CL.deskHeader}>
        <div>
          <div style={{ fontSize: 11, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>МАЙ 2026 · НЕДЕЛЯ 12 ИЗ 16</div>
          <div style={{ fontSize: 28, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', marginTop: 2 }}>11 — 17 мая</div>
        </div>
        <div style={{ flex: 1 }} />
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button style={CL.weekNavBtnLg}>‹</button>
          <button style={CL.weekTodayBtn}>Сегодня</button>
          <button style={CL.weekNavBtnLg}>›</button>
        </div>
        <div style={{ display: 'flex', background: TC.surf3, borderRadius: 10, padding: 3, marginLeft: 12 }}>
          <button style={{ ...CL.viewToggleSeg, ...CL.viewToggleSegActive }}>Неделя</button>
          <button style={CL.viewToggleSeg}>Месяц</button>
        </div>
      </div>

      {/* Main grid: macro ribbon up top + week grid + day detail */}
      <div style={CL.deskBody}>
        {/* Macro ribbon spans full width */}
        <div style={{ gridColumn: '1 / -1' }}>
          <div style={CL.card}>
            <MacroRibbon />
          </div>
        </div>

        {/* Left: week grid */}
        <div>
          <div style={CL.card}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <div>
                <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ЭТА НЕДЕЛЯ</div>
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 4 }}>
                  <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 36, fontWeight: 800, color: TC.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{totalKm}</span>
                  <span style={{ fontSize: 13, color: TC.ink3 }}>км · {CAL_WEEK.filter(d => d.items.some(i => i.key)).length} ключевых</span>
                </div>
              </div>
              <div style={{ flex: 1 }} />
              <span style={{ fontSize: 14, color: TC.success, fontWeight: 700 }}>✓ {doneKm}/{totalKm}</span>
            </div>

            {/* Week days as columns */}
            <div style={CL.deskWeekGrid}>
              {CAL_WEEK.map((d, i) => {
                const primary = d.items.find(it => it.type !== 'rest') || d.items[0];
                const c = V2.typeColor(primary.type);
                const isSel = selected === i;
                const isToday = d.status === 'today';
                const isDone = d.status === 'done';
                const isRest = primary.type === 'rest';

                return (
                  <button key={i} onClick={() => setSelected(i)} style={{
                    ...CL.deskDayCol,
                    background: isSel ? TC.primaryWash : isToday ? 'rgba(252,76,2,0.04)' : 'transparent',
                    borderColor: isSel ? TC.primary : isToday ? TC.primary + '50' : TC.line,
                    borderWidth: isSel ? 1.5 : 1,
                  }}>
                    {/* Date header */}
                    <div style={{ textAlign: 'center', paddingBottom: 10, borderBottom: `1px solid ${TC.line}` }}>
                      <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{d.day}</div>
                      <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 800, color: isToday ? TC.primary : TC.ink, letterSpacing: '-0.02em', lineHeight: 1, marginTop: 2 }}>{d.date}</div>
                      {isDone && <span style={{ display: 'inline-block', marginTop: 4, width: 16, height: 16, borderRadius: '50%', background: TC.success, color: 'white', fontSize: 10, fontWeight: 800, lineHeight: '16px' }}>✓</span>}
                      {isToday && !isDone && <span style={{ display: 'inline-block', marginTop: 4, padding: '2px 6px', background: TC.primary, color: 'white', fontSize: 9, fontWeight: 800, borderRadius: 4, letterSpacing: '0.06em' }}>СЕЙЧАС</span>}
                    </div>

                    {/* Workouts */}
                    <div style={{ flex: 1, padding: '10px 0', display: 'flex', flexDirection: 'column', gap: 6 }}>
                      {d.items.map((it, j) => {
                        if (it.type === 'rest') {
                          return <div key={j} style={{ fontSize: 11, color: TC.ink4, textAlign: 'center', padding: '8px 0' }}>— отдых —</div>;
                        }
                        const ic = V2.typeColor(it.type);
                        return (
                          <div key={j} style={{
                            padding: '8px 10px', background: ic + '10', border: `1px solid ${ic}30`,
                            borderRadius: 8, borderLeft: `3px solid ${ic}`,
                          }}>
                            <div style={{ fontSize: 11, color: TC.ink2, fontWeight: 700, letterSpacing: '0.04em' }}>
                              {V2.TYPE_LABEL[it.type] || it.type}
                              {it.key && <span style={{ marginLeft: 4, fontSize: 8, padding: '1px 4px', background: TC.primary, color: 'white', borderRadius: 3, fontWeight: 800 }}>★</span>}
                            </div>
                            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: TC.ink, fontWeight: 700, marginTop: 2 }}>
                              {it.label.length > 18 ? it.label.slice(0, 16) + '…' : it.label}
                            </div>
                            {it.km > 0 && (
                              <div style={{ fontSize: 11, color: TC.ink3, marginTop: 2, fontFamily: '"Jost", sans-serif' }}>{it.km} км</div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Volume chart */}
          <div style={{ marginTop: 14 }}>
            <VolumeChart />
          </div>
        </div>

        {/* Right: day detail */}
        <div>
          <DayWorkoutCard day={day} large />
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// 3. MONTH MOBILE
// ─────────────────────────────────────────────────────────────────────
function genMonth() {
  // May 2026 starts Friday
  const startsAt = 4; // 0=Mon
  const totalDays = 31;
  const today = 12;
  const cells = [];
  for (let i = 0; i < startsAt; i++) cells.push(null);
  for (let d = 1; d <= totalDays; d++) {
    const type = d === today ? 'tempo' :
                 d % 7 === 0 ? 'long' :
                 d % 5 === 0 ? 'interval' :
                 d % 3 === 0 ? 'rest' :
                 'easy';
    const km = type === 'rest' ? 0 : type === 'long' ? 22 : type === 'interval' ? 12 : type === 'tempo' ? 8 : 7;
    const done = d < today;
    cells.push({ day: d, type, km, done, isToday: d === today, key: type === 'tempo' || type === 'interval' || type === 'long' });
  }
  return cells;
}

function CalMonthMobile() {
  const [selected, setSelected] = useStateCAL(12);
  const cells = genMonth();
  const totalKm = cells.filter(c => c && c.km > 0).reduce((s, c) => s + c.km, 0);
  const doneKm = cells.filter(c => c && c.done && c.km > 0).reduce((s, c) => s + c.km, 0);

  return (
    <div style={CL.mobShell}>
      <div style={CL.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={CL.mobHeader}>
        <div>
          <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>МАЙ 2026</div>
          <div style={{ fontSize: 22, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', marginTop: 2 }}>Календарь</div>
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button style={CL.viewToggleBtn}>‹</button>
          <button style={CL.viewToggleBtn}>›</button>
        </div>
      </div>

      <div style={CL.viewTabs}>
        <button style={CL.viewTab}>Неделя</button>
        <button style={{ ...CL.viewTab, ...CL.viewTabActive }}>Месяц</button>
      </div>

      <div style={CL.mobScroll}>
        {/* Phase */}
        <div style={CL.section}>
          <MacroRibbon compact />
        </div>

        {/* Stats */}
        <div style={CL.section}>
          <div style={CL.card}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: TC.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{totalKm}</span>
              <span style={{ fontSize: 13, color: TC.ink3 }}>км в мае</span>
              <span style={{ flex: 1 }} />
              <span style={{ fontSize: 13, color: TC.success, fontWeight: 700 }}>✓ {doneKm}</span>
            </div>
            <div style={{ height: 4, background: TC.surf3, borderRadius: 999, overflow: 'hidden', marginTop: 8 }}>
              <div style={{ width: `${doneKm / totalKm * 100}%`, height: '100%', background: TC.success }} />
            </div>
          </div>
        </div>

        {/* Month grid */}
        <div style={{ ...CL.section, padding: '0 12px' }}>
          <div style={CL.monthCard}>
            <div style={CL.monthWeekHeader}>
              {['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map(d => (
                <div key={d} style={{ textAlign: 'center', fontSize: 10, color: TC.ink3, fontWeight: 700, padding: '8px 0' }}>{d}</div>
              ))}
            </div>
            <div style={CL.monthGrid}>
              {cells.map((c, i) => {
                if (!c) return <div key={i} />;
                const col = V2.typeColor(c.type);
                const isSel = selected === c.day;
                const isRest = c.type === 'rest';

                return (
                  <button key={i} onClick={() => setSelected(c.day)} style={{
                    ...CL.monthCellMob,
                    background: isSel ? col : c.isToday ? TC.primaryWash : c.done ? TC.surf3 : 'transparent',
                    borderColor: isSel ? col : c.isToday ? TC.primary : 'transparent',
                    color: isSel ? 'white' : TC.ink,
                  }}>
                    <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: c.isToday || isSel ? 800 : 600, letterSpacing: '-0.01em' }}>
                      {c.day}
                    </span>
                    {!isRest && (
                      <span style={{ position: 'absolute', bottom: 3, width: 4, height: 4, borderRadius: 999, background: isSel ? 'white' : col }} />
                    )}
                    {c.done && !isSel && (
                      <span style={{ position: 'absolute', top: 2, right: 2, fontSize: 7, color: TC.success }}>✓</span>
                    )}
                    {c.key && !isSel && (
                      <span style={{ position: 'absolute', top: 2, left: 2, width: 4, height: 4, borderRadius: 999, background: TC.primary }} />
                    )}
                  </button>
                );
              })}
            </div>
          </div>
        </div>

        {/* Selected day preview */}
        <div style={CL.section}>
          {(() => {
            const c = cells.find(x => x && x.day === selected);
            if (!c) return null;
            const day = { day: 'ВТ', date: c.day, items: [{ type: c.type, km: c.km, label: c.type === 'tempo' ? '4×1 км в темпе' : V2.TYPE_LABEL[c.type] + ' ' + c.km + ' км', key: c.key }], status: c.done ? 'done' : c.isToday ? 'today' : 'planned' };
            return <DayWorkoutCard day={day} />;
          })()}
        </div>

        {/* Legend */}
        <div style={CL.section}>
          <div style={CL.card}>
            <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 10 }}>ЛЕГЕНДА</div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {[['easy','Лёгкий'],['tempo','Темп'],['interval','Интервалы'],['long','Длительный'],['rest','Отдых']].map(([t,l]) => (
                <span key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 11, color: TC.ink2, fontWeight: 600 }}>
                  <span style={{ width: 8, height: 8, borderRadius: 999, background: V2.typeColor(t) }} />
                  {l}
                </span>
              ))}
            </div>
          </div>
        </div>

        <div style={{ height: 110 }} />
      </div>

      <MobileNav activeIndex={1} />
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// 4. MONTH DESKTOP
// ─────────────────────────────────────────────────────────────────────
function CalMonthDesktop() {
  const cells = genMonth();
  const totalKm = cells.filter(c => c && c.km > 0).reduce((s, c) => s + c.km, 0);
  const doneKm = cells.filter(c => c && c.done && c.km > 0).reduce((s, c) => s + c.km, 0);

  return (
    <div style={CL.deskShell}>
      <div style={CL.deskTop}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 30, height: 30, borderRadius: 9, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15 }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em', color: TC.ink }}>planrun</span>
        </div>
        <nav style={{ display: 'flex', gap: 2, marginLeft: 24 }}>
          {[['Дэшборд', false], ['Календарь', true], ['Чат', false], ['Прогресс', false], ['Настройки', false]].map(([l, on]) => (
            <a key={l} style={{ padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: on ? 700 : 500, color: on ? TC.ink : TC.ink2, background: on ? TC.surf3 : 'transparent', cursor: 'pointer' }}>{l}</a>
          ))}
        </nav>
        <div style={{ flex: 1 }} />
        <button style={CL.deskPrimary}>+ Тренировка</button>
      </div>

      <div style={CL.deskHeader}>
        <div>
          <div style={{ fontSize: 11, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>2026</div>
          <div style={{ fontSize: 28, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', marginTop: 2 }}>Май</div>
        </div>
        <div style={{ flex: 1 }} />
        <button style={CL.weekNavBtnLg}>‹</button>
        <button style={CL.weekTodayBtn}>Сегодня</button>
        <button style={CL.weekNavBtnLg}>›</button>
        <div style={{ display: 'flex', background: TC.surf3, borderRadius: 10, padding: 3, marginLeft: 12 }}>
          <button style={CL.viewToggleSeg}>Неделя</button>
          <button style={{ ...CL.viewToggleSeg, ...CL.viewToggleSegActive }}>Месяц</button>
        </div>
      </div>

      {/* Body: macro ribbon at top, then month grid + sidebar */}
      <div style={CL.deskBodyMonth}>
        <div style={{ gridColumn: '1 / -1' }}>
          <div style={CL.card}>
            <MacroRibbon />
          </div>
        </div>

        {/* Month grid */}
        <div style={CL.card}>
          <div style={CL.monthWeekHeaderDesk}>
            {['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map(d => (
              <div key={d} style={{ textAlign: 'left', fontSize: 11, color: TC.ink3, fontWeight: 700, padding: '8px 12px', letterSpacing: '0.08em' }}>{d}</div>
            ))}
          </div>
          <div style={CL.monthGridDesk}>
            {cells.map((c, i) => {
              if (!c) return <div key={i} style={{ background: 'transparent' }} />;
              const col = V2.typeColor(c.type);
              const isRest = c.type === 'rest';

              return (
                <div key={i} style={{
                  ...CL.monthCellDesk,
                  background: c.isToday ? TC.primaryWash : c.done ? 'rgba(248,250,252,0.6)' : 'rgba(255,255,255,0.4)',
                  border: c.isToday ? `1.5px solid ${TC.primary}` : `1px solid ${TC.line}`,
                }}>
                  <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
                    <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 800, color: c.isToday ? TC.primary : TC.ink, letterSpacing: '-0.02em' }}>
                      {c.day}
                    </span>
                    {c.done && <span style={{ fontSize: 12, color: TC.success }}>✓</span>}
                    {c.isToday && !c.done && <span style={{ fontSize: 8, fontWeight: 800, padding: '1px 5px', background: TC.primary, color: 'white', borderRadius: 3, letterSpacing: '0.05em' }}>СЕЙЧАС</span>}
                  </div>

                  {!isRest && (
                    <div style={{ marginTop: 8, padding: '4px 8px', background: col + '15', borderLeft: `3px solid ${col}`, borderRadius: '4px', fontSize: 11 }}>
                      <div style={{ fontWeight: 700, color: TC.ink, lineHeight: 1.25 }}>
                        {V2.TYPE_LABEL[c.type]}
                        {c.key && <span style={{ marginLeft: 4, fontSize: 8, fontWeight: 800, color: TC.primary }}>★</span>}
                      </div>
                      <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 11, color: TC.ink3, fontWeight: 600, marginTop: 1 }}>{c.km} км</div>
                    </div>
                  )}
                  {isRest && (
                    <div style={{ marginTop: 8, fontSize: 11, color: TC.ink4, fontStyle: 'italic' }}>отдых</div>
                  )}
                </div>
              );
            })}
          </div>
        </div>

        {/* Sidebar: month stats */}
        <aside style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div style={CL.card}>
            <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ОБЪЁМ В МАЕ</div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 6 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 40, fontWeight: 800, color: TC.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{totalKm}</span>
              <span style={{ fontSize: 13, color: TC.ink3 }}>км</span>
              <span style={{ flex: 1 }} />
              <span style={{ fontSize: 14, color: TC.success, fontWeight: 700 }}>+18% к апрелю</span>
            </div>
            <div style={{ marginTop: 14 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, marginBottom: 4 }}>
                <span style={{ color: TC.ink3 }}>Выполнено</span>
                <span style={{ color: TC.success, fontWeight: 700, fontFamily: '"Jost", sans-serif' }}>{doneKm} / {totalKm} км</span>
              </div>
              <div style={{ height: 6, background: TC.surf3, borderRadius: 999, overflow: 'hidden' }}>
                <div style={{ width: `${doneKm / totalKm * 100}%`, height: '100%', background: `linear-gradient(90deg, ${TC.success}, ${TC.success}cc)`, boxShadow: `0 0 8px ${TC.success}60` }} />
              </div>
            </div>
          </div>

          <VolumeChart />

          <div style={CL.card}>
            <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ТИПЫ ТРЕНИРОВОК</div>
            <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 8 }}>
              {[
                { t: 'easy', l: 'Лёгкие', n: 12, km: 84 },
                { t: 'tempo', l: 'Темповые', n: 4, km: 32 },
                { t: 'interval', l: 'Интервалы', n: 3, km: 36 },
                { t: 'long', l: 'Длительные', n: 4, km: 88 },
              ].map(x => (
                <div key={x.t} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <span style={{ width: 8, height: 8, borderRadius: 999, background: V2.typeColor(x.t) }} />
                  <span style={{ fontSize: 13, color: TC.ink, fontWeight: 600, flex: 1 }}>{x.l}</span>
                  <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 12, color: TC.ink3, fontWeight: 600 }}>{x.n} × {x.km} км</span>
                </div>
              ))}
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// 5. DAY DETAILS — bottom-sheet (mobile) + side-drawer (desktop)
// ─────────────────────────────────────────────────────────────────────
function CalDayDetailsMobile() {
  const day = CAL_WEEK[1]; // tempo Tuesday
  const primary = day.items[0];
  const c = V2.typeColor(primary.type);
  const [tab, setTab] = useStateCAL('plan');

  return (
    <div style={CL.mobShell}>
      <div style={CL.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      {/* Dimmed background showing calendar */}
      <div style={{ flex: 1, opacity: 0.4, padding: '0 16px', pointerEvents: 'none', overflow: 'hidden' }}>
        <div style={CL.mobHeader}>
          <div style={{ fontSize: 22, fontWeight: 800, color: TC.ink }}>11 — 17 мая</div>
        </div>
        <div style={{ padding: 12, background: 'white', borderRadius: 16, marginTop: 12 }}>
          <div style={{ display: 'flex', gap: 4 }}>
            {CAL_WEEK.map((d, i) => (
              <div key={i} style={{ flex: 1, height: 48, borderRadius: 8, background: TC.surf3 }} />
            ))}
          </div>
        </div>
      </div>

      {/* Scrim */}
      <div style={{ position: 'absolute', inset: 0, background: 'rgba(15,23,42,0.5)', zIndex: 5 }} />

      {/* Bottom sheet */}
      <div style={CL.bottomSheet}>
        <div style={CL.sheetGrip} />

        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12 }}>
          <span style={{ width: 8, height: 8, borderRadius: 999, background: c, boxShadow: `0 0 8px ${c}80` }} />
          <span style={{ fontSize: 11, color: TC.ink3, fontWeight: 700, letterSpacing: '0.12em' }}>ВТ · 12 МАЯ · ТЕМПОВАЯ</span>
          {primary.key && <span style={CL.keyPill}>КЛЮЧ</span>}
          <span style={{ flex: 1 }} />
          <button style={CL.sheetCloseBtn}>✕</button>
        </div>

        <h2 style={{ fontSize: 26, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', lineHeight: 1.1, marginTop: 8 }}>
          {primary.label}
        </h2>

        <div style={{ display: 'flex', gap: 16, marginTop: 14, paddingBottom: 14, borderBottom: `1px solid ${TC.line}` }}>
          <DayStatInline n="8,0" l="км" />
          <DayStatInline n="4:30" l="темп /км" accent />
          <DayStatInline n="42′" l="время ~" />
        </div>

        {/* Tabs */}
        <div style={CL.detailTabs}>
          {[['plan','План'],['done','Выполнено'],['notes','Заметки · 2']].map(([k,l]) => (
            <button key={k} onClick={() => setTab(k)}
              style={{ ...CL.detailTab, ...(tab === k ? CL.detailTabActive : {}) }}>
              {l}
            </button>
          ))}
        </div>

        {tab === 'plan' && (
          <div style={{ marginTop: 14 }}>
            {/* Interval bar */}
            <div style={CL.intervalBar}>
              {V2.TODAY.segments.map((s, i) => (
                <div key={i} style={{ flex: s.km, background: V2.typeColor(s.type) }} />
              ))}
            </div>
            <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column', gap: 6 }}>
              {V2.TODAY.segments.slice(0, 5).map((s, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0' }}>
                  <span style={{ width: 6, height: 6, borderRadius: 999, background: V2.typeColor(s.type), flexShrink: 0 }} />
                  <span style={{ flex: 1, fontSize: 13, color: TC.ink }}>{s.label}</span>
                  <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: TC.ink2, fontWeight: 600, width: 54, textAlign: 'right' }}>{s.km} км</span>
                  <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: TC.ink3, width: 50, textAlign: 'right' }}>{s.pace}</span>
                </div>
              ))}
              <div style={{ fontSize: 11, color: TC.ink3, textAlign: 'center', marginTop: 4 }}>+ ещё 4 отрезка</div>
            </div>

            {/* AI tip */}
            <div style={CL.aiInline}>
              <div style={CL.aiAvatar}>AI</div>
              <div style={{ flex: 1, fontSize: 12.5, color: TC.ink, lineHeight: 1.45 }}>
                <b>AI · 7:42:</b> Старт спокойно, держи 4:30 ровно. Восстановление — в медленном беге.
              </div>
            </div>
          </div>
        )}

        {tab === 'done' && (
          <div style={{ marginTop: 14, textAlign: 'center', padding: '24px 0' }}>
            <div style={{ fontSize: 32 }}>📱</div>
            <div style={{ fontSize: 13, color: TC.ink3, marginTop: 8 }}>Ещё не выполнено</div>
            <div style={{ fontSize: 12, color: TC.ink3, marginTop: 4 }}>Загрузка из Strava / Polar — автоматически</div>
          </div>
        )}

        {tab === 'notes' && (
          <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
            <div style={{ padding: 12, background: TC.surf3, borderRadius: 10 }}>
              <div style={{ fontSize: 11, color: TC.ink3, fontWeight: 600 }}>Михаил · вчера 19:01</div>
              <div style={{ fontSize: 13, color: TC.ink, marginTop: 4 }}>Не гоняй на первом повторе — холодные ноги.</div>
            </div>
            <div style={{ padding: 12, background: TC.surf3, borderRadius: 10 }}>
              <div style={{ fontSize: 11, color: TC.ink3, fontWeight: 600 }}>Я · 7:30</div>
              <div style={{ fontSize: 13, color: TC.ink, marginTop: 4 }}>Сплю отлично, готов!</div>
            </div>
          </div>
        )}

        {/* CTA */}
        <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
          <button style={CL.cta}>Начать тренировку →</button>
          <button style={CL.ctaIcon}>↔</button>
          <button style={CL.ctaIcon}>✎</button>
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// 6. EMPTY STATES
// ─────────────────────────────────────────────────────────────────────
function CalEmpty({ state = 'no-plan' }) {
  return (
    <div style={CL.mobShell}>
      <div style={CL.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={CL.mobHeader}>
        <div>
          <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>КАЛЕНДАРЬ</div>
          <div style={{ fontSize: 22, fontWeight: 800, color: TC.ink, letterSpacing: '-0.02em', marginTop: 2 }}>
            {state === 'no-plan' ? 'Нет плана' : 'Генерируется'}
          </div>
        </div>
      </div>

      <div style={{ flex: 1, overflow: 'auto', padding: 16 }}>
        {state === 'no-plan' && (
          <div>
            {/* Big icon */}
            <div style={{ textAlign: 'center', padding: '40px 0 20px' }}>
              <div style={{ fontSize: 72 }}>📅</div>
            </div>

            <h1 style={{ fontSize: 28, fontWeight: 800, letterSpacing: '-0.03em', textAlign: 'center', color: TC.ink, lineHeight: 1.1 }}>
              Создадим твой<br/>план тренировок
            </h1>
            <p style={{ fontSize: 14, color: TC.ink2, textAlign: 'center', marginTop: 10, lineHeight: 1.5 }}>
              AI-тренер соберёт 16-недельный план под твою цель. Календарь заполнится автоматически.
            </p>

            {/* Steps preview */}
            <div style={{ marginTop: 24, display: 'flex', flexDirection: 'column', gap: 8 }}>
              {[
                ['🎯', 'Указать цель и дату гонки'],
                ['💪', 'Описать текущий уровень'],
                ['✨', 'AI собирает план за 3-5 минут'],
                ['📅', 'Календарь заполняется по неделям'],
              ].map(([ic, l], i) => (
                <div key={i} style={CL.emptyStep}>
                  <span style={{ fontSize: 22 }}>{ic}</span>
                  <span style={{ fontSize: 13, color: TC.ink, fontWeight: 600 }}>{l}</span>
                </div>
              ))}
            </div>

            <button style={{ ...CL.cta, marginTop: 24, width: '100%' }}>Создать план →</button>
            <button style={CL.ctaGhost}>У меня уже есть план</button>
          </div>
        )}

        {state === 'generating' && (
          <div>
            <div style={{ textAlign: 'center', padding: '24px 0 16px' }}>
              <div style={{ position: 'relative', display: 'inline-block' }}>
                <div style={{
                  width: 88, height: 88, borderRadius: 28,
                  background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)',
                  display: 'grid', placeItems: 'center',
                  color: 'white', fontWeight: 800, fontSize: 26,
                  boxShadow: '0 16px 36px rgba(252,76,2,0.35)',
                }}>AI</div>
                <div style={{ position: 'absolute', inset: -6, border: `3px dashed ${TC.primary}50`, borderRadius: 32 }} />
              </div>
            </div>

            <h1 style={{ fontSize: 24, fontWeight: 800, letterSpacing: '-0.02em', textAlign: 'center', color: TC.ink }}>
              Составляю план…
            </h1>
            <p style={{ fontSize: 13, color: TC.ink3, textAlign: 'center', marginTop: 6 }}>3-5 минут. Уведомлю когда готово.</p>

            <div style={{ marginTop: 20, height: 6, background: TC.surf3, borderRadius: 999, overflow: 'hidden' }}>
              <div style={{ width: '52%', height: '100%', background: 'linear-gradient(90deg, #FC4C02, #FF6B3D)', boxShadow: `0 0 12px ${TC.primary}80` }} />
            </div>

            {/* Phantom week preview */}
            <div style={{ marginTop: 24, padding: 16, background: 'rgba(255,255,255,0.4)', border: `1px dashed ${TC.line2}`, borderRadius: 16 }}>
              <div style={{ fontSize: 10, color: TC.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>СЛЕДУЮЩАЯ НЕДЕЛЯ · ПРЕВЬЮ</div>
              <div style={{ marginTop: 10, display: 'flex', gap: 4 }}>
                {['ПН','ВТ','СР','ЧТ','ПТ','СБ','ВС'].map((d, i) => (
                  <div key={i} style={{ flex: 1, padding: '10px 4px', background: TC.surf3, borderRadius: 8, textAlign: 'center', opacity: 0.5 }}>
                    <div style={{ fontSize: 9, color: TC.ink3, fontWeight: 700 }}>{d}</div>
                    <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: 700, color: TC.ink, marginTop: 2 }}>—</div>
                  </div>
                ))}
              </div>
            </div>

            <div style={{ marginTop: 18, padding: 14, background: TC.surf3, borderRadius: 12 }}>
              <div style={{ fontSize: 11, color: TC.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>ПОКА ЖДЁШЬ</div>
              <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6 }}>
                <button style={CL.emptyHintBtn}>📱 Подключить Strava →</button>
                <button style={CL.emptyHintBtn}>📊 Заполнить рекорды (5K, 10K, ...) →</button>
              </div>
            </div>
          </div>
        )}

        <div style={{ height: 110 }} />
      </div>

      <MobileNav activeIndex={1} />
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// STYLES
// ─────────────────────────────────────────────────────────────────────
const CL = {
  // Shells with warm radial bg
  mobShell: {
    width: '100%', height: '100%', position: 'relative',
    background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)',
    fontFamily: 'Montserrat, sans-serif', color: TC.ink,
    display: 'flex', flexDirection: 'column', overflow: 'hidden',
  },
  deskShell: {
    width: '100%', height: '100%',
    background: 'radial-gradient(60% 50% at 0% 0%, rgba(252,76,2,0.05) 0%, transparent 50%), radial-gradient(50% 60% at 100% 100%, rgba(252,76,2,0.04) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)',
    display: 'flex', flexDirection: 'column', overflow: 'hidden',
    fontFamily: 'Montserrat, sans-serif', color: TC.ink,
  },

  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700, flexShrink: 0 },
  mobHeader: { padding: '8px 16px 14px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' },
  viewToggleBtn: { width: 40, height: 40, borderRadius: 12, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', cursor: 'pointer', fontSize: 16, fontFamily: 'inherit', color: TC.ink },

  viewTabs: { display: 'flex', gap: 4, padding: '0 16px', marginBottom: 8 },
  viewTab: { flex: 1, padding: '10px', background: 'transparent', border: 'none', borderRadius: 12, fontSize: 13, fontWeight: 600, color: TC.ink3, cursor: 'pointer', fontFamily: 'inherit' },
  viewTabActive: { background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 4px 12px rgba(15,23,42,0.05)', color: TC.ink, fontWeight: 700 },

  mobScroll: { flex: 1, overflow: 'auto', paddingBottom: 100 },
  section: { padding: '0 16px', marginBottom: 12 },

  // Cards — same glass treatment as dashboard
  card: {
    background: 'rgba(255,255,255,0.72)',
    backdropFilter: 'blur(20px) saturate(1.16)',
    WebkitBackdropFilter: 'blur(20px) saturate(1.16)',
    border: '1px solid rgba(252,76,2,0.08)',
    borderRadius: 16, padding: 16,
    boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px rgba(15,23,42,0.06), 0 4px 12px rgba(252,76,2,0.04)',
  },
  dayCard: {
    position: 'relative',
    background: 'rgba(255,255,255,0.78)',
    backdropFilter: 'blur(24px) saturate(1.2)',
    WebkitBackdropFilter: 'blur(24px) saturate(1.2)',
    border: '1px solid rgba(252,76,2,0.12)',
    borderRadius: 18, padding: 18, overflow: 'hidden',
    boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.85), 0 20px 40px rgba(15,23,42,0.08), 0 8px 20px rgba(252,76,2,0.07)',
  },

  // Macro
  macroCompact: { display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 12, boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7)' },
  macroFull: { padding: 4 },

  // Week strip
  weekStripWrap: { display: 'flex', alignItems: 'stretch', gap: 6 },
  weekNavBtn: { width: 28, background: 'transparent', border: 'none', color: TC.ink3, fontSize: 20, cursor: 'pointer', flexShrink: 0, fontFamily: 'inherit' },
  weekStrip: { display: 'flex', gap: 6, flex: 1 },
  dayPill: {
    flex: 1, position: 'relative', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
    padding: '10px 4px', borderRadius: 14, border: '1.5px solid', cursor: 'pointer', fontFamily: 'inherit',
    transition: 'all 0.2s', minHeight: 64,
  },

  keyPill: { background: TC.primary, color: 'white', fontSize: 9, fontWeight: 800, padding: '2px 6px', borderRadius: 4, letterSpacing: '0.06em' },

  aiInline: { display: 'flex', alignItems: 'center', gap: 10, padding: 10, marginTop: 14, background: 'linear-gradient(135deg, rgba(252,76,2,0.08) 0%, rgba(252,76,2,0.02) 100%)', border: `1px solid rgba(252,76,2,0.18)`, borderRadius: 12 },
  aiAvatar: { width: 28, height: 28, borderRadius: '50%', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 10, flexShrink: 0, boxShadow: '0 4px 10px rgba(252,76,2,0.3)' },

  cta: { flex: 1, padding: '14px 18px', borderRadius: 14, background: TC.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 8px 20px rgba(252,76,2,0.3)', fontFamily: 'inherit' },
  ctaIcon: { width: 48, height: 48, borderRadius: 14, background: TC.surf3, color: TC.ink, border: 'none', fontSize: 18, cursor: 'pointer', fontFamily: 'inherit' },
  ctaGhost: { width: '100%', padding: 12, marginTop: 8, background: 'transparent', border: 'none', color: TC.ink3, fontSize: 13, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },

  // Desktop bits
  deskTop: { height: 56, padding: '0 32px', display: 'flex', alignItems: 'center', gap: 12, background: 'rgba(255,255,255,0.7)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)', borderBottom: `1px solid ${TC.line}`, flexShrink: 0 },
  deskGhost: { padding: '8px 14px', background: 'transparent', color: TC.ink2, border: `1px solid ${TC.line}`, borderRadius: 10, fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' },
  deskPrimary: { padding: '8px 16px', background: TC.primary, color: 'white', border: 'none', borderRadius: 10, fontWeight: 700, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 4px 12px rgba(252,76,2,0.25)' },
  deskHeader: { padding: '20px 32px 14px', display: 'flex', alignItems: 'center', gap: 12 },
  weekNavBtnLg: { width: 36, height: 36, borderRadius: 10, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', cursor: 'pointer', fontSize: 18, color: TC.ink, fontFamily: 'inherit' },
  weekTodayBtn: { padding: '8px 16px', background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 10, fontSize: 13, fontWeight: 600, color: TC.ink, cursor: 'pointer', fontFamily: 'inherit' },

  viewToggleSeg: { padding: '7px 14px', background: 'transparent', border: 'none', borderRadius: 7, fontFamily: 'inherit', fontWeight: 600, color: TC.ink3, cursor: 'pointer', fontSize: 12 },
  viewToggleSegActive: { background: 'white', color: TC.ink, fontWeight: 700, boxShadow: '0 2px 6px rgba(0,0,0,0.05)' },

  deskBody: {
    flex: 1, overflow: 'auto', padding: '0 32px 32px',
    display: 'grid', gridTemplateColumns: '1.5fr 1fr', gap: 14, alignContent: 'start',
  },
  deskBodyMonth: {
    flex: 1, overflow: 'auto', padding: '0 32px 32px',
    display: 'grid', gridTemplateColumns: '1fr 360px', gap: 14, alignContent: 'start',
  },

  deskWeekGrid: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 8, marginTop: 14 },
  deskDayCol: {
    background: 'transparent', border: '1px solid', borderRadius: 12, padding: 12,
    cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left',
    display: 'flex', flexDirection: 'column', minHeight: 220,
    transition: 'all 0.15s',
  },

  // Month
  monthCard: { background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 16, padding: 8, boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px rgba(15,23,42,0.06)' },
  monthWeekHeader: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', borderBottom: `1px solid ${TC.line}` },
  monthGrid: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 4, padding: 4 },
  monthCellMob: {
    aspectRatio: '1', position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center',
    background: 'transparent', border: '1.5px solid', borderRadius: 10, cursor: 'pointer',
    fontFamily: 'inherit', transition: 'all 0.15s',
  },

  monthWeekHeaderDesk: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', borderBottom: `1px solid ${TC.line}` },
  monthGridDesk: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 6, padding: 6 },
  monthCellDesk: {
    minHeight: 90, padding: 10, borderRadius: 10,
    transition: 'all 0.15s',
  },

  // Day details / bottom sheet
  bottomSheet: {
    position: 'absolute', bottom: 0, left: 0, right: 0,
    background: 'rgba(255,255,255,0.82)',
    backdropFilter: 'blur(28px) saturate(1.24)',
    WebkitBackdropFilter: 'blur(28px) saturate(1.24)',
    borderRadius: '24px 24px 0 0',
    padding: '12px 18px 28px',
    borderTop: '1px solid rgba(252,76,2,0.12)',
    boxShadow: '0 -20px 50px rgba(0,0,0,0.18), inset 0 1px 0 rgba(255,255,255,0.85)',
    zIndex: 10, maxHeight: '92%', overflow: 'auto',
  },
  sheetGrip: { width: 40, height: 4, borderRadius: 999, background: TC.line2, margin: '0 auto' },
  sheetCloseBtn: { width: 32, height: 32, borderRadius: 10, background: TC.surf3, border: 'none', cursor: 'pointer', color: TC.ink, fontSize: 14, fontFamily: 'inherit' },

  detailTabs: { display: 'flex', gap: 4, marginTop: 14, padding: 3, background: TC.surf3, borderRadius: 10 },
  detailTab: { flex: 1, padding: '7px 10px', background: 'transparent', border: 'none', borderRadius: 7, fontSize: 12, fontWeight: 600, color: TC.ink3, cursor: 'pointer', fontFamily: 'inherit' },
  detailTabActive: { background: 'white', color: TC.ink, fontWeight: 700, boxShadow: '0 2px 6px rgba(0,0,0,0.05)' },

  intervalBar: { display: 'flex', height: 10, borderRadius: 999, overflow: 'hidden', gap: 1, background: TC.surf3 },

  // Empty states
  emptyStep: { display: 'flex', alignItems: 'center', gap: 14, padding: 14, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 12, boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7)' },
  emptyHintBtn: { padding: '10px 12px', background: 'rgba(255,255,255,0.6)', border: `1px solid ${TC.line}`, borderRadius: 8, fontSize: 13, fontWeight: 600, color: TC.ink, cursor: 'pointer', textAlign: 'left', fontFamily: 'inherit' },
};

window.CalWeekMobile = CalWeekMobile;
window.CalWeekDesktop = CalWeekDesktop;
window.CalMonthMobile = CalMonthMobile;
window.CalMonthDesktop = CalMonthDesktop;
window.CalDayDetailsMobile = CalDayDetailsMobile;
window.CalEmpty = CalEmpty;

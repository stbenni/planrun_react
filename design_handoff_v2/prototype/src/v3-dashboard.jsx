/* v3 Dashboard — редизайн дашборда бегуна.
   3 артборда: Mobile, Desktop, Empty-states.
   Реализует 6 предложений из Dashboard Suggestions:
   - Зафиксированная структура (5 секций)
   - AI интегрирован в "Сегодня"
   - "Сегодня" = hero
   - Десктоп = 2 колонки
   - Режим в шапке
   - Объединённая "Цель"                                            */

const { useState: useStateDB } = React;
const TD = V2.T;

// ────────────────────────────────────────────────────────────────────
// SHARED: Header (используется и на мобайле и на десктопе)
// ────────────────────────────────────────────────────────────────────
function DashHeader({ desktop, mode = 'ai', weekSummary = '5/5 ключевых · 60 км · форма растёт', compact }) {
  return (
    <div style={desktop ? DB.headerDesk : DB.headerMob}>
      {/* Left: greeting */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1, minWidth: 0 }}>
        <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={desktop ? 48 : 40} />
        <div style={{ minWidth: 0, flex: 1 }}>
          <div style={{ fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>
            {desktop ? 'Вторник · 12 мая' : 'ВТ · 12 МАЯ'}
          </div>
          <div style={{ fontSize: desktop ? 24 : 20, fontWeight: 800, color: TD.ink, letterSpacing: '-0.02em', lineHeight: 1.1, marginTop: 2, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
            Привет, Алексей{desktop ? ' 👋' : ''}
          </div>
          {desktop && (
            <div style={{ fontSize: 12, color: TD.ink2, marginTop: 4 }}>
              На этой неделе: <b style={{ color: TD.ink }}>{weekSummary}</b>
            </div>
          )}
        </div>
      </div>

      {/* Right: mode badge */}
      {compact ? (
        <button style={DB.modeBadgeCompact} title={mode === 'ai' ? 'AI-тренер' : 'Михаил К.'}>
          {mode === 'ai' ? (
            <>
              <div style={{ ...DB.aiAvatar, width: 32, height: 32, fontSize: 11 }}>AI</div>
              <span style={{ width: 7, height: 7, borderRadius: 999, background: TD.success, position: 'absolute', bottom: 4, right: 4, border: '2px solid white' }} />
            </>
          ) : (
            <>
              <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={32} />
              <span style={{ width: 7, height: 7, borderRadius: 999, background: TD.success, position: 'absolute', bottom: 4, right: 4, border: '2px solid white' }} />
            </>
          )}
        </button>
      ) : (
        <div style={DB.modeBadge}>
          {mode === 'ai' ? (
            <>
              <div style={DB.aiAvatar}>AI</div>
              <div>
                <div style={{ fontSize: 11, color: TD.ink3, fontWeight: 600, letterSpacing: '0.04em' }}>РЕЖИМ</div>
                <div style={{ fontSize: 13, fontWeight: 700, color: TD.ink, display: 'flex', alignItems: 'center', gap: 5 }}>
                  AI-тренер <span style={{ width: 7, height: 7, borderRadius: 999, background: TD.success }} />
                </div>
              </div>
            </>
          ) : (
            <>
              <div style={{ position: 'relative' }}>
                <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={36} />
                <div style={{ position: 'absolute', bottom: -1, right: -1, width: 11, height: 11, borderRadius: '50%', background: TD.success, border: '2px solid white' }} />
              </div>
              <div>
                <div style={{ fontSize: 11, color: TD.ink3, fontWeight: 600, letterSpacing: '0.04em' }}>ТРЕНЕР · ОНЛАЙН</div>
                <div style={{ fontSize: 13, fontWeight: 700, color: TD.ink }}>Михаил К.</div>
              </div>
            </>
          )}
        </div>
      )}

      {/* Bell — only on desktop */}
      {desktop && <button style={DB.bellBtn}>🔔<span style={DB.bellDot} /></button>}
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// SHARED: Today Hero (AI + workout в одной карточке)
// aiStyle: 'quote' (default) | 'minimal' | 'banner' | 'none'
// ────────────────────────────────────────────────────────────────────
function TodayHero({ large, mode = 'ai', aiStyle = 'quote' }) {
  const t = V2.TODAY;
  const tc = V2.typeColor(t.type);
  const isAI = mode === 'ai';
  const speaker = isAI ? 'AI-тренер' : 'Михаил';
  const time = isAI ? '7:42' : '8:14';
  const shortNote = 'Темповая — про контроль. Стартуй спокойно.';

  const aiHead = isAI
    ? <div style={DB.aiInlineAvatar}>AI</div>
    : <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={32} />;

  return (
    <div style={{ ...DB.todayCard, ...(large ? DB.todayCardLg : {}) }}>
      {/* Diagonal accent */}
      <div style={{
        position: 'absolute', top: 0, right: 0, width: large ? 240 : 160, height: large ? 240 : 160,
        background: `radial-gradient(circle at top right, ${tc}22 0%, transparent 70%)`,
        pointerEvents: 'none',
      }} />

      {/* Type ribbon */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ width: 8, height: 8, borderRadius: 999, background: tc }} />
        <span style={{ fontSize: 11, color: TD.ink3, fontWeight: 700, letterSpacing: '0.12em' }}>
          ТЕМПОВАЯ · КЛЮЧЕВАЯ
        </span>
      </div>

      {/* Title */}
      <h1 style={{ ...DB.todayTitle, fontSize: large ? 56 : 34 }}>
        4×1 км<br/>
        <span style={{ color: tc }}>в темпе</span>
      </h1>

      {/* Metrics */}
      <div style={DB.todayMetrics}>
        <Metric n="8,0" l="км" />
        <Metric n="4:30" l="темп /км" accent />
        <Metric n="42′" l="время ~" />
      </div>

      {/* Interval bar */}
      <div style={DB.intervalBar}>
        {t.segments.map((s, i) => (
          <div key={i} style={{ flex: s.km, background: V2.typeColor(s.type) }} />
        ))}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 10, color: TD.ink3, fontWeight: 600 }}>
        <span>Разм</span><span>1км × 4 + восст.</span><span>Зам</span>
      </div>

      {/* AI block — variant */}
      {aiStyle === 'minimal' && (
        <button style={DB.aiMinimal}>
          {aiHead}
          <span style={{ fontSize: 12.5, color: TD.ink2, flex: 1, textAlign: 'left', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
            <b style={{ color: TD.ink }}>{speaker}:</b> {shortNote}
          </span>
          <span style={{ color: TD.primary, fontWeight: 700, fontSize: 13 }}>→</span>
        </button>
      )}

      {aiStyle === 'quote' && (
        <div style={DB.aiQuote}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            {aiHead}
            <div style={{ fontSize: 11, color: TD.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>
              {speaker.toUpperCase()} · {time}
            </div>
            <div style={{ flex: 1 }} />
            <button style={DB.aiBtnSmall}>Спросить →</button>
          </div>
          <div style={{ fontSize: 13.5, color: TD.ink, lineHeight: 1.5 }}>
            {t.coachNote}
          </div>
        </div>
      )}

      {aiStyle === 'banner' && (
        <button style={DB.aiBanner}>
          {aiHead}
          <div style={{ flex: 1, textAlign: 'left' }}>
            <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.75)', fontWeight: 600, letterSpacing: '0.04em' }}>{speaker.toUpperCase()} · {time}</div>
            <div style={{ fontSize: 13, color: 'white', fontWeight: 600, marginTop: 2, lineHeight: 1.35 }}>{shortNote}</div>
          </div>
          <span style={{ color: 'white', fontSize: 16 }}>→</span>
        </button>
      )}

      {/* CTAs */}
      <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
        <button style={DB.cta}>Начать тренировку →</button>
        <button style={DB.ctaIcon} title="Перенести">↔</button>
        <button style={DB.ctaIcon} title="Отметить выполненной">✓</button>
      </div>
    </div>
  );
}

function Metric({ n, l, accent }) {
  return (
    <div style={{ flex: 1 }}>
      <div style={{
        fontFamily: '"Jost", sans-serif', fontWeight: 800, fontSize: 36,
        color: accent ? TD.primary : TD.ink,
        letterSpacing: '-0.03em', lineHeight: 1,
      }}>{n}</div>
      <div style={{ fontSize: 11, color: TD.ink3, marginTop: 4, fontWeight: 600, letterSpacing: '0.04em' }}>{l}</div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// SHARED: Week section
// ────────────────────────────────────────────────────────────────────
function WeekSection({ compact }) {
  const totalKm = V2.WEEK.reduce((s, d) => s + d.km, 0);
  const doneCnt = V2.WEEK.filter(d => d.status === 'done').length;
  return (
    <div style={DB.card}>
      <div style={DB.cardHead}>
        <div>
          <div style={DB.eyebrow}>НЕДЕЛЯ 12 · 11–17 МАЯ</div>
          <div style={{ marginTop: 6, display: 'flex', alignItems: 'baseline', gap: 12 }}>
            <span style={{ fontFamily: '"Jost", sans-serif', fontSize: compact ? 28 : 36, fontWeight: 800, color: TD.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{totalKm}</span>
            <span style={{ fontSize: 13, color: TD.ink3 }}>км запланировано</span>
            <span style={{ flex: 1 }} />
            <span style={{ fontSize: 13, fontWeight: 700, color: TD.success }}>✓ {doneCnt}/{V2.WEEK.filter(d => d.km > 0).length}</span>
          </div>
        </div>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 14 }}>
        {V2.WEEK.map((d, i) => {
          const isToday = d.status === 'today';
          const isDone = d.status === 'done';
          return (
            <div key={i} style={{
              display: 'flex', gap: 10, padding: '10px 12px', borderRadius: 10, alignItems: 'center',
              background: isToday ? TD.primaryWash : isDone ? TD.surf3 : 'transparent',
              border: isToday ? `1.5px solid ${TD.primary}` : `1px solid ${isDone ? 'transparent' : TD.line}`,
            }}>
              <div style={{ width: 32, textAlign: 'center', flexShrink: 0 }}>
                <div style={{ fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{d.day}</div>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 17, fontWeight: 800, color: isToday ? TD.primary : isDone ? TD.ink2 : TD.ink, letterSpacing: '-0.02em', lineHeight: 1, marginTop: 2 }}>{d.date}</div>
              </div>
              <div style={{ width: 3, alignSelf: 'stretch', background: V2.typeColor(d.type), borderRadius: 4, opacity: isDone ? 0.5 : 1 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontWeight: 600, fontSize: 13.5, color: isDone ? TD.ink2 : TD.ink, textDecoration: isDone ? 'line-through' : 'none' }}>{d.label}</span>
                  {d.key && <span style={DB.keyPill}>КЛЮЧ</span>}
                </div>
                {d.km > 0 && <div style={{ fontSize: 11, color: TD.ink3, fontFamily: '"Jost", sans-serif', marginTop: 2 }}>{d.km} км</div>}
              </div>
              {isDone && <span style={DB.checkBadge}>✓</span>}
              {isToday && <span style={DB.todayPill}>СЕГОДНЯ</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// SHARED: Goal (combined countdown + prediction + trend)
// ────────────────────────────────────────────────────────────────────
function GoalSection({ darkHero }) {
  const g = V2.GOAL;
  return (
    <div style={DB.card}>
      <div style={DB.eyebrow}>ГЛАВНАЯ ЦЕЛЬ</div>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: TD.ink, letterSpacing: '-0.01em', marginTop: 6 }}>{g.title}</h2>
      <div style={{ fontSize: 12, color: TD.ink3 }}>{g.date}</div>

      {/* Dark countdown */}
      <div style={DB.darkBox}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
          <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 56, fontWeight: 800, color: 'white', letterSpacing: '-0.04em', lineHeight: 1 }}>{g.daysLeft}</span>
          <span style={{ fontSize: 13, color: 'rgba(255,255,255,0.7)' }}>дней до старта</span>
        </div>
        <div style={{ marginTop: 12, height: 4, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
          <div style={{ width: `${g.progress * 100}%`, height: '100%', background: TD.primary }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 10, color: 'rgba(255,255,255,0.65)' }}>
          <span>Неделя {g.weeksDone}/{g.weeksTotal}</span>
          <span>Фаза: развивающая</span>
        </div>
      </div>

      {/* Prediction vs target */}
      <div style={DB.predRow}>
        <div>
          <div style={DB.predLbl}>ЦЕЛЬ</div>
          <div style={DB.predNum}>{g.target}</div>
        </div>
        <span style={{ fontSize: 18, color: TD.ink4 }}>→</span>
        <div>
          <div style={DB.predLbl}>ПРОГНОЗ</div>
          <div style={{ ...DB.predNum, color: TD.success }}>{g.predicted}</div>
          <div style={{ fontSize: 10, color: TD.success, fontWeight: 700 }}>↓ {g.trend}</div>
        </div>
      </div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// SHARED: Form (полный TrainingLoad с графиком — как было, но почище)
// ────────────────────────────────────────────────────────────────────
function FormSection() {
  // 28 дней данных
  const ctl = [44,44,45,45,46,46,47,47,47,48,48,49,49,49,50,50,50,50,50,51,51,50,50,50,50,50,50,50];
  const atl = [28,30,34,38,42,38,32,28,32,38,42,36,30,28,34,40,38,32,28,32,38,34,30,28,32,36,34,32];
  const tsb = ctl.map((c, i) => c - atl[i]);

  return (
    <div style={DB.card}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
        <div>
          <div style={DB.eyebrow}>ФОРМА И НАГРУЗКА</div>
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 10, marginTop: 8 }}>
            <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 48, fontWeight: 800, color: TD.success, letterSpacing: '-0.04em', lineHeight: 1 }}>+18</span>
            <div style={{ paddingBottom: 6 }}>
              <div style={{ fontSize: 14, fontWeight: 700, color: TD.success, display: 'flex', alignItems: 'center', gap: 6 }}>
                Свежий <span style={{ width: 7, height: 7, borderRadius: 999, background: TD.success }} />
              </div>
              <div style={{ fontSize: 10, color: TD.ink3, fontWeight: 600 }}>TSB · готов к нагрузке</div>
            </div>
          </div>
        </div>
        <button style={DB.tinyToggle}>28 дн ▾</button>
      </div>

      {/* Full ATL / CTL / TSB chart */}
      <div style={DB.tlChart}>
        <LineChart series={[
          { data: ctl, color: TD.info, label: 'CTL · форма' },
          { data: atl, color: TD.warning, label: 'ATL · усталость' },
          { data: tsb, color: TD.success, label: 'TSB · свежесть', dashed: false },
        ]} w={300} h={120} />
      </div>

      <div style={DB.tlLegend}>
        <LegendItem color={TD.info}    label="CTL форма"     v="50" />
        <LegendItem color={TD.warning} label="ATL усталость" v="32" />
        <LegendItem color={TD.success} label="TSB свежесть" v="+18" />
      </div>

      <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
        <MiniStat label="ACWR" v="1.1" color={TD.success} sub="опт." />
        <MiniStat label="TRIMP сегодня" v="56" color={TD.ink2} />
        <MiniStat label="7 дн" v="284" color={TD.ink2} />
      </div>

      <div style={DB.recoBox}>
        <div style={{ fontSize: 13, lineHeight: 1.5 }}>
          💡 <b>Рекомендация:</b> можно увеличить нагрузку. Целевой TRIMP сегодня: 40–65.
        </div>
      </div>
    </div>
  );
}

function MiniStat({ label, v, sub, color }) {
  return (
    <div style={{ flex: 1, padding: '8px 10px', background: TD.surf3, borderRadius: 8 }}>
      <div style={{ fontSize: 9, color: TD.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 3 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 18, fontWeight: 700, color: color || TD.ink, lineHeight: 1 }}>{v}</span>
        {sub && <span style={{ fontSize: 9, color: color, fontWeight: 700 }}>{sub}</span>}
      </div>
    </div>
  );
}

function LegendItem({ color, label, v }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, fontWeight: 600 }}>
      <span style={{ width: 8, height: 2, background: color, borderRadius: 999 }} />
      <span style={{ color: TD.ink3 }}>{label}</span>
      <span style={{ fontFamily: '"Jost", sans-serif', color, fontWeight: 700 }}>{v}</span>
    </div>
  );
}

function LineChart({ series, w = 300, h = 120 }) {
  const allValues = series.flatMap(s => s.data);
  const max = Math.max(...allValues, 1);
  const min = Math.min(...allValues, 0);
  const range = max - min || 1;
  const len = series[0].data.length;
  const step = w / (len - 1);
  const yFor = (v) => h - ((v - min) / range) * (h - 8) - 4;

  return (
    <svg width="100%" height={h} viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" style={{ display: 'block', overflow: 'visible', maxWidth: '100%' }}>
      {/* zero line */}
      <line x1={0} x2={w} y1={yFor(0)} y2={yFor(0)} stroke={TD.line} strokeDasharray="2 2" />
      {series.map((s, si) => {
        const d = 'M ' + s.data.map((v, i) => `${i * step} ${yFor(v)}`).join(' L ');
        return (
          <g key={si}>
            <path d={d} stroke={s.color} strokeWidth="2" fill="none" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
            <circle cx={(len - 1) * step} cy={yFor(s.data[len - 1])} r="3" fill={s.color} />
          </g>
        );
      })}
    </svg>
  );
}

// ────────────────────────────────────────────────────────────────────
// SHARED: Personal Records
// ────────────────────────────────────────────────────────────────────
function PRSection({ compact }) {
  const prs = [
    { label: '5K',  time: '20:14', date: '5 мая', vdot: 52, fresh: true },
    { label: '10K', time: '43:18', date: '21 апр', vdot: 51, fresh: false },
    { label: 'ПОЛУ', time: '1:36:42', date: '14 мар', vdot: 50, fresh: false },
    { label: 'МАРАФОН', time: '—', date: null, vdot: null, fresh: false },
  ];
  return (
    <div style={DB.card}>
      <div style={DB.eyebrow}>ЛИЧНЫЕ РЕКОРДЫ</div>
      <div style={{ display: 'grid', gridTemplateColumns: compact ? '1fr 1fr' : 'repeat(4, 1fr)', gap: 8, marginTop: 10 }}>
        {prs.map(pr => (
          <div key={pr.label} style={{
            padding: 12, background: pr.time === '—' ? TD.surf3 : 'white', border: `1px solid ${pr.fresh ? TD.primary + '40' : TD.line}`,
            borderRadius: 10, position: 'relative',
          }}>
            {pr.fresh && <span style={DB.prBadge}>★ НОВЫЙ</span>}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{pr.label}</div>
              {pr.vdot && <div style={{ fontSize: 10, color: TD.primary, fontWeight: 700, fontFamily: '"Jost", sans-serif' }}>VDOT {pr.vdot}</div>}
            </div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: pr.time === '—' ? 22 : 18, fontWeight: 700, color: pr.time === '—' ? TD.ink4 : TD.ink, letterSpacing: '-0.02em', marginTop: 6 }}>
              {pr.time}
            </div>
            {pr.date && <div style={{ fontSize: 10, color: TD.ink3, marginTop: 2 }}>{pr.date}</div>}
          </div>
        ))}
      </div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// SHARED: Trends (small widget for desktop sidebar)
// ────────────────────────────────────────────────────────────────────
function TrendsSmall() {
  return (
    <div style={DB.card}>
      <div style={DB.eyebrow}>ОБЪЁМ · vs прошлый месяц</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 10, marginTop: 8 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: TD.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>248</span>
        <span style={{ fontSize: 12, color: TD.ink3 }}>км</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 13, color: TD.success, fontWeight: 700 }}>+18%</span>
      </div>
      <V2.Sparkline data={[180,195,210,220,230,238,248]} w={300} h={32} color={TD.success} bg thick />
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// EXTRA WIDGETS (Next, RacePrediction, PaceZones, Stats)
// ────────────────────────────────────────────────────────────────────
function NextWorkoutSection() {
  return (
    <div style={DB.card}>
      <div style={DB.eyebrow}>СЛЕДУЮЩАЯ ТРЕНИРОВКА · ЧТ · 14 МАЯ</div>
      <div style={{ display: 'flex', gap: 14, alignItems: 'center', marginTop: 12 }}>
        <span style={{ width: 4, alignSelf: 'stretch', background: V2.typeColor('easy'), borderRadius: 4, minHeight: 60 }} />
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 17, fontWeight: 700, color: TD.ink, letterSpacing: '-0.01em' }}>Лёгкий 10 км</div>
          <div style={{ fontSize: 12, color: TD.ink3, marginTop: 2 }}>5:45 /км · ЧСС зона 2 · ≈ 58 мин</div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 24, fontWeight: 700, color: TD.ink, lineHeight: 1, letterSpacing: '-0.02em' }}>10<span style={{ fontSize: 12, color: TD.ink3 }}> км</span></div>
        </div>
      </div>
      <button style={DB.subtleBtn}>Открыть детали →</button>
    </div>
  );
}

function RacePredictionSection() {
  const preds = [
    { d: '5 км',  t: '20:42', delta: '−18″' },
    { d: '10 км', t: '43:18', delta: '−42″' },
    { d: '21.1 км (полу)', t: '1:35:42', delta: '−1:28', target: true },
    { d: '42.2 км (марафон)', t: '3:18:24', delta: '−4:12' },
  ];
  return (
    <div style={DB.card}>
      <div style={DB.eyebrow}>VDOT-ПРОГНОЗЫ · 52</div>
      <div style={{ marginTop: 8, fontSize: 12, color: TD.ink2 }}>
        На основе твоих недавних результатов и тренировок
      </div>
      <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 6 }}>
        {preds.map(p => (
          <div key={p.d} style={{
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: 10, background: p.target ? TD.primaryWash : TD.surf3, borderRadius: 8,
            border: p.target ? `1px solid ${TD.primary}30` : '1px solid transparent',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: TD.ink }}>{p.d}</span>
              {p.target && <span style={DB.keyPill}>ЦЕЛЬ</span>}
            </div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 700, color: TD.ink, letterSpacing: '-0.02em' }}>{p.t}</span>
              <span style={{ fontSize: 10, color: TD.success, fontWeight: 700 }}>{p.delta}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function PaceZonesSection() {
  const zones = [
    { name: 'Восстановительный', pace: '6:00–6:30', color: V2.typeColor('rest'),  use: 'после тяжёлых' },
    { name: 'Лёгкий (E)',        pace: '5:30–6:00', color: V2.typeColor('easy'),  use: 'база, 75–80% объёма' },
    { name: 'Марафонский (M)',   pace: '4:50–5:10', color: V2.typeColor('long'),  use: 'длительные' },
    { name: 'Пороговый (T)',     pace: '4:20–4:40', color: V2.typeColor('tempo'), use: 'темповые' },
    { name: 'Интервальный (I)',  pace: '3:45–4:05', color: V2.typeColor('interval'), use: 'VO2max' },
  ];
  return (
    <div style={DB.card}>
      <div style={DB.eyebrow}>ТРЕНИРОВОЧНЫЕ ЗОНЫ</div>
      <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 5 }}>
        {zones.map(z => (
          <div key={z.name} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 10px', background: TD.surf3, borderRadius: 8 }}>
            <span style={{ width: 3, alignSelf: 'stretch', minHeight: 28, background: z.color, borderRadius: 4 }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: TD.ink }}>{z.name}</div>
              <div style={{ fontSize: 10, color: TD.ink3 }}>{z.use}</div>
            </div>
            <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: 700, color: TD.ink, letterSpacing: '-0.01em' }}>{z.pace}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function StatsSection() {
  const [period, setPeriod] = useStateDB('month');
  const stats = {
    month:   { dist: '248', work: '18', time: '21:14', pace: '5:12' },
    quarter: { dist: '690', work: '52', time: '62:48', pace: '5:18' },
    year:    { dist: '2840', work: '218', time: '256:32', pace: '5:22' },
  };
  const s = stats[period];
  return (
    <div style={DB.card}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div style={DB.eyebrow}>СТАТИСТИКА</div>
        <div style={{ display: 'flex', gap: 2, background: TD.surf3, borderRadius: 6, padding: 2 }}>
          {[['month','Мес'],['quarter','Квартал'],['year','Год']].map(([k,l]) => (
            <button key={k} onClick={() => setPeriod(k)} style={{
              padding: '4px 10px', background: period === k ? 'white' : 'transparent', border: 'none',
              borderRadius: 4, fontSize: 11, fontWeight: 600, color: period === k ? TD.ink : TD.ink3,
              cursor: 'pointer', fontFamily: 'inherit',
            }}>{l}</button>
          ))}
        </div>
      </div>
      <div style={{ marginTop: 12, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
        <StatTile lbl="ДИСТАНЦИЯ" v={s.dist} u="км" />
        <StatTile lbl="ТРЕНИРОВОК" v={s.work} u="" />
        <StatTile lbl="ВРЕМЯ" v={s.time} u="ч" />
        <StatTile lbl="СРЕДН. ТЕМП" v={s.pace} u="/км" />
      </div>
    </div>
  );
}

function StatTile({ lbl, v, u }) {
  return (
    <div style={{ padding: 12, background: TD.surf3, borderRadius: 10 }}>
      <div style={{ fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{lbl}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 4, marginTop: 4 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 800, color: TD.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{v}</span>
        {u && <span style={{ fontSize: 11, color: TD.ink3 }}>{u}</span>}
      </div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// MOBILE DASHBOARD
// ────────────────────────────────────────────────────────────────────
function MobileDashV3({ mode = 'ai', aiStyle = 'quote' }) {
  const [active, setActive] = useStateDB('today');
  const scrollRef = React.useRef(null);
  const todayRef = React.useRef(null);
  const weekRef  = React.useRef(null);
  const goalRef  = React.useRef(null);
  const formRef  = React.useRef(null);
  const prRef    = React.useRef(null);
  const moreRef  = React.useRef(null);

  const refs = { today: todayRef, week: weekRef, goal: goalRef, form: formRef, pr: prRef, more: moreRef };

  const tabs = [
    { id: 'today', label: 'Сегодня' },
    { id: 'week',  label: 'Неделя' },
    { id: 'goal',  label: 'Цель' },
    { id: 'form',  label: 'Форма' },
    { id: 'pr',    label: 'PR' },
    { id: 'more',  label: 'Ещё' },
  ];

  const scrollTo = (id) => {
    const el = refs[id]?.current;
    const root = scrollRef.current;
    if (!el || !root) return;
    setActive(id);
    const top = el.offsetTop - 8;
    root.scrollTo({ top, behavior: 'smooth' });
  };

  const onScroll = () => {
    const root = scrollRef.current;
    if (!root) return;
    const y = root.scrollTop + 60;
    let cur = 'today';
    for (const t of tabs) {
      const el = refs[t.id]?.current;
      if (el && el.offsetTop <= y) cur = t.id;
    }
    if (cur !== active) setActive(cur);
  };

  return (
    <div style={DB.mobShell}>
      <div style={DB.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={{ padding: '0 16px' }}>
        <DashHeader mode={mode} compact />
      </div>

      {/* Sticky tabs */}
      <div style={DB.stickyTabsWrap}>
        <div style={DB.stickyTabs}>
          {tabs.map(s => (
            <button key={s.id} onClick={() => scrollTo(s.id)}
              style={{ ...DB.tabBtn, ...(active === s.id ? DB.tabBtnActive : {}) }}>
              {s.label}
            </button>
          ))}
        </div>
      </div>

      <div ref={scrollRef} onScroll={onScroll} style={DB.mobScroll}>
        {/* Сегодня */}
        <div ref={todayRef} style={DB.mobSection}><TodayHero mode={mode} aiStyle={aiStyle} /></div>
        <div style={DB.mobSection}><NextWorkoutSection /></div>

        {/* Неделя */}
        <div ref={weekRef}  style={DB.mobSection}><WeekSection compact /></div>

        {/* Цель */}
        <div ref={goalRef}  style={DB.mobSection}><GoalSection /></div>

        {/* Форма */}
        <div ref={formRef}  style={DB.mobSection}><FormSection /></div>

        {/* PR */}
        <div ref={prRef}    style={DB.mobSection}><PRSection compact /></div>

        {/* Ещё */}
        <div ref={moreRef}  style={DB.mobSection}><RacePredictionSection /></div>
        <div                 style={DB.mobSection}><PaceZonesSection /></div>
        <div                 style={DB.mobSection}><StatsSection /></div>
        <div                 style={DB.mobSection}><TrendsSmall /></div>

        <div style={DB.mobSection}>
          <button style={DB.customHint}>
            <span style={{ fontSize: 18 }}>⚙</span>
            <div style={{ flex: 1, textAlign: 'left' }}>
              <div style={{ fontWeight: 700, fontSize: 13 }}>Настроить дэшборд</div>
              <div style={{ fontSize: 11, color: TD.ink3 }}>Можно убрать ненужные виджеты</div>
            </div>
            <span style={{ color: TD.ink3 }}>→</span>
          </button>
        </div>

        <div style={{ height: 110 }} />
      </div>

      {/* FAB for AI chat */}
      <button style={DB.fab} title={mode === 'ai' ? 'Открыть AI-чат' : 'Открыть чат с тренером'}>
        {mode === 'ai' ? <span style={{ fontWeight: 800, fontSize: 13 }}>AI</span> : <span style={{ fontWeight: 800, fontSize: 13 }}>МК</span>}
      </button>

      {/* Bottom nav (Variant C) */}
      <MobileNav activeIndex={0} />
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// DESKTOP DASHBOARD (1440×900, internal scroll)
// ────────────────────────────────────────────────────────────────────
function DesktopDashV3({ mode = 'ai' }) {
  return (
    <div style={DB.deskShell}>
      {/* App top */}
      <div style={DB.deskTop}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 30, height: 30, borderRadius: 9, background: TD.primary, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15 }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em' }}>planrun</span>
        </div>
        <nav style={{ display: 'flex', gap: 2, marginLeft: 24 }}>
          {[
            ['Дэшборд', true],
            ['Календарь', false],
            ['Чат', false],
            ['Прогресс', false],
            ['Настройки', false],
          ].map(([l, on]) => (
            <a key={l} style={{ ...DB.deskNavItem, ...(on ? DB.deskNavItemActive : {}) }}>{l}</a>
          ))}
        </nav>
        <div style={{ flex: 1 }} />
        <button style={DB.deskGhostBtn}>⚙ Настроить виджеты</button>
        <button style={DB.deskAddBtn}>+ Тренировка</button>
      </div>

      {/* Header with greeting */}
      <div style={{ padding: '24px 32px 0' }}>
        <DashHeader desktop mode={mode} />
      </div>

      {/* 2-column grid (scrolls) */}
      <div style={DB.deskGrid}>
        {/* Left main */}
        <div style={DB.deskMain}>
          <TodayHero large mode={mode} />
          <NextWorkoutSection />
          <WeekSection />
          <FormSection />
          <StatsSection />
        </div>

        {/* Right sidebar */}
        <aside style={DB.deskSide}>
          <GoalSection />
          <PRSection compact />
          <TrendsSmall />
          <RacePredictionSection />
          <PaceZonesSection />
        </aside>
      </div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// EMPTY STATES (тройной артборд)
// ────────────────────────────────────────────────────────────────────
function EmptyStates({ state = 'no-plan' }) {
  return (
    <div style={DB.mobShell}>
      <div style={DB.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={{ padding: '0 20px' }}>
        <DashHeader mode="ai" />
      </div>

      <div style={{ flex: 1, overflow: 'auto', padding: 20 }}>
        {state === 'no-plan' && <EmptyNoPlan />}
        {state === 'generating' && <EmptyGenerating />}
        {state === 'rest-day' && <EmptyRest />}
      </div>

      <MobileNav activeIndex={0} />
    </div>
  );
}

function EmptyNoPlan() {
  return (
    <div>
      <div style={DB.bigEmoji}>🎯</div>
      <h1 style={{ fontSize: 28, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.1, marginTop: 18 }}>
        Создадим твой<br/>план тренировок
      </h1>
      <p style={{ fontSize: 14, color: TD.ink2, marginTop: 8, lineHeight: 1.5 }}>
        AI-тренер соберёт план на 16 недель под твою цель. Это займёт пару минут.
      </p>

      <div style={{ marginTop: 24, display: 'flex', flexDirection: 'column', gap: 8 }}>
        <StepRow num="1" title="Расскажи о цели" sub="Дистанция, дата гонки, целевое время" />
        <StepRow num="2" title="Поделись текущей формой" sub="Бегал ли последние месяцы, какой темп" />
        <StepRow num="3" title="Получи план" sub="Можно сразу спросить AI про любую тренировку" />
      </div>

      <button style={{ ...DB.cta, marginTop: 28, width: '100%' }}>Начать настройку →</button>
      <button style={{ ...DB.ctaGhost, marginTop: 8, width: '100%' }}>У меня уже есть план</button>
    </div>
  );
}

function StepRow({ num, title, sub }) {
  return (
    <div style={{ display: 'flex', gap: 12, padding: 14, background: 'white', border: `1px solid ${TD.line}`, borderRadius: 12, alignItems: 'flex-start' }}>
      <div style={{ width: 28, height: 28, borderRadius: '50%', background: TD.primaryWash, color: TD.primary, display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 13, flexShrink: 0 }}>{num}</div>
      <div>
        <div style={{ fontSize: 14, fontWeight: 700 }}>{title}</div>
        <div style={{ fontSize: 12, color: TD.ink3, marginTop: 2 }}>{sub}</div>
      </div>
    </div>
  );
}

function EmptyGenerating() {
  return (
    <div>
      {/* Animated dot pattern */}
      <div style={{
        margin: '0 auto', width: 80, height: 80, borderRadius: 24,
        background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)',
        display: 'grid', placeItems: 'center', color: 'white', fontWeight: 800, fontSize: 28,
        boxShadow: '0 12px 28px rgba(252,76,2,0.3)',
        position: 'relative',
      }}>
        AI
        <div style={{ position: 'absolute', inset: -4, border: `2px dashed ${TD.primary}50`, borderRadius: 28, animation: 'spin 3s linear infinite' }} />
      </div>

      <h1 style={{ fontSize: 24, fontWeight: 800, letterSpacing: '-0.02em', lineHeight: 1.2, marginTop: 20, textAlign: 'center' }}>
        Собираю твой план…
      </h1>
      <p style={{ fontSize: 13, color: TD.ink3, marginTop: 6, textAlign: 'center' }}>
        Это займёт 3–5 минут. Уведомлю когда готово.
      </p>

      <div style={DB.genProgress}>
        <div style={DB.genProgressFill} />
      </div>

      {/* Steps */}
      <div style={{ marginTop: 24, display: 'flex', flexDirection: 'column', gap: 6 }}>
        {[
          { done: true,  text: 'Анализирую твой профиль и цели' },
          { done: true,  text: 'Расставляю ключевые тренировки на 16 недель' },
          { done: false, text: 'Балансирую объём и интенсивность', active: true },
          { done: false, text: 'Подстраиваю под твой график' },
        ].map((s, i) => (
          <div key={i} style={{ display: 'flex', gap: 10, fontSize: 13, color: s.done ? TD.ink2 : s.active ? TD.ink : TD.ink4, padding: '6px 0' }}>
            <span style={{ width: 18, color: s.done ? TD.success : s.active ? TD.primary : TD.ink4, fontWeight: 700 }}>
              {s.done ? '✓' : s.active ? '⟳' : '○'}
            </span>
            <span style={{ fontWeight: s.active ? 700 : 400 }}>{s.text}</span>
          </div>
        ))}
      </div>

      <div style={{ marginTop: 28, padding: 16, background: 'white', border: `1px solid ${TD.line}`, borderRadius: 14 }}>
        <div style={{ fontSize: 11, color: TD.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>ПОКА ЖДЁШЬ</div>
        <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <button style={DB.linkBtn}>📱 Подключить Strava / Polar →</button>
          <button style={DB.linkBtn}>📝 Заполнить профиль (рост, вес, ЧСС) →</button>
          <button style={DB.linkBtn}>🎬 Посмотреть как работает AI (1 мин) →</button>
        </div>
      </div>
    </div>
  );
}

function EmptyRest() {
  return (
    <div>
      <div style={DB.bigEmoji}>💤</div>
      <h1 style={{ fontSize: 32, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.1, marginTop: 18 }}>
        Сегодня<br/>день восстановления
      </h1>
      <p style={{ fontSize: 14, color: TD.ink2, marginTop: 8, lineHeight: 1.5 }}>
        Полный отдых или лёгкая активность. Завтра — ключевая темповая.
      </p>

      <div style={{ marginTop: 24, padding: 16, background: TD.successWash, border: `1px solid ${TD.success}30`, borderRadius: 14 }}>
        <div style={{ fontSize: 11, color: '#166534', fontWeight: 700, letterSpacing: '0.08em' }}>💡 ЧТО ПОЛЕЗНО СДЕЛАТЬ</div>
        <div style={{ marginTop: 8, fontSize: 13, color: TD.ink, lineHeight: 1.6 }}>
          • Растяжка 10 мин<br/>
          • Прогулка пешком 30-40 мин<br/>
          • Лечь спать раньше (8 часов сна)
        </div>
      </div>

      {/* Tomorrow preview */}
      <div style={{ marginTop: 20 }}>
        <div style={DB.eyebrow}>ЗАВТРА · СРЕДА</div>
        <div style={{ marginTop: 10, padding: 16, background: 'white', border: `1px solid ${TD.line}`, borderRadius: 14, display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 4, alignSelf: 'stretch', background: TD.warning, borderRadius: 4 }} />
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 700, fontSize: 15 }}>4×1 км в темпе</div>
            <div style={{ fontSize: 12, color: TD.ink3, fontFamily: '"Jost", sans-serif' }}>8 км · 4:30 /км · 42 мин</div>
          </div>
          <span style={{ background: TD.primary, color: 'white', fontSize: 10, fontWeight: 800, padding: '4px 8px', borderRadius: 6 }}>КЛЮЧ</span>
        </div>
      </div>

      {/* Goal reminder */}
      <div style={{ marginTop: 20 }}>
        <div style={DB.eyebrow}>ДО ГОНКИ ОСТАЛОСЬ</div>
        <div style={{ marginTop: 10, padding: 16, background: TD.ink, color: 'white', borderRadius: 14, display: 'flex', alignItems: 'center', gap: 14 }}>
          <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 44, fontWeight: 800, letterSpacing: '-0.04em', lineHeight: 1 }}>{V2.GOAL.daysLeft}</span>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 13, fontWeight: 700 }}>{V2.GOAL.title}</div>
            <div style={{ fontSize: 11, opacity: 0.7 }}>прогноз {V2.GOAL.predicted} · цель {V2.GOAL.target}</div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ────────────────────────────────────────────────────────────────────
// STYLES
// ────────────────────────────────────────────────────────────────────
const DB = {
  // Mobile shell
  mobShell: { width: '100%', height: '100%', background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: TD.ink, position: 'relative' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700, flexShrink: 0 },

  // Headers
  headerMob: { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 0 14px' },
  headerDesk: { display: 'flex', alignItems: 'center', gap: 16, paddingBottom: 16 },
  modeBadge: { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 14px 8px 10px', background: 'white', border: `1px solid ${TD.line}`, borderRadius: 12, boxShadow: '0 2px 6px rgba(0,0,0,0.03)' },
  modeBadgeCompact: { position: 'relative', width: 44, height: 44, padding: 0, borderRadius: 14, background: 'white', border: `1px solid ${TD.line}`, cursor: 'pointer', flexShrink: 0, display: 'grid', placeItems: 'center', fontFamily: 'inherit', boxShadow: '0 2px 6px rgba(0,0,0,0.03)' },
  aiAvatar: { width: 36, height: 36, borderRadius: '50%', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 13, boxShadow: '0 4px 10px rgba(252,76,2,0.25)' },
  bellBtn: { position: 'relative', width: 40, height: 40, borderRadius: 12, border: 'none', background: 'white', cursor: 'pointer', fontSize: 16, flexShrink: 0, boxShadow: '0 2px 6px rgba(0,0,0,0.03)' },
  bellDot: { position: 'absolute', top: 8, right: 9, width: 7, height: 7, borderRadius: 999, background: TD.primary, border: '1.5px solid white' },

  // Sticky tabs
  stickyTabsWrap: { position: 'sticky', top: 0, zIndex: 5, background: 'rgba(255,255,255,0.92)', backdropFilter: 'blur(12px)', WebkitBackdropFilter: 'blur(12px)', borderBottom: `1px solid ${TD.line}`, flexShrink: 0 },
  stickyTabs: { display: 'flex', gap: 4, padding: '8px 16px', overflowX: 'auto', scrollbarWidth: 'none', msOverflowStyle: 'none' },
  tabBtn: { padding: '7px 13px', background: 'transparent', border: 'none', borderRadius: 999, color: TD.ink3, fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap', flexShrink: 0 },
  tabBtnActive: { background: TD.primary, color: 'white', fontWeight: 700, boxShadow: '0 2px 8px rgba(252,76,2,0.25)' },
  mobScroll: { flex: 1, overflow: 'auto', paddingTop: 12, paddingBottom: 100, scrollBehavior: 'smooth' },
  mobSection: { padding: '0 16px', marginBottom: 12 },
  customHint: { display: 'flex', alignItems: 'center', gap: 10, width: '100%', padding: '14px 16px', background: 'white', border: `1px dashed ${TD.line2}`, borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', color: TD.ink },

  // Today hero — strongest glass
  todayCard: { position: 'relative', background: 'rgba(255,255,255,0.78)', backdropFilter: 'blur(24px) saturate(1.2)', WebkitBackdropFilter: 'blur(24px) saturate(1.2)', border: '1px solid rgba(252,76,2,0.12)', borderRadius: 18, padding: 20, overflow: 'hidden', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.85), 0 20px 40px rgba(15,23,42,0.08), 0 8px 20px rgba(252,76,2,0.07)' },
  todayCardLg: { padding: 28 },
  todayTitle: { fontSize: 40, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.02, color: TD.ink, marginTop: 10 },
  todayMetrics: { display: 'flex', gap: 16, marginTop: 18, paddingTop: 16, borderTop: `1px solid ${TD.line}` },
  intervalBar: { display: 'flex', height: 8, borderRadius: 999, overflow: 'hidden', marginTop: 18, gap: 1, background: TD.surf3 },

  aiInline: { display: 'flex', alignItems: 'center', gap: 10, padding: 12, marginTop: 18, background: 'linear-gradient(135deg, rgba(252,76,2,0.06), rgba(252,76,2,0.02))', border: `1px solid ${TD.primary}20`, borderRadius: 12 },
  aiInlineAvatar: { width: 32, height: 32, borderRadius: '50%', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 11, flexShrink: 0 },
  aiBtn: { padding: '6px 12px', background: TD.primary, color: 'white', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 11, cursor: 'pointer', fontFamily: 'inherit', flexShrink: 0 },
  aiBtnSmall: { padding: '5px 10px', background: TD.primary, color: 'white', border: 'none', borderRadius: 7, fontWeight: 700, fontSize: 11, cursor: 'pointer', fontFamily: 'inherit', flexShrink: 0 },
  aiMinimal: { display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', marginTop: 18, background: 'white', border: `1px solid ${TD.line}`, borderRadius: 10, cursor: 'pointer', fontFamily: 'inherit', width: '100%' },
  aiQuote: { padding: 14, marginTop: 18, background: 'linear-gradient(135deg, rgba(252,76,2,0.07), rgba(252,76,2,0.02))', border: `1px solid ${TD.primary}25`, borderRadius: 12 },
  aiBanner: { display: 'flex', alignItems: 'center', gap: 12, padding: 14, marginTop: 18, background: 'linear-gradient(135deg, #FC4C02 0%, #FF6B3D 100%)', border: 'none', borderRadius: 12, cursor: 'pointer', fontFamily: 'inherit', width: '100%', boxShadow: '0 6px 16px rgba(252,76,2,0.3)' },

  cta: { flex: 1, padding: '15px 20px', borderRadius: 14, background: TD.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 8px 20px rgba(252,76,2,0.3)', fontFamily: 'inherit' },
  ctaGhost: { padding: '14px 16px', borderRadius: 14, background: TD.surf3, color: TD.ink2, border: 'none', fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap' },
  ctaIcon: { width: 48, height: 48, borderRadius: 14, background: TD.surf3, color: TD.ink, border: 'none', fontWeight: 600, fontSize: 18, cursor: 'pointer', fontFamily: 'inherit', display: 'grid', placeItems: 'center', flexShrink: 0 },

  // Cards — liquid glass treatment
  card: { background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 16, padding: 18, marginBottom: 12, boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 1px 0 rgba(255,255,255,0.3) inset, 0 12px 28px rgba(15,23,42,0.06), 0 4px 12px rgba(252,76,2,0.04)' },
  cardHead: {},
  eyebrow: { fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' },

  keyPill: { background: TD.primary, color: 'white', fontSize: 9, fontWeight: 800, padding: '2px 5px', borderRadius: 3, letterSpacing: '0.04em' },
  todayPill: { background: TD.primary, color: 'white', fontSize: 9, fontWeight: 800, padding: '3px 8px', borderRadius: 4, letterSpacing: '0.06em' },
  checkBadge: { width: 22, height: 22, borderRadius: '50%', background: TD.success, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 11 },

  darkBox: { marginTop: 16, padding: 18, background: 'linear-gradient(180deg, #0F172A 0%, #1E293B 100%)', borderRadius: 14 },
  predRow: { display: 'flex', alignItems: 'center', gap: 12, marginTop: 14, padding: 14, background: TD.surf3, borderRadius: 12 },
  predLbl: { fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.08em' },
  predNum: { fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: TD.ink, letterSpacing: '-0.02em', marginTop: 3 },

  prBadge: { position: 'absolute', top: -7, right: 8, background: TD.primary, color: 'white', fontSize: 8.5, fontWeight: 800, padding: '2px 6px', borderRadius: 4, letterSpacing: '0.04em' },

  subtleBtn: { marginTop: 10, padding: '8px 12px', background: 'transparent', border: 'none', color: TD.primary, fontWeight: 700, fontSize: 12, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left', display: 'block', width: '100%' },

  // FAB
  fab: { position: 'absolute', bottom: 92, right: 16, width: 56, height: 56, borderRadius: '50%', border: 'none', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', fontSize: 16, fontWeight: 800, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 12px 28px rgba(252,76,2,0.4)', display: 'grid', placeItems: 'center', overflow: 'hidden' },

  // Nav
  nav: { position: 'absolute', bottom: 12, left: 12, right: 12, height: 64, background: 'rgba(255,255,255,0.92)', backdropFilter: 'blur(20px)', borderRadius: 20, border: `1px solid ${TD.line}`, display: 'flex', justifyContent: 'space-around', alignItems: 'center', boxShadow: '0 12px 32px rgba(0,0,0,0.06)' },
  navItem: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 },
  navBadge: { position: 'absolute', top: -2, right: -8, background: TD.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '0 4px', borderRadius: 999, minWidth: 14, textAlign: 'center', lineHeight: '14px' },

  // Desktop shell
  deskShell: { width: '100%', height: '100%', background: 'radial-gradient(60% 50% at 0% 0%, rgba(252,76,2,0.05) 0%, transparent 50%), radial-gradient(50% 60% at 100% 100%, rgba(252,76,2,0.04) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: TD.ink },
  deskTop: { height: 56, padding: '0 32px', display: 'flex', alignItems: 'center', gap: 14, background: 'white', borderBottom: `1px solid ${TD.line}`, flexShrink: 0 },
  deskNavItem: { padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: 500, color: TD.ink2, cursor: 'pointer' },
  deskNavItemActive: { background: TD.surf3, color: TD.ink, fontWeight: 700 },
  deskAddBtn: { padding: '8px 16px', background: TD.primary, color: 'white', border: 'none', borderRadius: 10, fontWeight: 700, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 4px 12px rgba(252,76,2,0.25)' },
  deskGhostBtn: { padding: '8px 14px', background: 'transparent', color: TD.ink2, border: `1px solid ${TD.line}`, borderRadius: 10, fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', marginRight: 8 },

  deskGrid: { flex: 1, overflow: 'auto', padding: '0 32px 32px', display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 18, alignContent: 'start' },
  deskMain: { display: 'flex', flexDirection: 'column', gap: 12 },
  deskSide: { display: 'flex', flexDirection: 'column', gap: 12 },

  // Empty states
  bigEmoji: { fontSize: 64, lineHeight: 1, textAlign: 'center', marginTop: 24 },
  genProgress: { marginTop: 24, height: 6, background: TD.surf3, borderRadius: 999, overflow: 'hidden' },
  genProgressFill: { width: '52%', height: '100%', background: `linear-gradient(90deg, ${TD.primary}, ${TD.primary}cc)`, borderRadius: 999, transition: 'width 0.5s ease' },
  linkBtn: { padding: '10px 12px', background: TD.surf2, border: 'none', borderRadius: 8, fontSize: 13, fontWeight: 600, color: TD.ink, cursor: 'pointer', textAlign: 'left', fontFamily: 'inherit' },
};

// ────────────────────────────────────────────────────────────────────
// DASHBOARD CUSTOMIZER — управление виджетами (тумблеры + пресеты)
// ────────────────────────────────────────────────────────────────────
const DASH_WIDGETS = [
  { id: 'today',    name: 'Сегодняшняя тренировка', emoji: '🎯', desc: 'Главная тренировка дня + AI-совет. Самое важное.', fixed: true },
  { id: 'week',     name: 'Неделя',                 emoji: '📅', desc: 'Все 7 дней с типами тренировок и прогрессом.', preset: ['simple', 'standard', 'pro'] },
  { id: 'goal',     name: 'Главная цель',           emoji: '🏆', desc: 'Countdown + прогноз vs цель + тренд.', preset: ['simple', 'standard', 'pro'] },
  { id: 'next',     name: 'Следующая тренировка',   emoji: '⏭', desc: 'Что планируется после сегодняшней.', preset: ['standard', 'pro'] },
  { id: 'pr',       name: 'Личные рекорды',         emoji: '⭐', desc: '4 карточки: 5K / 10K / Полу / Марафон.', preset: ['simple', 'standard', 'pro'] },
  { id: 'load',     name: 'Форма и нагрузка',       emoji: '📊', desc: 'TSB / ATL / CTL — серьёзная аналитика.', preset: ['standard', 'pro'] },
  { id: 'trend',    name: 'Тренд месяца',           emoji: '📈', desc: 'Этот месяц vs прошлый: км / темп / тренировки.', preset: ['pro'] },
  { id: 'race',     name: 'Прогноз на дистанции',   emoji: '🎲', desc: 'VDOT-прогноз для 5K, 10K, Полу, Марафон.', preset: ['pro'] },
  { id: 'stats',    name: 'Статистика',             emoji: '📉', desc: 'Дистанция / время / тренировки за период.', preset: ['standard', 'pro'] },
  { id: 'pace',     name: 'Тренировочные зоны',     emoji: '⚡', desc: 'Темпы для лёгкого / темпового / интервалов.', preset: ['pro'] },
];

const PRESETS = {
  simple:   { name: 'Простой',     desc: 'Основа: что делать, неделя, цель, рекорды', emoji: '◐' },
  standard: { name: 'Средний',     desc: '+ нагрузка и статистика',                    emoji: '◑' },
  pro:      { name: 'Профи',       desc: 'Все 10 виджетов · максимум данных',          emoji: '●' },
};

function DashCustomizer({ defaultEnabled }) {
  const initial = defaultEnabled || new Set(['today', 'week', 'goal', 'pr', 'load', 'stats']);
  const [enabled, setEnabled] = useStateDB(initial);
  const [preset, setPreset] = useStateDB('standard');

  const toggle = (id) => {
    const w = DASH_WIDGETS.find(x => x.id === id);
    if (w?.fixed) return;
    const n = new Set(enabled);
    if (n.has(id)) n.delete(id); else n.add(id);
    setEnabled(n);
    setPreset('custom');
  };

  const applyPreset = (key) => {
    setPreset(key);
    const n = new Set(['today']);
    DASH_WIDGETS.forEach(w => { if (w.preset?.includes(key)) n.add(w.id); });
    setEnabled(n);
  };

  return (
    <div style={DC.shell}>
      <div style={DB.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={DC.head}>
        <button style={DC.iconBtn}>←</button>
        <div style={{ flex: 1, textAlign: 'center', fontWeight: 700, fontSize: 16 }}>Настройка дэшборда</div>
        <button style={DC.iconBtn}>✕</button>
      </div>

      <div style={DC.body}>
        {/* Presets */}
        <div style={DC.eyebrow}>БЫСТРЫЙ ВЫБОР</div>
        <div style={DC.presetRow}>
          {Object.entries(PRESETS).map(([k, p]) => (
            <button key={k} onClick={() => applyPreset(k)}
              style={{ ...DC.presetCard, ...(preset === k ? DC.presetCardActive : {}) }}>
              <span style={{ fontSize: 22 }}>{p.emoji}</span>
              <div style={{ fontWeight: 700, fontSize: 13, marginTop: 6 }}>{p.name}</div>
              <div style={{ fontSize: 10, color: preset === k ? 'rgba(255,255,255,0.85)' : TD.ink3, marginTop: 4, lineHeight: 1.3 }}>{p.desc}</div>
            </button>
          ))}
        </div>

        {/* Individual widgets */}
        <div style={{ ...DC.eyebrow, marginTop: 24 }}>
          ВКЛЮЧИТЬ ВИДЖЕТЫ · {enabled.size} из {DASH_WIDGETS.length}
        </div>
        <div style={DC.widgetList}>
          {DASH_WIDGETS.map(w => {
            const on = enabled.has(w.id);
            return (
              <button key={w.id} onClick={() => toggle(w.id)}
                disabled={w.fixed}
                style={{ ...DC.widgetRow, ...(on ? DC.widgetRowOn : {}), ...(w.fixed ? DC.widgetRowFixed : {}) }}>
                <span style={{ fontSize: 24, opacity: w.fixed || on ? 1 : 0.4 }}>{w.emoji}</span>
                <div style={{ flex: 1, textAlign: 'left', minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ fontWeight: 700, fontSize: 14 }}>{w.name}</span>
                    {w.fixed && <span style={DC.lockBadge}>всегда</span>}
                  </div>
                  <div style={{ fontSize: 11, color: TD.ink3, marginTop: 2, lineHeight: 1.35 }}>{w.desc}</div>
                </div>
                <div style={{ ...DC.toggleSwitch, background: on ? TD.primary : TD.line2, opacity: w.fixed ? 0.5 : 1 }}>
                  <div style={{ ...DC.toggleKnob, transform: `translateX(${on ? 18 : 0}px)` }} />
                </div>
              </button>
            );
          })}
        </div>

        <div style={DC.tip}>
          💡 <b>Порядок виджетов</b> — фиксированный, отображаются по приоритету. Можно изменить порядок свайпом в режиме «Расставить вручную».
        </div>

        <button style={DC.orderBtn}>↕ Расставить вручную</button>
      </div>

      <div style={DC.foot}>
        <button style={DC.saveBtn}>Сохранить · показать {enabled.size} виджетов</button>
      </div>
    </div>
  );
}

const DC = {
  shell: { width: '100%', height: '100%', background: TD.surf2, fontFamily: 'Montserrat, sans-serif', color: TD.ink, display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  head: { padding: '8px 16px 14px', display: 'flex', alignItems: 'center', gap: 10 },
  iconBtn: { width: 36, height: 36, borderRadius: 10, background: 'transparent', border: 'none', cursor: 'pointer', fontSize: 18, fontFamily: 'inherit', color: TD.ink },
  body: { flex: 1, overflow: 'auto', padding: '0 16px 16px' },
  eyebrow: { fontSize: 10, color: TD.ink3, fontWeight: 700, letterSpacing: '0.12em', marginBottom: 10 },

  presetRow: { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 },
  presetCard: { padding: 14, background: 'white', border: `1.5px solid ${TD.line}`, borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'center', color: TD.ink, transition: 'all 0.15s' },
  presetCardActive: { background: TD.primary, color: 'white', borderColor: TD.primary, boxShadow: '0 6px 18px rgba(252,76,2,0.3)' },

  widgetList: { display: 'flex', flexDirection: 'column', gap: 6 },
  widgetRow: { display: 'flex', alignItems: 'center', gap: 12, padding: 12, background: 'white', border: `1px solid ${TD.line}`, borderRadius: 12, cursor: 'pointer', fontFamily: 'inherit' },
  widgetRowOn: { background: 'white', borderColor: TD.primary + '40' },
  widgetRowFixed: { background: TD.primaryWash, borderColor: TD.primary + '30', cursor: 'default' },
  lockBadge: { fontSize: 8.5, fontWeight: 800, padding: '2px 6px', borderRadius: 4, background: TD.primary, color: 'white', letterSpacing: '0.04em' },

  toggleSwitch: { width: 40, height: 22, borderRadius: 999, padding: 2, flexShrink: 0, transition: 'background 0.2s' },
  toggleKnob: { width: 18, height: 18, borderRadius: '50%', background: 'white', boxShadow: '0 1px 3px rgba(0,0,0,0.2)', transition: 'transform 0.2s' },

  tip: { marginTop: 18, padding: 12, background: TD.surf3, borderRadius: 10, fontSize: 12, color: TD.ink2, lineHeight: 1.5 },
  orderBtn: { marginTop: 10, width: '100%', padding: 12, background: 'transparent', border: `1px dashed ${TD.line2}`, borderRadius: 10, fontSize: 13, fontWeight: 600, color: TD.ink2, cursor: 'pointer', fontFamily: 'inherit' },

  foot: { padding: 16, background: 'white', borderTop: `1px solid ${TD.line}`, flexShrink: 0 },
  saveBtn: { width: '100%', padding: 16, background: TD.primary, color: 'white', border: 'none', borderRadius: 14, fontWeight: 700, fontSize: 15, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 6px 16px rgba(252,76,2,0.3)' },
};

// Style additions for the expanded FormSection
Object.assign(DB, {
  tinyToggle: { padding: '4px 10px', background: TD.surf3, border: 'none', borderRadius: 6, fontSize: 11, fontWeight: 600, color: TD.ink2, cursor: 'pointer', fontFamily: 'inherit' },
  tlChart: { marginTop: 14, padding: '10px 0 6px' },
  tlLegend: { display: 'flex', gap: 14, marginTop: 8, flexWrap: 'wrap' },
  recoBox: { marginTop: 12, padding: '10px 12px', background: TD.successWash, color: '#166534', borderRadius: 10, border: `1px solid ${TD.success}25` },
});

window.MobileDashV3 = MobileDashV3;
window.DesktopDashV3 = DesktopDashV3;
window.EmptyStates = EmptyStates;
window.DashCustomizer = DashCustomizer;

/* Direction C — "Squad Grid"
   Heatmap-first dark UI: вся команда сразу видна как сетка тайлов,
   состояние читается за секунду. Атлет — performance-OS на тёмном фоне
   с крупными метриками.                                                    */

const { useState: useStateC, useMemo: useMemoC } = React;
const TC = PR_TOKENS;

// Dark palette
const DK = {
  bg: '#0B1015',
  bg2: '#13181F',
  bg3: '#1C222B',
  bg4: '#29313B',
  line: '#1F2731',
  line2: '#2A323D',
  ink: '#F1F5F9',
  ink2: '#CBD5E1',
  ink3: '#94A3B8',
  ink4: '#64748B',
  primary: '#FC4C02',
  primary2: '#FF7A45',
  primary3: '#FF9D7A',
  success: '#2ED573',
  warning: '#FFBD3E',
  danger: '#FF5252',
  info: '#5B9DFF',
};

// ─────────────────────────────────────────────────────────────────────
// Coach Desktop (1440×900)
// ─────────────────────────────────────────────────────────────────────
function SquadCoach() {
  const [view, setView] = useStateC('grid'); // grid | compare
  const [activeId, setActiveId] = useStateC(1);
  const [compareIds, setCompareIds] = useStateC(new Set([1, 5, 9]));

  const active = PR_ATHLETES.find(a => a.id === activeId);

  const toggleCompare = (id) => {
    const n = new Set(compareIds);
    if (n.has(id)) n.delete(id); else if (n.size < 4) n.add(id);
    setCompareIds(n);
  };

  return (
    <div style={squadStyles.shell}>
      {/* Top */}
      <div style={squadStyles.top}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <span style={squadStyles.brand}>P</span>
          <span style={{ fontWeight: 800, letterSpacing: '-0.02em', fontSize: 17 }}>planrun</span>
          <span style={{ width: 1, height: 18, background: DK.line2 }} />
          <span style={{ fontSize: 13, color: DK.ink3 }}>Команда «Марафон-осень»</span>
        </div>
        <div style={squadStyles.viewToggle}>
          <button style={{ ...squadStyles.vt, ...(view === 'grid' ? squadStyles.vtActive : {}) }} onClick={() => setView('grid')}>▦ Сетка</button>
          <button style={{ ...squadStyles.vt, ...(view === 'compare' ? squadStyles.vtActive : {}) }} onClick={() => setView('compare')}>≡ Сравнить ({compareIds.size})</button>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <span style={squadStyles.live}>● LIVE</span>
          <span style={{ fontSize: 12, color: DK.ink3 }}>Обновлено сейчас</span>
          <div style={{ width: 36, height: 36, borderRadius: '50%', background: '#FFD9C9', color: DK.bg, display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 12 }}>МК</div>
        </div>
      </div>

      <div style={squadStyles.body}>
        {/* Left: KPI bar */}
        <aside style={squadStyles.kpiRail}>
          <KpiCard label="Compliance" value="82%" delta="+4%" trend="up" />
          <KpiCard label="Активных" value="9 из 12" delta="−1" trend="down" />
          <KpiCard label="Объём команды" value="412 км" delta="+38" trend="up" />
          <KpiCard label="Личные рекорды" value="3" delta="за неделю" trend="neutral" />
          <KpiCard label="Без ответа" value="3" trend="alert" />

          <div style={{ ...squadStyles.kpiCard, padding: 14, marginTop: 8 }}>
            <div style={squadStyles.kpiLbl}>ТЕПЛОВАЯ ШКАЛА</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 8 }}>
              <span style={{ width: 12, height: 12, borderRadius: 3, background: DK.success }} />
              <span style={{ fontSize: 11, color: DK.ink2 }}>80–100%</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
              <span style={{ width: 12, height: 12, borderRadius: 3, background: DK.warning }} />
              <span style={{ fontSize: 11, color: DK.ink2 }}>50–80%</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
              <span style={{ width: 12, height: 12, borderRadius: 3, background: DK.danger }} />
              <span style={{ fontSize: 11, color: DK.ink2 }}>&lt; 50%</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
              <span style={{ width: 12, height: 12, borderRadius: 3, background: DK.bg4 }} />
              <span style={{ fontSize: 11, color: DK.ink2 }}>нет данных</span>
            </div>
          </div>
        </aside>

        {/* Center */}
        <main style={squadStyles.main}>
          {view === 'grid' ? (
            <SquadGrid activeId={activeId} setActiveId={setActiveId} compareIds={compareIds} toggleCompare={toggleCompare} />
          ) : (
            <CompareView compareIds={compareIds} toggleCompare={toggleCompare} />
          )}
        </main>

        {/* Right: athlete detail */}
        <aside style={squadStyles.detail}>
          <AthleteDetail a={active} />
        </aside>
      </div>
    </div>
  );
}

function KpiCard({ label, value, delta, trend }) {
  const trendColor = trend === 'up' ? DK.success : trend === 'down' ? DK.danger : trend === 'alert' ? DK.warning : DK.ink3;
  return (
    <div style={squadStyles.kpiCard}>
      <div style={squadStyles.kpiLbl}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 4 }}>
        <div style={squadStyles.kpiVal}>{value}</div>
      </div>
      {delta && <div style={{ fontSize: 11, color: trendColor, fontWeight: 600, marginTop: 2, fontFamily: '"Jost", sans-serif' }}>
        {trend === 'up' ? '▲ ' : trend === 'down' ? '▼ ' : trend === 'alert' ? '⚠ ' : ''}
        {delta}
      </div>}
    </div>
  );
}

function SquadGrid({ activeId, setActiveId, compareIds, toggleCompare }) {
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 16 }}>
        <div>
          <div style={{ fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em', color: DK.ink }}>Сетка команды</div>
          <div style={{ fontSize: 12, color: DK.ink3, marginTop: 2 }}>12 атлетов · кликни тайл для деталей, ⊞ для сравнения</div>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button style={squadStyles.chip}>Сортировать: риск ↓</button>
          <button style={squadStyles.chip}>Группа: все</button>
        </div>
      </div>
      <div style={squadStyles.grid}>
        {PR_ATHLETES.map(a => (
          <AthleteTile key={a.id} a={a}
            active={activeId === a.id}
            inCompare={compareIds.has(a.id)}
            onClick={() => setActiveId(a.id)}
            onToggleCompare={(e) => { e.stopPropagation(); toggleCompare(a.id); }}
          />
        ))}
      </div>

      {/* Heatmap */}
      <div style={{ marginTop: 24 }}>
        <div style={{ fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em', color: DK.ink }}>14 дней · тепловая карта</div>
        <div style={{ fontSize: 12, color: DK.ink3, marginTop: 2, marginBottom: 12 }}>Каждая клетка — день. Цвет — выполнение, размер — объём.</div>
        <div style={squadStyles.heatmap}>
          {PR_ATHLETES.slice(0, 8).map((a, i) => (
            <HeatmapRow key={a.id} a={a} />
          ))}
        </div>
      </div>
    </div>
  );
}

function AthleteTile({ a, active, inCompare, onClick, onToggleCompare }) {
  const pct = a.compliance;
  const color = pct >= 0.8 ? DK.success : pct >= 0.5 ? DK.warning : pct > 0 ? DK.danger : DK.bg4;
  return (
    <div
      role="button"
      tabIndex={0}
      onClick={onClick}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } }}
      style={{
        ...squadStyles.tile,
        borderColor: active ? DK.primary : inCompare ? DK.info : DK.line2,
        borderWidth: active || inCompare ? 1.5 : 1,
      }}>
      {/* Compliance bar at top */}
      <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: DK.bg4 }}>
        <div style={{ width: `${pct * 100}%`, height: '100%', background: color }} />
      </div>

      {/* compare toggle */}
      <button onClick={onToggleCompare} style={{
        ...squadStyles.compareToggle,
        background: inCompare ? DK.info : 'transparent',
        color: inCompare ? 'white' : DK.ink3,
        borderColor: inCompare ? DK.info : DK.line2,
      }}>⊞</button>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 6 }}>
        <PR_Avatar a={a} size={36} />
        <div style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
          <div style={{ fontSize: 13, fontWeight: 700, color: DK.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name.split(' ')[0]}</div>
          <div style={{ fontSize: 11, color: DK.ink3, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.goal} {a.target && '· ' + a.target}</div>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginTop: 14 }}>
        <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, letterSpacing: '-0.03em', color: a.atRisk ? DK.danger : DK.ink, lineHeight: 1 }}>
          {a.weekDone}<span style={{ color: DK.ink4, fontWeight: 500, fontSize: 18 }}>/{a.weekTotal}</span>
        </div>
        <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.06em', textAlign: 'right' }}>
          НЕДЕЛЯ<br/>
          <span style={{ color: DK.ink2, fontWeight: 600, fontFamily: '"Jost", sans-serif' }}>{a.spark.reduce((s, x) => s + x, 0)} км</span>
        </div>
      </div>

      <div style={{ marginTop: 10 }}>
        <PR_Sparkline data={a.spark} w={148} h={28} color={a.atRisk ? DK.danger : DK.primary2} bg />
      </div>

      <div style={{ marginTop: 10, display: 'flex', gap: 6, alignItems: 'center' }}>
        {a.atRisk && <span style={{ ...squadStyles.badge, background: DK.danger + '30', color: DK.danger }}>РИСК</span>}
        {a.freshUpload && <span style={{ ...squadStyles.badge, background: DK.success + '30', color: DK.success }}>↑ {a.lastActivity}</span>}
        {!a.atRisk && !a.freshUpload && <span style={{ fontSize: 10, color: DK.ink3 }}>{a.lastActivity}</span>}
        <div style={{ flex: 1 }} />
        {a.daysToRace != null && a.daysToRace <= 60 && (
          <span style={{ fontSize: 10, color: DK.primary2, fontWeight: 700, fontFamily: '"Jost", sans-serif' }}>{a.daysToRace}д</span>
        )}
      </div>
    </div>
  );
}

function HeatmapRow({ a }) {
  // generate 14 days of fake compliance data
  const days = Array.from({ length: 14 }, (_, i) => {
    const base = a.compliance;
    const rnd = ((a.id * 13 + i * 7) % 100) / 100;
    if (rnd > 0.85) return 0;
    return Math.min(1, base + (rnd - 0.5) * 0.4);
  });
  const vols = Array.from({ length: 14 }, (_, i) => ((a.id * 17 + i * 5) % 22) + 2);

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '6px 0' }}>
      <div style={{ width: 130, display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
        <PR_Avatar a={a} size={24} />
        <span style={{ fontSize: 12, color: DK.ink, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
          {a.name.split(' ')[0]}
        </span>
      </div>
      <div style={{ display: 'flex', gap: 3, flex: 1 }}>
        {days.map((v, i) => {
          const color = v === 0 ? DK.bg4 : v >= 0.8 ? DK.success : v >= 0.5 ? DK.warning : DK.danger;
          const size = v === 0 ? 8 : 8 + (vols[i] / 24) * 14;
          return (
            <div key={i} style={{
              flex: 1, height: 22, display: 'grid', placeItems: 'center',
              background: DK.bg2, borderRadius: 3,
            }}>
              <div style={{ width: size, height: size, background: color, borderRadius: 2, opacity: v === 0 ? 0.4 : 1 }} />
            </div>
          );
        })}
      </div>
      <div style={{ width: 50, textAlign: 'right', fontFamily: '"Jost", sans-serif', fontSize: 12, color: DK.ink2, fontWeight: 600 }}>
        {a.spark.reduce((s, x) => s + x, 0)}<span style={{ fontSize: 10, color: DK.ink4 }}> км</span>
      </div>
    </div>
  );
}

function CompareView({ compareIds, toggleCompare }) {
  const ids = Array.from(compareIds);
  const athletes = ids.map(id => PR_ATHLETES.find(a => a.id === id)).filter(Boolean);

  if (athletes.length === 0) {
    return (
      <div style={{ padding: 80, textAlign: 'center' }}>
        <div style={{ fontSize: 18, color: DK.ink2 }}>Выбери атлетов кнопкой ⊞ на тайлах</div>
      </div>
    );
  }

  const metrics = [
    { key: 'compliance', label: 'COMPLIANCE', fmt: a => `${Math.round(a.compliance * 100)}%`, color: a => a.compliance >= 0.8 ? DK.success : a.compliance >= 0.5 ? DK.warning : DK.danger },
    { key: 'volume', label: 'ОБЪЁМ · 7 ДН', fmt: a => `${a.spark.reduce((s, x) => s + x, 0)} км`, color: () => DK.ink },
    { key: 'pace', label: 'ТЕМП vs МЕС.', fmt: a => a.paceTrend || '—', color: a => a.paceTrend?.startsWith('+') ? DK.success : a.paceTrend?.startsWith('−') ? DK.danger : DK.ink },
    { key: 'race', label: 'ДО ГОНКИ', fmt: a => a.daysToRace ? `${a.daysToRace} дн` : '—', color: () => DK.ink },
    { key: 'target', label: 'ЦЕЛЬ', fmt: a => a.target || '—', color: () => DK.ink },
    { key: 'last', label: 'АКТИВНОСТЬ', fmt: a => a.lastActivity, color: a => a.atRisk ? DK.danger : DK.ink },
  ];

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 16 }}>
        <div>
          <div style={{ fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em', color: DK.ink }}>Сравнение</div>
          <div style={{ fontSize: 12, color: DK.ink3, marginTop: 2 }}>{athletes.length} из 4 атлетов</div>
        </div>
        <button style={squadStyles.chip}>Назначить общую тренировку</button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: `140px repeat(${athletes.length}, 1fr)`, gap: 12, marginBottom: 14 }}>
        <div />
        {athletes.map(a => (
          <div key={a.id} style={{ background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 12, padding: 14, position: 'relative' }}>
            <button onClick={() => toggleCompare(a.id)} style={{
              position: 'absolute', top: 8, right: 8, width: 22, height: 22,
              border: 'none', background: 'transparent', color: DK.ink3, cursor: 'pointer', fontSize: 14,
            }}>×</button>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <PR_Avatar a={a} size={36} />
              <div style={{ minWidth: 0 }}>
                <div style={{ fontWeight: 700, fontSize: 13, color: DK.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name}</div>
                <div style={{ fontSize: 11, color: DK.ink3 }}>{a.goal}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {metrics.map(m => (
        <div key={m.key} style={{ display: 'grid', gridTemplateColumns: `140px repeat(${athletes.length}, 1fr)`, gap: 12, padding: '12px 0', borderTop: `1px solid ${DK.line2}` }}>
          <div style={{ fontSize: 11, color: DK.ink3, fontWeight: 700, letterSpacing: '0.08em', display: 'flex', alignItems: 'center' }}>{m.label}</div>
          {athletes.map(a => (
            <div key={a.id} style={{ fontFamily: '"Jost", sans-serif', fontWeight: 700, fontSize: 22, color: m.color(a), letterSpacing: '-0.02em' }}>
              {m.fmt(a)}
            </div>
          ))}
        </div>
      ))}

      <div style={{ display: 'grid', gridTemplateColumns: `140px repeat(${athletes.length}, 1fr)`, gap: 12, padding: '12px 0', borderTop: `1px solid ${DK.line2}` }}>
        <div style={{ fontSize: 11, color: DK.ink3, fontWeight: 700, letterSpacing: '0.08em', display: 'flex', alignItems: 'center' }}>ДИНАМИКА 7 ДН</div>
        {athletes.map(a => (
          <div key={a.id}>
            <PR_Sparkline data={a.spark} w={200} h={48} color={DK.primary2} bg />
          </div>
        ))}
      </div>
    </div>
  );
}

function AthleteDetail({ a }) {
  if (!a) return null;
  return (
    <div style={{ padding: 20, height: '100%', display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', gap: 12, alignItems: 'center', paddingBottom: 16, borderBottom: `1px solid ${DK.line2}` }}>
        <PR_Avatar a={a} size={52} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontWeight: 700, fontSize: 16, color: DK.ink }}>{a.name}</div>
          <div style={{ fontSize: 11, color: DK.ink3 }}>{a.group}</div>
        </div>
      </div>

      {a.note && (
        <div style={{
          marginTop: 14, padding: 12, borderRadius: 10, fontSize: 12, lineHeight: 1.5,
          background: a.atRisk ? DK.danger + '15' : a.freshUpload ? DK.success + '15' : DK.bg2,
          color: a.atRisk ? DK.danger : a.freshUpload ? DK.success : DK.ink2,
          border: `1px solid ${a.atRisk ? DK.danger + '40' : a.freshUpload ? DK.success + '40' : DK.line2}`,
        }}>{a.note}</div>
      )}

      <div style={{ marginTop: 18, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
        <DetailMetric label="COMPLIANCE" value={`${Math.round(a.compliance * 100)}%`} color={a.compliance >= 0.8 ? DK.success : a.compliance >= 0.5 ? DK.warning : DK.danger} />
        <DetailMetric label="ОБЪЁМ" value={`${a.spark.reduce((s, x) => s + x, 0)}`} suffix="км" />
        <DetailMetric label="ТЕМП" value={a.paceTrend} color={a.paceTrend?.startsWith('+') ? DK.success : a.paceTrend?.startsWith('−') ? DK.danger : DK.ink} />
        <DetailMetric label={a.daysToRace ? 'ДО ГОНКИ' : 'ЦЕЛЬ'} value={a.daysToRace ? `${a.daysToRace}` : 'здоровье'} suffix={a.daysToRace ? 'дн.' : ''} />
      </div>

      <div style={{ marginTop: 18 }}>
        <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 8 }}>ПРОГНОЗ {a.goal.toUpperCase()}</div>
        {a.target ? (
          <div style={{ background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 12, padding: 14 }}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: DK.ink, letterSpacing: '-0.03em' }}>
                {a.target.replace(/(\d+):(\d+)/, (m, h, mm) => `${h}:${String(Math.min(59, parseInt(mm) + 2)).padStart(2, '0')}`)}
              </span>
              <span style={{ fontSize: 12, color: DK.ink3 }}>vs цель {a.target}</span>
            </div>
            <div style={{ marginTop: 10, height: 4, background: DK.bg4, borderRadius: 999, overflow: 'hidden' }}>
              <div style={{ width: `${Math.min(1, a.compliance + 0.1) * 100}%`, height: '100%', background: DK.primary }} />
            </div>
          </div>
        ) : <div style={{ color: DK.ink4, fontSize: 12 }}>Без целевого времени</div>}
      </div>

      <div style={{ marginTop: 18 }}>
        <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 8 }}>СЕГОДНЯ</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: 12, background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 12 }}>
          <span style={{ width: 4, height: 32, borderRadius: 4, background: PR_TYPE_COLOR(a.todayPlan?.type) }} />
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 700, fontSize: 14, color: DK.ink }}>{a.todayPlan?.label || '—'}</div>
            <div style={{ fontSize: 11, color: DK.ink3 }}>{a.todayPlan?.distance ? `${a.todayPlan.distance} км · ${a.todayPlan.pace} /км` : ''}</div>
          </div>
        </div>
      </div>

      <div style={{ marginTop: 'auto', display: 'flex', gap: 8 }}>
        <button style={squadStyles.detailAction}>✉ Чат</button>
        <button style={{ ...squadStyles.detailAction, background: DK.primary, color: 'white', borderColor: DK.primary }}>✎ План</button>
      </div>
    </div>
  );
}

function DetailMetric({ label, value, suffix, color }) {
  return (
    <div style={{ background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 10, padding: 12 }}>
      <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 4, marginTop: 4 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: color || DK.ink, letterSpacing: '-0.02em' }}>{value}</span>
        {suffix && <span style={{ fontSize: 11, color: DK.ink3 }}>{suffix}</span>}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Athlete Mobile (390×844) — performance OS, dark
// ─────────────────────────────────────────────────────────────────────
function SquadAthlete() {
  const [expanded, setExpanded] = useStateC(false);

  return (
    <div style={squadMobile.shell}>
      <div style={squadMobile.statusBar}>
        <span>9:41</span>
        <span>●●● ●</span>
      </div>

      <div style={squadMobile.header}>
        <div>
          <div style={{ fontSize: 11, color: DK.ink3, letterSpacing: '0.1em', fontWeight: 700 }}>ВТОРНИК · 12 МАЯ</div>
          <div style={{ fontSize: 26, fontWeight: 800, color: DK.ink, letterSpacing: '-0.02em', marginTop: 2 }}>Привет, Алексей</div>
        </div>
        <div style={{ width: 38, height: 38, borderRadius: '50%', background: '#FFD9C9', color: '#0F172A', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13 }}>АП</div>
      </div>

      {/* Status block */}
      <div style={squadMobile.scroll}>
        <div style={squadMobile.statusBlock}>
          <div style={squadMobile.statusRow}>
            <span style={{ fontSize: 11, color: DK.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>СТАТУС ФОРМЫ</span>
            <span style={{ fontSize: 11, color: DK.success, fontWeight: 700 }}>● ХОРОШО</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, marginTop: 14 }}>
            <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 72, fontWeight: 800, color: DK.ink, letterSpacing: '-0.04em', lineHeight: 1 }}>92</span>
            <span style={{ fontSize: 13, color: DK.ink3, paddingBottom: 12 }}>/100</span>
            <div style={{ flex: 1 }} />
            <span style={{ fontSize: 11, color: DK.success, fontWeight: 700, paddingBottom: 12 }}>+5 за нед.</span>
          </div>
          <div style={{ height: 6, background: 'rgba(255,255,255,0.08)', borderRadius: 999, overflow: 'hidden', marginTop: 8 }}>
            <div style={{ width: '92%', height: '100%', background: `linear-gradient(90deg, ${DK.warning}, ${DK.success})`, borderRadius: 999 }} />
          </div>
          <div style={squadMobile.miniStats}>
            <MiniMetric label="ОБЪЁМ" value="77" suffix="км/нед" />
            <MiniMetric label="ТЕМП" value="+5%" color={DK.success} />
            <MiniMetric label="ЧСС покоя" value="48" suffix="bpm" />
          </div>
        </div>

        {/* Today */}
        <div style={squadMobile.eyebrow}>СЕГОДНЯ · КЛЮЧЕВАЯ</div>
        <div style={squadMobile.todayCard} onClick={() => setExpanded(!expanded)}>
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
            <div style={{ width: 4, alignSelf: 'stretch', background: DK.warning, borderRadius: 4 }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 11, color: DK.warning, fontWeight: 700, letterSpacing: '0.08em' }}>ТЕМПОВАЯ</div>
              <div style={{ fontSize: 28, fontWeight: 800, color: DK.ink, letterSpacing: '-0.02em', marginTop: 2, lineHeight: 1.1 }}>4×1 км в темпе</div>
              <div style={{ display: 'flex', gap: 20, marginTop: 16 }}>
                <div>
                  <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 24, fontWeight: 700, color: DK.ink, lineHeight: 1 }}>8,0</div>
                  <div style={{ fontSize: 10, color: DK.ink3, letterSpacing: '0.05em' }}>КМ</div>
                </div>
                <div>
                  <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 24, fontWeight: 700, color: DK.primary2, lineHeight: 1 }}>4:30</div>
                  <div style={{ fontSize: 10, color: DK.ink3, letterSpacing: '0.05em' }}>ТЕМП</div>
                </div>
                <div>
                  <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 24, fontWeight: 700, color: DK.ink, lineHeight: 1 }}>42′</div>
                  <div style={{ fontSize: 10, color: DK.ink3, letterSpacing: '0.05em' }}>ВРЕМЯ</div>
                </div>
              </div>
              <div style={{ display: 'flex', height: 5, borderRadius: 999, overflow: 'hidden', gap: 1, marginTop: 14, background: DK.bg4 }}>
                {PR_TODAY.segments.map((s, i) => (
                  <div key={i} style={{ flex: s.km, background: PR_TYPE_COLOR(s.type) }} />
                ))}
              </div>
              {expanded && (
                <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {PR_TODAY.segments.map((s, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, color: DK.ink2 }}>
                      <span style={{ width: 6, height: 6, borderRadius: 999, background: PR_TYPE_COLOR(s.type) }} />
                      <span style={{ flex: 1 }}>{s.label}</span>
                      <span style={{ fontFamily: '"Jost", sans-serif', color: DK.ink3 }}>{s.km} км · {s.pace}</span>
                    </div>
                  ))}
                </div>
              )}
              <div style={{ marginTop: 14, fontSize: 12, color: DK.ink3 }}>
                Tap для {expanded ? 'свернуть ↑' : 'отрезков ↓'}
              </div>
            </div>
          </div>
        </div>

        <button style={squadMobile.startBtn}>СТАРТ ТРЕНИРОВКИ</button>

        {/* Week stripe */}
        <div style={squadMobile.eyebrow}>НЕДЕЛЯ · 5 ИЗ 5 КЛЮЧЕВЫХ</div>
        <div style={squadMobile.weekStrip}>
          {PR_WEEK.map((d, i) => {
            const isToday = d.status === 'today';
            const isDone = d.status === 'done';
            return (
              <div key={i} style={{
                flex: 1, padding: '10px 4px',
                background: isToday ? DK.primary : isDone ? DK.bg3 : 'transparent',
                border: `1px solid ${isToday ? DK.primary : DK.line2}`,
                borderRadius: 10, textAlign: 'center',
              }}>
                <div style={{ fontSize: 9, color: isToday ? 'white' : DK.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{d.day}</div>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 700, color: isToday ? 'white' : DK.ink, lineHeight: 1, marginTop: 2 }}>{d.date}</div>
                <div style={{ marginTop: 6, width: 8, height: 8, borderRadius: 999, background: PR_TYPE_COLOR(d.type), marginInline: 'auto', opacity: d.type === 'rest' ? 0.3 : 1 }} />
                <div style={{ fontSize: 9, color: isToday ? 'white' : DK.ink4, marginTop: 4, fontFamily: '"Jost", sans-serif' }}>
                  {d.km > 0 ? `${d.km}км` : '—'}
                </div>
              </div>
            );
          })}
        </div>

        {/* Goal block */}
        <div style={squadMobile.eyebrow}>ГЛАВНАЯ ЦЕЛЬ</div>
        <div style={squadMobile.goalCard}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div style={{ fontSize: 16, fontWeight: 700, color: DK.ink, letterSpacing: '-0.01em' }}>Москва · полумарафон</div>
              <div style={{ fontSize: 11, color: DK.ink3, marginTop: 2 }}>28 сентября</div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: DK.primary2, lineHeight: 1, letterSpacing: '-0.03em' }}>{PR_GOAL.daysLeft}</div>
              <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 600, letterSpacing: '0.05em' }}>ДНЕЙ</div>
            </div>
          </div>
          <div style={{ marginTop: 18, padding: 14, background: DK.bg4, borderRadius: 12, display: 'flex', justifyContent: 'space-between' }}>
            <div>
              <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>ЦЕЛЬ</div>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: DK.ink2, letterSpacing: '-0.02em' }}>{PR_GOAL.target}</div>
            </div>
            <div style={{ width: 1, background: DK.line2 }} />
            <div>
              <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>ПРОГНОЗ</div>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: DK.success, letterSpacing: '-0.02em' }}>{PR_GOAL.predicted}</div>
            </div>
            <div style={{ width: 1, background: DK.line2 }} />
            <div>
              <div style={{ fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>ТРЕНД</div>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 18, fontWeight: 700, color: DK.success, letterSpacing: '-0.02em' }}>{PR_GOAL.trend}</div>
            </div>
          </div>
        </div>
      </div>

      <div style={squadMobile.nav}>
        {[['◆', 'Сегодня', true], ['▦', 'План', false], ['◐', 'Тренер', false, 2], ['◍', 'Прогресс', false]].map(([ic, l, on, badge]) => (
          <div key={l} style={squadMobile.navItem}>
            <span style={{ fontSize: 16, color: on ? DK.primary2 : DK.ink3, position: 'relative' }}>
              {ic}
              {badge && <span style={squadMobile.badge}>{badge}</span>}
            </span>
            <span style={{ fontSize: 10, color: on ? DK.ink : DK.ink3, fontWeight: on ? 700 : 500, marginTop: 2 }}>{l}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function MiniMetric({ label, value, suffix, color }) {
  return (
    <div style={{ flex: 1 }}>
      <div style={{ fontSize: 9, color: DK.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 4 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 18, fontWeight: 700, color: color || DK.ink }}>{value}</span>
        {suffix && <span style={{ fontSize: 10, color: DK.ink3 }}>{suffix}</span>}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Styles
// ─────────────────────────────────────────────────────────────────────
const squadStyles = {
  shell: { width: '100%', height: '100%', background: DK.bg, color: DK.ink, fontFamily: 'Montserrat, sans-serif', display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  top: { padding: '14px 20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: `1px solid ${DK.line2}`, background: DK.bg2 },
  brand: { width: 28, height: 28, borderRadius: 8, background: DK.primary, display: 'grid', placeItems: 'center', color: 'white', fontWeight: 800, fontSize: 14 },
  viewToggle: { display: 'flex', background: DK.bg3, borderRadius: 8, padding: 3, gap: 2 },
  vt: { padding: '7px 14px', background: 'transparent', border: 'none', borderRadius: 6, color: DK.ink3, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  vtActive: { background: DK.bg4, color: DK.ink },
  live: { color: DK.success, fontSize: 11, fontWeight: 700, letterSpacing: '0.1em' },
  body: { flex: 1, display: 'grid', gridTemplateColumns: '180px 1fr 340px', overflow: 'hidden' },
  kpiRail: { padding: 16, borderRight: `1px solid ${DK.line2}`, display: 'flex', flexDirection: 'column', gap: 8, overflow: 'auto' },
  kpiCard: { background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 12, padding: 14 },
  kpiLbl: { fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.08em' },
  kpiVal: { fontFamily: '"Jost", sans-serif', fontSize: 24, fontWeight: 800, color: DK.ink, letterSpacing: '-0.02em', lineHeight: 1.1 },
  main: { padding: '20px 24px', overflow: 'auto' },
  chip: { padding: '8px 14px', background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 8, color: DK.ink2, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 },
  tile: { position: 'relative', background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 14, padding: 14, paddingTop: 16, cursor: 'pointer', textAlign: 'left', fontFamily: 'inherit', overflow: 'hidden', transition: 'all 0.15s' },
  compareToggle: { position: 'absolute', top: 8, right: 8, width: 22, height: 22, borderRadius: 6, border: `1px solid`, cursor: 'pointer', fontSize: 11, fontFamily: 'inherit' },
  badge: { fontSize: 9, fontWeight: 800, padding: '2px 6px', borderRadius: 4, letterSpacing: '0.05em' },
  heatmap: { background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 14, padding: 16 },
  detail: { background: DK.bg2, borderLeft: `1px solid ${DK.line2}`, overflow: 'auto' },
  detailAction: { flex: 1, padding: '12px 14px', background: 'transparent', color: DK.ink, border: `1px solid ${DK.line2}`, borderRadius: 10, fontSize: 13, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
};

const squadMobile = {
  shell: { width: '100%', height: '100%', background: DK.bg, color: DK.ink, fontFamily: 'Montserrat, sans-serif', display: 'flex', flexDirection: 'column', position: 'relative', overflow: 'hidden' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 13, fontWeight: 600 },
  header: { padding: '8px 20px 16px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' },
  scroll: { flex: 1, overflow: 'auto', padding: '0 20px', paddingBottom: 100 },
  statusBlock: { background: 'linear-gradient(135deg, #181F28 0%, #0E141B 100%)', border: `1px solid ${DK.line2}`, borderRadius: 20, padding: 20 },
  statusRow: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' },
  miniStats: { display: 'flex', gap: 12, marginTop: 16, paddingTop: 16, borderTop: `1px solid ${DK.line2}` },
  eyebrow: { fontSize: 10, color: DK.ink3, fontWeight: 700, letterSpacing: '0.12em', marginTop: 24, marginBottom: 10 },
  todayCard: { background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 18, padding: 18, cursor: 'pointer' },
  startBtn: { width: '100%', padding: 16, marginTop: 12, background: DK.primary, color: 'white', border: 'none', borderRadius: 14, fontWeight: 700, fontSize: 14, letterSpacing: '0.05em', cursor: 'pointer', fontFamily: 'inherit', boxShadow: `0 12px 32px ${DK.primary}40` },
  weekStrip: { display: 'flex', gap: 4 },
  goalCard: { background: DK.bg2, border: `1px solid ${DK.line2}`, borderRadius: 18, padding: 18 },
  nav: { position: 'absolute', bottom: 12, left: 12, right: 12, height: 64, background: 'rgba(20,24,31,0.92)', backdropFilter: 'blur(20px)', borderRadius: 20, border: `1px solid ${DK.line2}`, display: 'flex', justifyContent: 'space-around', alignItems: 'center' },
  navItem: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 },
  badge: { position: 'absolute', top: -4, right: -8, background: DK.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '0 4px', borderRadius: 999, minWidth: 14, textAlign: 'center', lineHeight: '14px' },
};

window.SquadCoach = SquadCoach;
window.SquadAthlete = SquadAthlete;

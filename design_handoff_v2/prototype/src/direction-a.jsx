/* Direction A — "Cockpit"
   Тренер-центричный экран: плотная таблица атлетов с inline-фильтрами,
   быстрым сравнением и панелью деталей. Атлет — мобильный экран с
   крупной типографикой и фокусом на «что сегодня».                         */

const { useState, useMemo } = React;
const T = PR_TOKENS;

// ─────────────────────────────────────────────────────────────────────
// Coach Desktop (1440×900)
// ─────────────────────────────────────────────────────────────────────
function CockpitCoach() {
  const [activeId, setActiveId] = useState(1);
  const [selected, setSelected] = useState(new Set());
  const [filterGroup, setFilterGroup] = useState('Все');
  const [sortBy, setSortBy] = useState('attention');
  const [showBulk, setShowBulk] = useState(false);

  const groups = ['Все', 'Марафон-осень', 'Полумарафон', 'Спринт-группа', 'База'];
  const filtered = useMemo(() => {
    let a = PR_ATHLETES;
    if (filterGroup !== 'Все') a = a.filter(x => x.group === filterGroup);
    if (sortBy === 'attention') {
      a = [...a].sort((x, y) => (y.atRisk ? 1 : 0) - (x.atRisk ? 1 : 0) || (x.compliance - y.compliance));
    } else if (sortBy === 'compliance') {
      a = [...a].sort((x, y) => x.compliance - y.compliance);
    } else if (sortBy === 'fresh') {
      a = [...a].sort((x, y) => (y.freshUpload ? 1 : 0) - (x.freshUpload ? 1 : 0));
    } else {
      a = [...a].sort((x, y) => x.activityDays - y.activityDays);
    }
    return a;
  }, [filterGroup, sortBy]);

  const active = PR_ATHLETES.find(a => a.id === activeId);
  const attentionCount = PR_ATHLETES.filter(a => a.atRisk).length;
  const freshCount = PR_ATHLETES.filter(a => a.freshUpload).length;

  const toggle = (id) => {
    const n = new Set(selected);
    if (n.has(id)) n.delete(id); else n.add(id);
    setSelected(n);
    setShowBulk(n.size > 0);
  };

  return (
    <div style={cockpitStyles.shell}>
      {/* Top bar */}
      <div style={cockpitStyles.topbar}>
        <div style={cockpitStyles.brand}>
          <span style={{ width: 28, height: 28, borderRadius: 8, background: T.primary, display: 'grid', placeItems: 'center', color: 'white', fontWeight: 800, fontSize: 14 }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 16, letterSpacing: '-0.02em' }}>planrun</span>
          <span style={{ color: T.ink3, fontSize: 13 }}>/ Тренерская</span>
        </div>
        <nav style={cockpitStyles.nav}>
          <a style={{ ...cockpitStyles.navItem, ...cockpitStyles.navItemActive }}>Команда</a>
          <a style={cockpitStyles.navItem}>Календарь</a>
          <a style={cockpitStyles.navItem}>Шаблоны</a>
          <a style={cockpitStyles.navItem}>Чат <span style={cockpitStyles.unreadDot}>7</span></a>
          <a style={cockpitStyles.navItem}>Аналитика</a>
        </nav>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <button style={cockpitStyles.iconBtn}>🔔</button>
          <div style={{ ...cockpitStyles.avatar, background: '#FFD9C9' }}>МК</div>
        </div>
      </div>

      <div style={cockpitStyles.body}>
        {/* Left sidebar: groups */}
        <aside style={cockpitStyles.sidebar}>
          <div style={cockpitStyles.sectionTitle}>СЕГОДНЯ · 12 МАЯ</div>
          <div style={cockpitStyles.heroStat}>
            <div style={cockpitStyles.heroNum}>{attentionCount}</div>
            <div style={cockpitStyles.heroLabel}>требуют внимания</div>
          </div>
          <div style={cockpitStyles.miniStats}>
            <MiniStat label="Новые загрузки" value={freshCount} dot={T.success} />
            <MiniStat label="Без ответа" value={3} dot={T.info} />
            <MiniStat label="Атлетов в плане" value={PR_ATHLETES.length} dot={T.ink4} />
          </div>

          <div style={{ ...cockpitStyles.sectionTitle, marginTop: 20 }}>ГРУППЫ</div>
          <div style={cockpitStyles.groupList}>
            {groups.map(g => (
              <button key={g}
                onClick={() => setFilterGroup(g)}
                style={{ ...cockpitStyles.groupItem, ...(filterGroup === g ? cockpitStyles.groupItemActive : {}) }}>
                <span>{g}</span>
                <span style={cockpitStyles.groupCount}>
                  {g === 'Все' ? PR_ATHLETES.length : PR_ATHLETES.filter(a => a.group === g).length}
                </span>
              </button>
            ))}
          </div>

          <div style={{ ...cockpitStyles.sectionTitle, marginTop: 20 }}>БЫСТРЫЕ ФИЛЬТРЫ</div>
          <div style={cockpitStyles.quickFilters}>
            <button style={cockpitStyles.qf}>⚠ С риском (2)</button>
            <button style={cockpitStyles.qf}>↑ Свежие загрузки (4)</button>
            <button style={cockpitStyles.qf}>★ Близка гонка (5)</button>
          </div>
        </aside>

        {/* Center: athlete table */}
        <section style={cockpitStyles.center}>
          <div style={cockpitStyles.centerHead}>
            <div>
              <div style={cockpitStyles.h1}>{filterGroup === 'Все' ? 'Все атлеты' : filterGroup}</div>
              <div style={cockpitStyles.h1sub}>{filtered.length} человек · обновлено только что</div>
            </div>
            <div style={cockpitStyles.headControls}>
              <div style={cockpitStyles.search}>
                <span style={{ color: T.ink4 }}>⌕</span>
                <input placeholder="Найти атлета..." style={cockpitStyles.searchInput} />
              </div>
              <SortMenu value={sortBy} onChange={setSortBy} />
            </div>
          </div>

          {showBulk && (
            <div style={cockpitStyles.bulkBar}>
              <span style={{ fontWeight: 600 }}>Выбрано: {selected.size}</span>
              <div style={{ flex: 1 }} />
              <button style={cockpitStyles.bulkBtn}>Назначить тренировку</button>
              <button style={cockpitStyles.bulkBtn}>Сообщение в группу</button>
              <button style={cockpitStyles.bulkBtn}>Применить шаблон…</button>
              <button onClick={() => { setSelected(new Set()); setShowBulk(false); }} style={{ ...cockpitStyles.bulkBtn, background: 'transparent', color: 'white' }}>×</button>
            </div>
          )}

          <div style={cockpitStyles.tableHead}>
            <div style={{ width: 28 }} />
            <div style={{ flex: '2 1 200px' }}>АТЛЕТ</div>
            <div style={{ width: 130 }}>ЦЕЛЬ</div>
            <div style={{ width: 80 }}>ДО ГОНКИ</div>
            <div style={{ width: 110 }}>НЕДЕЛЯ</div>
            <div style={{ width: 100 }}>7 ДН.</div>
            <div style={{ width: 110 }}>СЕГОДНЯ</div>
            <div style={{ width: 100 }}>АКТИВНОСТЬ</div>
            <div style={{ width: 80, textAlign: 'right' }}>ТЕМП</div>
          </div>
          <div style={cockpitStyles.tableBody}>
            {filtered.map(a => (
              <AthleteRow key={a.id}
                a={a}
                active={activeId === a.id}
                selected={selected.has(a.id)}
                onClick={() => setActiveId(a.id)}
                onToggle={(e) => { e.stopPropagation(); toggle(a.id); }}
              />
            ))}
          </div>
        </section>

        {/* Right: detail panel */}
        <aside style={cockpitStyles.detail}>
          <DetailPanel a={active} />
        </aside>
      </div>
    </div>
  );
}

function MiniStat({ label, value, dot }) {
  return (
    <div style={cockpitStyles.miniStat}>
      <span style={{ width: 6, height: 6, borderRadius: 999, background: dot, flexShrink: 0 }} />
      <span style={{ color: T.ink2, fontSize: 12, flex: 1 }}>{label}</span>
      <span style={{ fontWeight: 700, fontFamily: '"Jost", sans-serif', fontSize: 14 }}>{value}</span>
    </div>
  );
}

function SortMenu({ value, onChange }) {
  const labels = { attention: 'Сначала риск', compliance: 'По выполнению', fresh: 'Свежие загрузки', activity: 'По активности' };
  return (
    <div style={{ position: 'relative' }}>
      <select value={value} onChange={(e) => onChange(e.target.value)} style={cockpitStyles.sortSel}>
        {Object.entries(labels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
      </select>
    </div>
  );
}

function AthleteRow({ a, active, selected, onClick, onToggle }) {
  return (
    <div onClick={onClick} style={{
      ...cockpitStyles.row,
      background: active ? T.primary50 : 'transparent',
      borderLeft: active ? `3px solid ${T.primary}` : '3px solid transparent',
    }}>
      <div style={{ width: 28, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <input type="checkbox" checked={selected} onChange={onToggle} onClick={(e) => e.stopPropagation()} style={cockpitStyles.cb} />
      </div>
      <div style={{ flex: '2 1 200px', display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
        <PR_Avatar a={a} size={32} ring={a.atRisk ? T.danger : a.freshUpload ? T.success : null} />
        <div style={{ minWidth: 0 }}>
          <div style={{ fontWeight: 600, fontSize: 14, color: T.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name}</div>
          <div style={{ fontSize: 11, color: T.ink3, display: 'flex', gap: 6, alignItems: 'center' }}>
            <span>{a.group}</span>
            {a.unread > 0 && <span style={cockpitStyles.unread}>{a.unread}</span>}
          </div>
        </div>
      </div>
      <div style={{ width: 130, fontSize: 13 }}>
        <div style={{ color: T.ink, fontWeight: 500 }}>{a.goal}</div>
        {a.target && <div style={{ color: T.ink3, fontFamily: '"Jost", sans-serif', fontSize: 12 }}>цель {a.target}</div>}
      </div>
      <div style={{ width: 80, fontSize: 13, fontFamily: '"Jost", sans-serif' }}>
        {a.daysToRace != null ? <>
          <div style={{ fontWeight: 700, color: a.daysToRace <= 30 ? T.primary : T.ink }}>{a.daysToRace} дн.</div>
          <div style={{ fontSize: 11, color: T.ink3 }}>{a.raceDate}</div>
        </> : <span style={{ color: T.ink4 }}>—</span>}
      </div>
      <div style={{ width: 110, display: 'flex', alignItems: 'center', gap: 8 }}>
        <PR_ComplianceBar done={a.weekDone} total={a.weekTotal} w={50} />
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: T.ink, fontWeight: 600 }}>
          {a.weekDone}/{a.weekTotal}
        </span>
      </div>
      <div style={{ width: 100 }}>
        <PR_Sparkline data={a.spark} w={80} h={22} color={a.atRisk ? T.danger : T.primary} bg />
      </div>
      <div style={{ width: 110, fontSize: 13 }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <span style={{ width: 6, height: 6, borderRadius: 999, background: PR_TYPE_COLOR(a.todayPlan?.type) }} />
          <span style={{ color: T.ink }}>{a.todayPlan?.label || '—'}</span>
        </span>
      </div>
      <div style={{ width: 100, fontSize: 12 }}>
        <div style={{ color: a.atRisk ? T.danger : T.ink, fontWeight: a.atRisk ? 600 : 400 }}>{a.lastActivity}</div>
        {a.freshUpload && <div style={cockpitStyles.freshTag}>↑ новая</div>}
      </div>
      <div style={{ width: 80, textAlign: 'right', fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: 600,
                    color: a.paceTrend?.startsWith('+') ? T.success : a.paceTrend?.startsWith('−') ? T.danger : T.ink3 }}>
        {a.paceTrend || '—'}
      </div>
    </div>
  );
}

function DetailPanel({ a }) {
  if (!a) return null;
  return (
    <div style={cockpitStyles.detailInner}>
      <div style={cockpitStyles.detailHead}>
        <PR_Avatar a={a} size={48} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontWeight: 700, fontSize: 16, color: T.ink }}>{a.name}</div>
          <div style={{ fontSize: 12, color: T.ink3 }}>{a.group} · {a.goal} {a.target ? '· ' + a.target : ''}</div>
        </div>
        <button style={cockpitStyles.iconBtnSm}>⋯</button>
      </div>

      {a.note && (
        <div style={{
          ...cockpitStyles.callout,
          background: a.atRisk ? '#FEE2E2' : a.freshUpload ? '#DCFCE7' : T.surf3,
          color: a.atRisk ? '#991B1B' : a.freshUpload ? '#166534' : T.ink,
        }}>{a.note}</div>
      )}

      <div style={cockpitStyles.actionGrid}>
        <ActionBtn label="Сообщение" icon="✉" primary />
        <ActionBtn label="Править план" icon="✎" />
        <ActionBtn label="Открыть тренировку" icon="↗" />
        <ActionBtn label="Перенести день" icon="↔" />
      </div>

      <div style={cockpitStyles.detailSection}>
        <div style={cockpitStyles.detailLabel}>СЕГОДНЯ ПО ПЛАНУ</div>
        <div style={cockpitStyles.todayCard}>
          <div style={{ width: 4, alignSelf: 'stretch', background: PR_TYPE_COLOR(a.todayPlan?.type), borderRadius: 4 }} />
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 700, fontSize: 15 }}>{a.todayPlan?.label || 'Нет тренировки'}</div>
            <div style={{ fontSize: 12, color: T.ink3, marginTop: 2 }}>
              {a.todayPlan?.distance} км · {a.todayPlan?.pace} /км
            </div>
          </div>
        </div>
      </div>

      <div style={cockpitStyles.detailSection}>
        <div style={cockpitStyles.detailLabel}>НЕДЕЛЯ</div>
        <div style={cockpitStyles.weekDots}>
          {['ПН','ВТ','СР','ЧТ','ПТ','СБ','ВС'].map((d, i) => {
            const done = i < a.weekDone;
            const today = i === 1;
            return (
              <div key={d} style={cockpitStyles.weekDot}>
                <div style={{ fontSize: 10, color: T.ink3 }}>{d}</div>
                <div style={{
                  width: 26, height: 26, borderRadius: 8,
                  background: done ? T.success : today ? T.primary : T.surf3,
                  color: done || today ? 'white' : T.ink3,
                  display: 'grid', placeItems: 'center',
                  fontSize: 11, fontWeight: 700, fontFamily: '"Jost", sans-serif',
                  border: today ? `2px solid ${T.primary}` : 'none',
                }}>
                  {done ? '✓' : today ? '•' : ''}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      <div style={cockpitStyles.detailSection}>
        <div style={cockpitStyles.detailLabel}>ОБЪЁМ · 7 ДНЕЙ</div>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
          <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 700, color: T.ink, letterSpacing: '-0.02em' }}>
            {a.spark.reduce((s, x) => s + x, 0)}
          </span>
          <span style={{ color: T.ink3, fontSize: 13 }}>км</span>
          <span style={{ flex: 1 }} />
          <PR_Sparkline data={a.spark} w={120} h={32} color={T.primary} bg />
        </div>
      </div>

      <div style={cockpitStyles.detailSection}>
        <div style={cockpitStyles.detailLabel}>ПРОГНОЗ ВРЕМЕНИ · {a.goal}</div>
        {a.target ? (
          <div style={cockpitStyles.prediction}>
            <div>
              <div style={{ fontSize: 11, color: T.ink3 }}>Цель</div>
              <div style={cockpitStyles.predNum}>{a.target}</div>
            </div>
            <div style={{ fontSize: 18, color: T.ink4 }}>→</div>
            <div>
              <div style={{ fontSize: 11, color: T.ink3 }}>Прогноз</div>
              <div style={{ ...cockpitStyles.predNum, color: a.atRisk ? T.danger : T.success }}>
                {a.target.replace(/(\d+):(\d+)/, (m, h, mm) => `${h}:${String(Math.min(59, parseInt(mm) + 2)).padStart(2, '0')}`)}
              </div>
            </div>
          </div>
        ) : <div style={{ color: T.ink4, fontSize: 13 }}>Цель: здоровье</div>}
      </div>
    </div>
  );
}

function ActionBtn({ label, icon, primary }) {
  return (
    <button style={{ ...cockpitStyles.action, ...(primary ? cockpitStyles.actionPrimary : {}) }}>
      <span style={{ fontSize: 14 }}>{icon}</span>
      <span>{label}</span>
    </button>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Athlete Mobile (390×844)
// ─────────────────────────────────────────────────────────────────────
function CockpitAthlete() {
  const [tab, setTab] = useState('today');

  return (
    <div style={cockpitMobileStyles.shell}>
      {/* Status bar */}
      <div style={cockpitMobileStyles.statusBar}>
        <span>9:41</span>
        <span style={{ display: 'flex', gap: 4 }}>
          <span>●●●</span><span>●</span>
        </span>
      </div>

      <div style={cockpitMobileStyles.header}>
        <div>
          <div style={{ fontSize: 12, color: T.ink3, fontWeight: 500, textTransform: 'uppercase', letterSpacing: '0.08em' }}>Вторник</div>
          <div style={{ fontSize: 28, fontWeight: 800, color: T.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>12 мая</div>
        </div>
        <PR_Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={40} />
      </div>

      <div style={cockpitMobileStyles.tabs}>
        {[['today', 'Сегодня'], ['week', 'Неделя'], ['goal', 'Цель']].map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)}
            style={{ ...cockpitMobileStyles.tab, ...(tab === k ? cockpitMobileStyles.tabActive : {}) }}>
            {l}
          </button>
        ))}
      </div>

      <div style={cockpitMobileStyles.scroll}>
        {tab === 'today' && <TodayView />}
        {tab === 'week' && <WeekView />}
        {tab === 'goal' && <GoalView />}
      </div>

      <div style={cockpitMobileStyles.nav}>
        {[['🏠', 'Главная', true], ['📅', 'План', false], ['💬', 'Тренер', false, 2], ['📊', 'Прогресс', false]].map(([ic, l, on, badge]) => (
          <div key={l} style={cockpitMobileStyles.navItem}>
            <span style={{ fontSize: 18, opacity: on ? 1 : 0.5, position: 'relative' }}>
              {ic}
              {badge && <span style={cockpitMobileStyles.navBadge}>{badge}</span>}
            </span>
            <span style={{ fontSize: 10, color: on ? T.primary : T.ink3, fontWeight: on ? 700 : 500 }}>{l}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function TodayView() {
  const t = PR_TODAY;
  return (
    <>
      <div style={{ padding: '0 20px' }}>
        <div style={cockpitMobileStyles.eyebrow}>
          <span style={{ width: 8, height: 8, borderRadius: 999, background: PR_TYPE_COLOR(t.type) }} />
          {PR_TYPE_LABEL[t.type]} · ключевая
        </div>
        <h1 style={cockpitMobileStyles.heroTitle}>
          4×1 км<br />
          <span style={{ color: PR_TYPE_COLOR(t.type) }}>в темпе</span>
        </h1>

        <div style={cockpitMobileStyles.heroStats}>
          <Stat n="8,0" l="км" />
          <Stat n="4:30" l="темп /км" accent />
          <Stat n="42" l="мин ~" />
        </div>

        <div style={cockpitMobileStyles.bar}>
          {t.segments.map((s, i) => (
            <div key={i} style={{ flex: s.km, background: PR_TYPE_COLOR(s.type) }} />
          ))}
        </div>

        <div style={cockpitMobileStyles.segList}>
          {t.segments.slice(0, 5).map((s, i) => (
            <div key={i} style={cockpitMobileStyles.seg}>
              <span style={{ width: 6, height: 6, borderRadius: 999, background: PR_TYPE_COLOR(s.type), flexShrink: 0 }} />
              <span style={{ flex: 1, fontSize: 13 }}>{s.label}</span>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: T.ink2, fontWeight: 600 }}>{s.km} км</span>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: T.ink3, width: 56, textAlign: 'right' }}>{s.pace}</span>
            </div>
          ))}
          <div style={{ fontSize: 12, color: T.ink3, textAlign: 'center', padding: '4px 0' }}>+ ещё 4 отрезка</div>
        </div>

        <div style={cockpitMobileStyles.coachCard}>
          <div style={cockpitMobileStyles.coachAvatar}>AI</div>
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 700, fontSize: 13, color: T.ink, marginBottom: 2 }}>Михаил · совет тренера</div>
            <div style={{ fontSize: 13, color: T.ink2, lineHeight: 1.45 }}>
              Темповая — про контроль. Старт спокойно, держи 4:30 ровно. Восстановление — в медленном беге, не в шаге.
            </div>
          </div>
        </div>

        <button style={cockpitMobileStyles.cta}>Отметить выполненной →</button>
        <button style={cockpitMobileStyles.ctaGhost}>Перенести</button>
      </div>
    </>
  );
}

function WeekView() {
  return (
    <div style={{ padding: '0 20px' }}>
      <div style={cockpitMobileStyles.eyebrow}>Неделя 12 · 11–17 мая</div>
      <h1 style={{ ...cockpitMobileStyles.heroTitle, fontSize: 32 }}>
        60 <span style={{ color: T.ink3, fontWeight: 500 }}>км</span><br />
        <span style={{ fontSize: 14, color: T.ink3, fontWeight: 500, letterSpacing: 0 }}>
          Объём недели · 5 ключевых тренировок
        </span>
      </h1>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 16 }}>
        {PR_WEEK.map((d, i) => (
          <div key={i} style={{
            ...cockpitMobileStyles.weekRow,
            background: d.status === 'today' ? T.primary50 : T.surf,
            border: d.status === 'today' ? `1.5px solid ${T.primary}` : `1px solid ${T.line}`,
          }}>
            <div style={{ width: 36, textAlign: 'center' }}>
              <div style={{ fontSize: 10, color: T.ink3, fontWeight: 600, letterSpacing: '0.08em' }}>{d.day}</div>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 20, fontWeight: 700, color: d.status === 'today' ? T.primary : T.ink, lineHeight: 1 }}>{d.date}</div>
            </div>
            <div style={{ width: 3, alignSelf: 'stretch', background: PR_TYPE_COLOR(d.type), borderRadius: 4 }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, fontSize: 14, color: T.ink }}>{d.label}</div>
              {d.km > 0 && <div style={{ fontSize: 12, color: T.ink3, fontFamily: '"Jost", sans-serif' }}>{d.km} км</div>}
            </div>
            {d.status === 'done' && <span style={{ color: T.success, fontSize: 16 }}>✓</span>}
            {d.status === 'today' && <span style={cockpitMobileStyles.todayPill}>СЕГОДНЯ</span>}
          </div>
        ))}
      </div>
    </div>
  );
}

function GoalView() {
  const g = PR_GOAL;
  return (
    <div style={{ padding: '0 20px' }}>
      <div style={cockpitMobileStyles.eyebrow}>ГЛАВНАЯ ЦЕЛЬ</div>
      <h1 style={{ ...cockpitMobileStyles.heroTitle, fontSize: 28 }}>{g.title}</h1>
      <div style={{ ...cockpitMobileStyles.countdownCard }}>
        <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 64, fontWeight: 800, color: T.primary, lineHeight: 1, letterSpacing: '-0.04em' }}>{g.daysLeft}</div>
        <div style={{ fontSize: 13, color: T.ink2, fontWeight: 500 }}>дней до {g.date}</div>
        <div style={{ marginTop: 16, height: 6, background: T.line, borderRadius: 999, overflow: 'hidden' }}>
          <div style={{ width: `${g.progress * 100}%`, height: '100%', background: T.primary, borderRadius: 999 }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 8, fontSize: 11, color: T.ink3 }}>
          <span>Неделя {g.weeksDone}/{g.weeksTotal}</span>
          <span>{Math.round(g.progress * 100)}% плана</span>
        </div>
      </div>

      <div style={cockpitMobileStyles.predGrid}>
        <div style={cockpitMobileStyles.predBlock}>
          <div style={{ fontSize: 11, color: T.ink3, fontWeight: 600, letterSpacing: '0.06em' }}>ЦЕЛЬ</div>
          <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 700, color: T.ink, letterSpacing: '-0.02em' }}>{g.target}</div>
        </div>
        <div style={cockpitMobileStyles.predBlock}>
          <div style={{ fontSize: 11, color: T.ink3, fontWeight: 600, letterSpacing: '0.06em' }}>ПРОГНОЗ</div>
          <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 700, color: T.success, letterSpacing: '-0.02em' }}>{g.predicted}</div>
          <div style={{ fontSize: 11, color: T.success, fontWeight: 600, marginTop: 2 }}>↓ {g.trend}</div>
        </div>
      </div>
    </div>
  );
}

function Stat({ n, l, accent }) {
  return (
    <div style={{ flex: 1, textAlign: 'left' }}>
      <div style={{
        fontFamily: '"Jost", sans-serif', fontWeight: 700, fontSize: 32,
        color: accent ? T.primary : T.ink, letterSpacing: '-0.03em', lineHeight: 1,
      }}>{n}</div>
      <div style={{ fontSize: 11, color: T.ink3, marginTop: 4, fontWeight: 500, letterSpacing: '0.04em' }}>{l}</div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Styles
// ─────────────────────────────────────────────────────────────────────
const cockpitStyles = {
  shell: { width: '100%', height: '100%', background: T.surf2, display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: T.ink },
  topbar: { height: 56, background: 'white', borderBottom: `1px solid ${T.line}`, display: 'flex', alignItems: 'center', padding: '0 20px', gap: 24 },
  brand: { display: 'flex', alignItems: 'center', gap: 8 },
  nav: { display: 'flex', gap: 4, flex: 1, marginLeft: 24 },
  navItem: { padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: 500, color: T.ink2, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 },
  navItemActive: { background: T.surf3, color: T.ink, fontWeight: 600 },
  unreadDot: { background: T.primary, color: 'white', borderRadius: 999, fontSize: 10, padding: '1px 6px', fontWeight: 700, fontFamily: '"Jost", sans-serif' },
  iconBtn: { width: 36, height: 36, borderRadius: 8, border: `1px solid ${T.line}`, background: 'white', cursor: 'pointer', fontSize: 14 },
  iconBtnSm: { width: 28, height: 28, borderRadius: 6, border: `1px solid ${T.line}`, background: 'white', cursor: 'pointer', fontSize: 14 },
  avatar: { width: 36, height: 36, borderRadius: '50%', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13 },
  body: { flex: 1, display: 'grid', gridTemplateColumns: '240px 1fr 380px', overflow: 'hidden' },
  sidebar: { background: 'white', borderRight: `1px solid ${T.line}`, padding: 16, overflow: 'auto' },
  sectionTitle: { fontSize: 10, color: T.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 10 },
  heroStat: { padding: '12px 0' },
  heroNum: { fontFamily: '"Jost", sans-serif', fontSize: 56, fontWeight: 800, color: T.primary, letterSpacing: '-0.04em', lineHeight: 1 },
  heroLabel: { fontSize: 12, color: T.ink2, marginTop: 4 },
  miniStats: { display: 'flex', flexDirection: 'column', gap: 6, marginTop: 8 },
  miniStat: { display: 'flex', alignItems: 'center', gap: 8, padding: '6px 8px', borderRadius: 6 },
  groupList: { display: 'flex', flexDirection: 'column', gap: 2 },
  groupItem: { padding: '8px 10px', borderRadius: 6, fontSize: 13, color: T.ink2, background: 'transparent', border: 'none', cursor: 'pointer', display: 'flex', justifyContent: 'space-between', textAlign: 'left' },
  groupItemActive: { background: T.surf3, color: T.ink, fontWeight: 600 },
  groupCount: { fontFamily: '"Jost", sans-serif', fontSize: 12, color: T.ink3, fontWeight: 600 },
  quickFilters: { display: 'flex', flexDirection: 'column', gap: 6 },
  qf: { padding: '8px 10px', borderRadius: 6, fontSize: 12, color: T.ink2, background: T.surf3, border: 'none', cursor: 'pointer', textAlign: 'left' },
  center: { background: T.surf2, display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  centerHead: { padding: '16px 24px 12px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' },
  h1: { fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em', color: T.ink },
  h1sub: { fontSize: 12, color: T.ink3, marginTop: 2 },
  headControls: { display: 'flex', gap: 8 },
  search: { display: 'flex', alignItems: 'center', gap: 6, background: 'white', border: `1px solid ${T.line}`, borderRadius: 8, padding: '6px 10px', width: 220 },
  searchInput: { border: 'none', outline: 'none', fontSize: 13, flex: 1, background: 'transparent', fontFamily: 'inherit' },
  sortSel: { padding: '6px 10px', borderRadius: 8, fontSize: 13, border: `1px solid ${T.line}`, background: 'white', cursor: 'pointer', fontFamily: 'inherit' },
  bulkBar: { margin: '0 24px 12px', background: T.ink, color: 'white', borderRadius: 10, padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 8 },
  bulkBtn: { background: 'rgba(255,255,255,0.15)', color: 'white', border: 'none', padding: '6px 12px', borderRadius: 6, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  tableHead: { display: 'flex', padding: '8px 24px', gap: 12, fontSize: 10, color: T.ink3, fontWeight: 700, letterSpacing: '0.08em', borderBottom: `1px solid ${T.line}` },
  tableBody: { flex: 1, overflow: 'auto', background: 'white' },
  row: { display: 'flex', padding: '12px 21px 12px 21px', gap: 12, alignItems: 'center', borderBottom: `1px solid ${T.line}`, cursor: 'pointer', transition: 'background 0.12s' },
  cb: { width: 14, height: 14, accentColor: T.primary, cursor: 'pointer' },
  unread: { background: T.primary, color: 'white', borderRadius: 999, fontSize: 10, padding: '1px 6px', fontWeight: 700, fontFamily: '"Jost", sans-serif' },
  freshTag: { display: 'inline-block', background: '#DCFCE7', color: '#166534', fontSize: 10, fontWeight: 700, padding: '1px 6px', borderRadius: 4, marginTop: 2 },
  detail: { background: 'white', borderLeft: `1px solid ${T.line}`, overflow: 'auto' },
  detailInner: { padding: 20 },
  detailHead: { display: 'flex', gap: 12, alignItems: 'center', paddingBottom: 16, borderBottom: `1px solid ${T.line}` },
  callout: { padding: 10, borderRadius: 8, fontSize: 12, marginTop: 14, lineHeight: 1.4, fontWeight: 500 },
  actionGrid: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6, marginTop: 14 },
  action: { padding: '10px 12px', borderRadius: 8, fontSize: 12, fontWeight: 600, background: T.surf3, color: T.ink, border: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' },
  actionPrimary: { background: T.primary, color: 'white' },
  detailSection: { marginTop: 18 },
  detailLabel: { fontSize: 10, color: T.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 8 },
  todayCard: { display: 'flex', gap: 10, padding: 12, background: T.surf2, borderRadius: 10, alignItems: 'stretch' },
  weekDots: { display: 'flex', justifyContent: 'space-between', gap: 4 },
  weekDot: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4, flex: 1 },
  prediction: { display: 'flex', alignItems: 'center', gap: 10, padding: 12, background: T.surf2, borderRadius: 10 },
  predNum: { fontFamily: '"Jost", sans-serif', fontSize: 20, fontWeight: 700, color: T.ink, letterSpacing: '-0.02em' },
};

const cockpitMobileStyles = {
  shell: { width: '100%', height: '100%', background: T.surf, display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: T.ink, position: 'relative' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 13, fontWeight: 600, color: T.ink },
  header: { padding: '8px 20px 16px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' },
  tabs: { display: 'flex', padding: '0 20px', gap: 4, borderBottom: `1px solid ${T.line}` },
  tab: { padding: '12px 6px', fontSize: 14, fontWeight: 600, color: T.ink3, background: 'transparent', border: 'none', cursor: 'pointer', marginRight: 12, borderBottom: '2px solid transparent', fontFamily: 'inherit' },
  tabActive: { color: T.ink, borderBottom: `2px solid ${T.primary}` },
  scroll: { flex: 1, overflow: 'auto', paddingBottom: 90, paddingTop: 16 },
  eyebrow: { fontSize: 11, color: T.ink3, fontWeight: 600, letterSpacing: '0.08em', textTransform: 'uppercase', display: 'inline-flex', alignItems: 'center', gap: 6 },
  heroTitle: { fontSize: 40, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.05, color: T.ink, marginTop: 6 },
  heroStats: { display: 'flex', gap: 16, marginTop: 20, paddingBottom: 18, borderBottom: `1px solid ${T.line}` },
  bar: { display: 'flex', height: 8, borderRadius: 999, overflow: 'hidden', marginTop: 16, gap: 1 },
  segList: { display: 'flex', flexDirection: 'column', gap: 8, marginTop: 14 },
  seg: { display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0' },
  coachCard: { display: 'flex', gap: 10, padding: 14, marginTop: 20, background: T.surf3, borderRadius: 12 },
  coachAvatar: { width: 32, height: 32, borderRadius: '50%', background: T.primary, color: 'white', display: 'grid', placeItems: 'center', fontSize: 11, fontWeight: 700, flexShrink: 0 },
  cta: { marginTop: 20, width: '100%', padding: '16px', borderRadius: 14, background: T.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 15, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 8px 24px rgba(252,76,2,0.3)' },
  ctaGhost: { marginTop: 8, width: '100%', padding: '14px', borderRadius: 14, background: 'transparent', color: T.ink2, border: `1px solid ${T.line}`, fontWeight: 600, fontSize: 14, cursor: 'pointer', fontFamily: 'inherit' },
  weekRow: { display: 'flex', alignItems: 'center', gap: 12, padding: 12, borderRadius: 12 },
  todayPill: { background: T.primary, color: 'white', fontSize: 10, fontWeight: 800, padding: '4px 8px', borderRadius: 6, letterSpacing: '0.06em' },
  countdownCard: { padding: 24, background: T.surf3, borderRadius: 16, marginTop: 12 },
  predGrid: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 14 },
  predBlock: { padding: 16, background: T.surf3, borderRadius: 12 },
  nav: { position: 'absolute', bottom: 12, left: 12, right: 12, height: 64, background: 'rgba(255,255,255,0.92)', backdropFilter: 'blur(20px)', borderRadius: 20, border: `1px solid ${T.line}`, display: 'flex', justifyContent: 'space-around', alignItems: 'center', boxShadow: '0 10px 30px rgba(0,0,0,0.08)' },
  navItem: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 },
  navBadge: { position: 'absolute', top: -2, right: -8, background: T.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '0 4px', borderRadius: 999, minWidth: 14, textAlign: 'center', lineHeight: '14px' },
};

window.CockpitCoach = CockpitCoach;
window.CockpitAthlete = CockpitAthlete;

// ============================================================
// Направление B — «ТЕЛЕМЕТРИЯ». HUD / mission control: стекло,
// градиентные кольца, живые данные. Unbounded + Jost.
// ============================================================

const B_T = {
  dark: {
    bg: 'radial-gradient(120% 90% at 80% -10%, rgba(255,90,31,0.12) 0%, transparent 50%), radial-gradient(100% 80% at 0% 110%, rgba(255,45,120,0.08) 0%, transparent 55%), linear-gradient(180deg, #0B0F16 0%, #080B10 100%)',
    bgFlat: '#0B0F16',
    card: 'rgba(255,255,255,0.045)', card2: 'rgba(255,255,255,0.08)', cardBorder: 'rgba(255,255,255,0.09)',
    ink: '#EDF1F7', sub: '#828B99', line: 'rgba(255,255,255,0.08)',
    accent: '#FF5A1F', accent2: '#FF2D78', good: '#3DDC97', bad: '#FF5470', onAccent: '#FFFFFF',
    track: 'rgba(255,255,255,0.08)', glow: '0 0 24px rgba(255,90,31,0.35)',
  },
  light: {
    bg: 'radial-gradient(120% 90% at 80% -10%, rgba(255,90,31,0.10) 0%, transparent 50%), radial-gradient(100% 80% at 0% 110%, rgba(255,45,120,0.06) 0%, transparent 55%), linear-gradient(180deg, #F2F4F9 0%, #E9EDF4 100%)',
    bgFlat: '#F2F4F9',
    card: 'rgba(255,255,255,0.75)', card2: 'rgba(255,255,255,0.95)', cardBorder: 'rgba(14,20,32,0.08)',
    ink: '#0E1420', sub: '#5D6573', line: 'rgba(14,20,32,0.08)',
    accent: '#F4480A', accent2: '#E5226B', good: '#0FA968', bad: '#E0254E', onAccent: '#FFFFFF',
    track: 'rgba(14,20,32,0.08)', glow: '0 8px 24px rgba(244,72,10,0.25)',
  },
};
const B_DISP = "'Unbounded', 'Jost', sans-serif";
const B_BODY = "'Jost', sans-serif";
const B_GRAD = (T) => `linear-gradient(90deg, ${T.accent} 0%, ${T.accent2} 100%)`;

function BStyle({ T }) {
  return (
    <style>{`
      @keyframes r3b-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(255,90,31,0.5); } 70% { box-shadow: 0 0 0 9px rgba(255,90,31,0); } }
      @keyframes r3b-livebar { 0% { transform: scaleY(0.3); } 50% { transform: scaleY(1); } 100% { transform: scaleY(0.3); } }
      .r3b-card { backdrop-filter: blur(14px); transition: transform .18s ease, border-color .18s ease; }
      .r3b-hover:hover { transform: translateY(-2px); border-color: ${T.accent} !important; }
      .r3b-btn { transition: filter .15s ease, transform .15s ease; cursor: pointer; }
      .r3b-btn:hover { filter: brightness(1.12); transform: translateY(-1px); }
      .r3b-cell { transition: transform .12s ease; cursor: pointer; }
      .r3b-cell:hover { transform: scale(1.12); }
    `}</style>
  );
}

function BDefs() {
  return (
    <svg width="0" height="0" style={{ position: 'absolute' }}>
      <defs>
        <linearGradient id="r3b-grad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="#FF5A1F"></stop>
          <stop offset="100%" stopColor="#FF2D78"></stop>
        </linearGradient>
      </defs>
    </svg>
  );
}

const bLabel = (T, extra) => ({
  fontFamily: B_BODY, fontSize: 11, fontWeight: 600, letterSpacing: '0.14em',
  textTransform: 'uppercase', color: T.sub, ...extra,
});
const bCard = (T, extra) => ({
  background: T.card, border: `1px solid ${T.cardBorder}`, borderRadius: 20, ...extra,
});

function BLogo({ T, size = 15 }) {
  return (
    <div style={{ fontFamily: B_DISP, fontSize: size, fontWeight: 700, color: T.ink }}>
      plan<span style={{ background: B_GRAD(T), WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>run</span>
    </div>
  );
}

function BLive({ T, label = 'live' }) {
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
      <div style={{ width: 7, height: 7, borderRadius: 99, background: T.accent, animation: 'r3b-pulse 1.8s infinite' }}></div>
      <span style={bLabel(T, { fontSize: 9, color: T.accent })}>{label}</span>
    </div>
  );
}

// Зоны темпа — сегментированная шкала
function BZoneBar({ T, h = 10 }) {
  const zones = [
    { w: 18, c: T.sub, op: 0.35 }, { w: 22, c: T.good, op: 0.8 },
    { w: 26, c: T.accent, op: 1 }, { w: 20, c: T.accent2, op: 0.85 }, { w: 14, c: T.bad, op: 0.6 },
  ];
  return (
    <div>
      <div style={{ display: 'flex', gap: 3, height: h }}>
        {zones.map((z, i) => (
          <div key={i} style={{ width: `${z.w}%`, borderRadius: 99, background: z.c, opacity: z.op, position: 'relative' }}>
            {i === 2 && <div style={{ position: 'absolute', top: -5, left: '55%', width: 2.5, height: h + 10, background: T.ink, borderRadius: 2 }}></div>}
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 7 }}>
        <span style={bLabel(T, { fontSize: 9 })}>Z1</span>
        <span style={bLabel(T, { fontSize: 9, color: T.ink })}>цель · Z3 · {R3.today.pace}/км</span>
        <span style={bLabel(T, { fontSize: 9 })}>Z5</span>
      </div>
    </div>
  );
}

function BMetricTile({ T, label, value, unit, spark, color, delta }) {
  return (
    <div className="r3b-card r3b-hover" style={bCard(T, { padding: '13px 15px', display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 })}>
      <div style={bLabel(T, { fontSize: 9 })}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 8 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: T.ink, lineHeight: 1 }}>
          {value}<span style={{ fontSize: 10, fontFamily: B_BODY, fontWeight: 600, color: T.sub, marginLeft: 3 }}>{unit}</span>
        </div>
        {delta && <div style={{ fontFamily: B_BODY, fontSize: 11, fontWeight: 700, color: T.good }}>{delta}</div>}
      </div>
      {spark && <R3Spark data={spark} w={110} h={20} color={color || T.accent} sw={2} />}
    </div>
  );
}

// Интервал-бар сегментов тренировки (как TodayHeroV3)
function BIntervalBar({ T, h = 12 }) {
  const segs = [
    { w: 20, c: T.sub, op: 0.4, l: '2 км' },
    { w: 60, c: 'url(#r3b-grad-flat)', op: 1, l: '6 км · 4:30–4:40', grad: true },
    { w: 20, c: T.sub, op: 0.4, l: '2 км' },
  ];
  return (
    <div>
      <div style={{ display: 'flex', gap: 3, height: h }}>
        {segs.map((s, i) => (
          <div key={i} style={{ width: `${s.w}%`, borderRadius: 99, background: s.grad ? B_GRAD(T) : s.c, opacity: s.op, boxShadow: s.grad ? T.glow : 'none' }}></div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 3, marginTop: 6 }}>
        {segs.map((s, i) => (
          <div key={i} style={{ width: `${s.w}%`, ...bLabel(T, { fontSize: 8.5, color: s.grad ? T.accent : T.sub, textAlign: i === 0 ? 'left' : i === 2 ? 'right' : 'center' }) }}>{s.l}</div>
        ))}
      </div>
    </div>
  );
}

// Чип режима тренировок (training_mode: ai / coach / self)
function BModeChip({ T, mode = 'AI-тренер' }) {
  return (
    <div className="r3b-btn" onClick={() => prNav('mode')} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, border: `1px solid ${T.cardBorder}`, background: T.card, borderRadius: 99, padding: '5px 11px 5px 6px', cursor: 'pointer' }}>
      <div style={{ width: 20, height: 20, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 8, fontWeight: 800, color: '#fff', fontFamily: B_DISP }}>AI</div>
      <span style={{ fontSize: 11, fontWeight: 700, color: T.ink }}>{mode}</span>
      <svg width="9" height="9" viewBox="0 0 10 10" fill="none" stroke={T.sub} strokeWidth="2" strokeLinecap="round"><path d="M2 3.5l3 3 3-3"></path></svg>
    </div>
  );
}

function BWeekDots({ T }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6 }}>
      {R3.week.map((w, i) => {
        const today = w.state === 'today', done = w.state === 'done';
        return (
          <div key={i} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 5 }}>
            <div style={{
              width: 30, height: 30, borderRadius: 99, display: 'flex', alignItems: 'center', justifyContent: 'center',
              background: today ? B_GRAD(T) : done ? T.card2 : 'transparent',
              border: done ? `1.5px solid ${T.good}` : today ? 'none' : `1.5px dashed ${w.state === 'rest' ? T.line : T.sub}`,
              boxShadow: today ? T.glow : 'none',
            }}>
              {done ? R3Icon.check(T.good, 13) : (
                <span style={{ fontFamily: B_BODY, fontSize: 11, fontWeight: 700, color: today ? '#fff' : T.sub }}>
                  {w.km > 0 ? w.km : '·'}
                </span>
              )}
            </div>
            <span style={bLabel(T, { fontSize: 8.5, color: today ? T.accent : T.sub })}>{w.d}</span>
          </div>
        );
      })}
    </div>
  );
}

function BNav({ T, active = 'home' }) {
  // Навигация: 5 вкладок, пятая — страница пользователя (с шестерёнкой настроек внутри)
  const items = [
    ['home', 'Главная', 'home'],
    ['cal', 'План', 'cal'],
    ['chat', 'Чат', 'chat'],
    ['stats', 'Прогресс', 'stats'],
    ['user', 'Профиль', 'profile'],
  ];
  return (
    <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 26, margin: '0 16px 14px', padding: '8px 10px' }), display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', flexShrink: 0, background: T.card2 }}>
      {items.map(([ic, label, id], i) => {
        const act = id === active;
        return (
          <div key={i} onClick={() => prNav(id)} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 3, padding: '6px 0', borderRadius: 18, background: act ? B_GRAD(T) : 'transparent', cursor: 'pointer' }}>
            {R3Icon[ic](act ? '#fff' : T.sub, 19)}
            <span style={{ fontFamily: B_BODY, fontSize: 9, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: act ? '#fff' : T.sub }}>{label}</span>
          </div>
        );
      })}
    </div>
  );
}

// ---------- Бегун · мобайл ----------
function BRunnerMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden', position: 'relative' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '16px 20px 8px', flexShrink: 0 }}>
        <div style={{ width: 38, height: 38, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 12, fontWeight: 700, color: '#fff' }}>И</div>
        <div style={{ flex: 1 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <div style={{ fontSize: 14.5, fontWeight: 600, color: T.ink }}>Доброе утро, Иван</div>
            <BModeChip T={T} />
          </div>
          <div style={bLabel(T, { fontSize: 9 })}>{R3.today.dow}, {R3.today.date} · {R3.today.week}</div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
          {R3Icon.flame(T.accent, 15)}
          <span style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: T.ink }}>{R3.user.streak}</span>
        </div>
        <div style={{ position: 'relative', marginLeft: 6, cursor: 'pointer' }} onClick={() => prNav('notif')}>
          {R3Icon.bell(T.ink, 19)}
          <div style={{ position: 'absolute', top: -1, right: -2, width: 7, height: 7, borderRadius: 99, background: T.accent }}></div>
        </div>
      </div>

      {/* готовность */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 18, padding: '8px 20px', flexShrink: 0, cursor: 'pointer' }} onClick={() => prNav('stats')}>
        <R3Ring pct={R3.metrics.form / 100} size={128} stroke={11} color="url(#r3b-grad)" track={T.track}>
          <div style={{ fontFamily: B_DISP, fontSize: 34, fontWeight: 700, color: T.ink, lineHeight: 1 }}>{R3.metrics.form}</div>
          <div style={bLabel(T, { fontSize: 8 })}>готовность</div>
        </R3Ring>
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 8 }}>
          <BLive T={T} label="форма растёт" />
          <div style={{ fontSize: 13.5, fontWeight: 500, color: T.ink, lineHeight: 1.45 }}>
            Восстановление полное. Сегодня можно работать на пороге — окно продуктивности до 11:00.
          </div>
        </div>
      </div>

      {/* тренировка дня */}
      <div className="r3b-card" style={bCard(T, { margin: '8px 16px 0', padding: '16px 18px', flexShrink: 0 })}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <div style={bLabel(T, { fontSize: 9 })}>Тренировка дня · <span style={{ color: T.accent }}>ключевая</span></div>
          <div style={bLabel(T, { fontSize: 9, color: T.accent })}>~48 мин · Z3</div>
        </div>
        <div style={{ fontFamily: B_DISP, fontSize: 23, fontWeight: 700, color: T.ink, marginBottom: 14 }}>
          Темповый · 10 км
        </div>
        <BIntervalBar T={T} />
        <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
          <div className="r3b-btn" onClick={() => prNav('workout')} style={{ flex: 1, background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '12px 16px', fontFamily: B_BODY, fontSize: 14, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
            Начать →
          </div>
          <div className="r3b-btn" title="Перенести" style={{ width: 44, border: `1px solid ${T.cardBorder}`, color: T.ink, borderRadius: 14, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            {R3Icon.cal(T.sub, 17)}
          </div>
          <div className="r3b-btn" title="Отметить выполненной" style={{ width: 44, border: `1px solid ${T.cardBorder}`, borderRadius: 14, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            {R3Icon.check(T.good, 16)}
          </div>
        </div>
      </div>

      {/* метрики */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, padding: '10px 16px 0', flex: 1, minHeight: 0, alignContent: 'start' }}>
        <BMetricTile T={T} label="VDOT" value={R3.metrics.vdot} delta={R3.metrics.vdotDelta} spark={R3.metrics.vdotSpark} />
        <BMetricTile T={T} label="Объём недели" value={R3.metrics.weekKm} unit={`/ ${R3.metrics.weekPlanKm} км`} spark={R3.metrics.loadSpark} color={T.accent2} />
        <BMetricTile T={T} label="Пульс покоя" value={R3.metrics.rhr} unit="уд/мин" delta="−2" spark={[52, 51, 51, 50, 49, 49, 48, 48]} color={T.good} />
        <BMetricTile T={T} label="Средний темп" value={R3.metrics.paceAvg} unit="/км" spark={R3.metrics.paceSpark} />
      </div>

      <div style={{ padding: '6px 20px 12px', flexShrink: 0 }}>
        <BWeekDots T={T} />
      </div>
      <BNav T={T} />
    </div>
  );
}

// ---------- Бегун · десктоп ----------
function BRunnerDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 30, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '5px 6px' }), display: 'flex', gap: 2 }}>
          {['Сегодня', 'План', 'Данные', 'AI-тренер'].map((x, i) => (
            <div key={i} onClick={() => prNav(['home', 'cal', 'stats', 'chat'][i])} style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : 'transparent', color: i === 0 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
        <BLive T={T} label="синхронизировано · garmin" />
        <BModeChip T={T} />
        <div style={{ position: 'relative', cursor: 'pointer' }} onClick={() => prNav('notif')}>{R3Icon.bell(T.ink, 19)}<div style={{ position: 'absolute', top: -1, right: -2, width: 7, height: 7, borderRadius: 99, background: T.accent }}></div></div>
        <div onClick={() => prNav('settings')} style={{ width: 36, height: 36, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 12, fontWeight: 700, color: '#fff', cursor: 'pointer' }}>И</div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '320px 1fr 320px', gap: 16, padding: '4px 36px 24px' }}>
        {/* левая — цель */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '22px 22px 18px', flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10 })}>
            <div style={bLabel(T, { fontSize: 9, alignSelf: 'flex-start' })}>Цель сезона</div>
            <R3Ring pct={R3.goal.progress} size={150} stroke={12} color="url(#r3b-grad)" track={T.track}>
              <div style={{ fontFamily: B_DISP, fontSize: 30, fontWeight: 700, color: T.ink, lineHeight: 1 }}>{R3.goal.daysLeft}</div>
              <div style={bLabel(T, { fontSize: 8 })}>дней</div>
            </R3Ring>
            <div style={{ textAlign: 'center' }}>
              <div style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: T.ink }}>{R3.goal.race}</div>
              <div style={{ fontSize: 12.5, color: T.sub, marginTop: 3 }}>{R3.goal.date} · цель {R3.goal.target}</div>
            </div>
            <div style={{ width: '100%', display: 'flex', justifyContent: 'space-between', borderTop: `1px solid ${T.line}`, paddingTop: 12 }}>
              <div>
                <div style={bLabel(T, { fontSize: 8.5 })}>Прогноз</div>
                <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.accent }}>{R3.goal.predict}</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={bLabel(T, { fontSize: 8.5 })}>VDOT → нужен</div>
                <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>{R3.goal.vdotNow} → {R3.goal.vdotNeed}</div>
              </div>
            </div>
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '16px 20px' })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Личные рекорды</div>
            {R3.prs.map((p, i) => (
              <div key={i} style={{ display: 'flex', justifyContent: 'space-between', padding: '7px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>
                <span style={{ fontSize: 13, fontWeight: 600, color: T.sub }}>{p.dist}</span>
                <span style={{ fontFamily: B_DISP, fontSize: 14, fontWeight: 700, color: T.ink }}>{p.time}</span>
              </div>
            ))}
          </div>
        </div>

        {/* центр — сегодня */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '26px 30px', flex: 1.4, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' })}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
              <BLive T={T} label="тренировка дня" />
              <div style={bLabel(T, { fontSize: 9 })}>{R3.today.dow}, {R3.today.date}</div>
            </div>
            <div>
              <div style={{ fontFamily: B_DISP, fontSize: 42, fontWeight: 700, color: T.ink, lineHeight: 1.1 }}>
                Темповый <span style={{ background: B_GRAD(T), WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>10 км</span>
              </div>
              <div style={{ fontSize: 14, color: T.sub, marginTop: 8, maxWidth: 420, lineHeight: 1.5 }}>{R3.today.why}</div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
              {R3.today.steps.map((s, i) => (
                <div key={i} style={{ background: T.card2, borderRadius: 14, padding: '12px 14px', border: `1px solid ${T.cardBorder}` }}>
                  <div style={bLabel(T, { fontSize: 8.5, color: T.accent })}>{s.n}</div>
                  <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink, margin: '3px 0 2px' }}>{s.name}</div>
                  <div style={{ fontSize: 11.5, color: T.sub }}>{s.detail}</div>
                </div>
              ))}
            </div>
            <BIntervalBar T={T} h={12} />
            <div style={{ display: 'flex', gap: 10 }}>
              <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '12px 28px', fontSize: 14, fontWeight: 700, boxShadow: T.glow }}>Начать тренировку →</div>
              <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 14, padding: '12px 20px', fontSize: 14, fontWeight: 600 }}>Перенести</div>
              <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.good, borderRadius: 14, padding: '12px 18px', fontSize: 14, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 7 }}>{R3Icon.check(T.good, 14)} Выполнена</div>
            </div>
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '16px 24px 14px', flexShrink: 0 })}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
              <div style={bLabel(T, { fontSize: 9 })}>Неделя 24 · {R3.metrics.weekKm} из {R3.metrics.weekPlanKm} км</div>
              <div style={bLabel(T, { fontSize: 9, color: T.accent })}>build-фаза</div>
            </div>
            <BWeekDots T={T} />
          </div>
        </div>

        {/* правая — телеметрия */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, minHeight: 0 }}>
          {/* форма TSB/CTL/ATL + ACWR/TRIMP (FormSectionV3) */}
          <div className="r3b-card r3b-hover" style={bCard(T, { padding: '14px 16px' })}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
              <div style={bLabel(T, { fontSize: 9 })}>Форма · TSB</div>
              <div style={{ fontSize: 11, fontWeight: 700, color: T.good }}>Свежий · +8</div>
            </div>
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12 }}>
              <div style={{ fontFamily: B_DISP, fontSize: 30, fontWeight: 700, color: T.ink, lineHeight: 1 }}>{R3.metrics.form}</div>
              <div style={{ flex: 1 }}><R3Spark data={[64, 70, 68, 74, 78, 76, 80, 82]} w={150} h={26} color={T.good} /></div>
            </div>
            <div style={{ display: 'flex', borderTop: `1px solid ${T.line}`, marginTop: 10, paddingTop: 9 }}>
              {[['ACWR', '1.1', 'оптимально', T.good], ['TRIMP день', '86', '', T.ink], ['TRIMP · 7д', '412', '', T.ink]].map(([l, v, s, c], i) => (
                <div key={i} style={{ flex: 1, borderLeft: i ? `1px solid ${T.line}` : 'none', paddingLeft: i ? 12 : 0 }}>
                  <div style={bLabel(T, { fontSize: 7.5 })}>{l}</div>
                  <div style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: c }}>{v}</div>
                  {s && <div style={{ fontSize: 9, fontWeight: 600, color: T.good }}>{s}</div>}
                </div>
              ))}
            </div>
          </div>
          <BMetricTile T={T} label="VDOT" value={R3.metrics.vdot} delta={R3.metrics.vdotDelta} spark={R3.metrics.vdotSpark} />
          <BMetricTile T={T} label="Пульс покоя" value={R3.metrics.rhr} unit="уд/мин" delta="−2" spark={[52, 51, 51, 50, 49, 49, 48, 48]} color={T.good} />
          {/* зоны темпа (PaceZonesSectionV3) */}
          <div className="r3b-card r3b-hover" style={bCard(T, { padding: '14px 16px', flex: 1 })}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
              <div style={bLabel(T, { fontSize: 9 })}>Зоны темпа · из VDOT</div>
              <div style={bLabel(T, { fontSize: 8.5, color: T.accent })}>обновлены</div>
            </div>
            {[['Лёгкий', '5:45–6:15', T.sub], ['Марафонский', '5:00', T.good], ['Пороговый', '4:35', T.accent], ['Интервальный', '4:12', T.accent2], ['Повторный', '3:55', T.bad]].map(([z, p, c], i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '5px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>
                <div style={{ width: 7, height: 7, borderRadius: 99, background: c, flexShrink: 0 }}></div>
                <span style={{ flex: 1, fontSize: 11.5, fontWeight: 600, color: T.sub }}>{z}</span>
                <span style={{ fontFamily: B_DISP, fontSize: 12.5, fontWeight: 700, color: T.ink }}>{p}<span style={{ fontSize: 8.5, color: T.sub, fontFamily: B_BODY }}> /км</span></span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Тренер · десктоп (mission control) ----------
function BCoachDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  const cellColor = (a, di) => {
    // детерминированная псевдо-карта по атлету и дню
    const seed = (a.id * 7 + di * 3) % 10;
    if (di === 3) return a.state === 'missed' ? T.bad : T.accent;
    if (di > 3) return T.track;
    if (a.compl < 60 && seed > 4) return T.bad;
    return seed > 2 ? T.good : T.track;
  };
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 32px', height: 60, flexShrink: 0 }}>
        <BLogo T={T} size={16} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Центр управления командой</div>
        <BLive T={T} label="3 события сегодня" />
        <div style={{ flex: 1 }}></div>
        {[['Выполнение', '87%', T.good], ['Объём', '276 км', T.ink], ['Риск', '2', T.bad]].map(([l, v, c], i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'baseline', gap: 7 }}>
            <span style={bLabel(T, { fontSize: 9 })}>{l}</span>
            <span style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: c }}>{v}</span>
          </div>
        ))}
        <div className="r3b-btn" onClick={() => prNav('assign')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '9px 18px', fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 7, boxShadow: T.glow }}>
          {R3Icon.plus('#fff', 14)} Назначить
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '300px 1fr 330px', gap: 14, padding: '2px 32px 22px' }}>
        {/* атлеты */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, overflow: 'hidden' })}>
          <div style={{ padding: '14px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: `1px solid ${T.line}` }}>
            <div style={bLabel(T, { fontSize: 9 })}>Атлеты · 8</div>
            {R3Icon.search(T.sub, 15)}
          </div>
          <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
            {R3.athletes.map((a) => (
              <div key={a.id} className="r3b-cell" onClick={() => prNav('overlay')} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '0 16px', flex: 1, borderBottom: `1px solid ${T.line}`, minHeight: 0 }}>
                <R3Ring pct={a.compl / 100} size={32} stroke={3.5} color={a.risk ? T.bad : 'url(#r3b-grad)'} track={T.track} round={false}>
                  <span style={{ fontSize: 8.5, fontWeight: 700, color: T.ink }}>{a.ini}</span>
                </R3Ring>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name}</div>
                  <div style={{ fontSize: 10.5, color: a.risk ? T.bad : T.sub, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.last}</div>
                </div>
                <R3Spark data={a.trend} w={44} h={16} color={a.risk ? T.bad : T.accent} sw={1.6} />
              </div>
            ))}
          </div>
        </div>

        {/* тепловая карта */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, padding: '14px 22px 18px' })}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
            <div style={bLabel(T, { fontSize: 9 })}>Неделя 24 · карта выполнения</div>
            <div style={{ display: 'flex', gap: 14 }}>
              {[['выполнено', T.good], ['сегодня', T.accent], ['пропуск', T.bad]].map(([l, c], i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                  <div style={{ width: 8, height: 8, borderRadius: 3, background: c }}></div>
                  <span style={bLabel(T, { fontSize: 8.5 })}>{l}</span>
                </div>
              ))}
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '110px repeat(7,1fr)', gap: 6, marginBottom: 4 }}>
            <div></div>
            {days.map((d, i) => <div key={i} style={bLabel(T, { fontSize: 9, textAlign: 'center', color: i === 3 ? T.accent : T.sub })}>{d}</div>)}
          </div>
          <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
            {R3.athletes.map((a) => (
              <div key={a.id} style={{ display: 'grid', gridTemplateColumns: '110px repeat(7,1fr)', gap: 6, flex: 1, minHeight: 0 }}>
                <div style={{ fontSize: 11.5, fontWeight: 600, color: T.sub, display: 'flex', alignItems: 'center', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name.split(' ')[0]} {a.name.split(' ')[1][0]}.</div>
                {days.map((_, di) => (
                  <div key={di} className="r3b-cell" style={{ borderRadius: 8, background: cellColor(a, di), opacity: di > 3 ? 0.6 : 0.92, boxShadow: di === 3 && a.state !== 'missed' ? T.glow : 'none' }}></div>
                ))}
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
            {['Назначить всем', 'Сообщение группе', 'Сравнить'].map((x, i) => (
              <div key={i} className="r3b-btn" onClick={() => { if (i === 0) prNav('assign'); }} style={{ fontSize: 12, fontWeight: 600, padding: '8px 14px', borderRadius: 10, border: `1px solid ${T.cardBorder}`, background: T.card2, color: T.ink }}>{x}</div>
            ))}
          </div>
        </div>

        {/* живой поток */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, overflow: 'hidden' })}>
          <div style={{ padding: '14px 18px 10px', borderBottom: `1px solid ${T.line}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div style={bLabel(T, { fontSize: 9 })}>Поток событий</div>
            <BLive T={T} />
          </div>
          <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
            {R3.events.map((e, i) => {
              const c = e.kind === 'done' ? T.good : e.kind === 'pr' ? T.accent : e.kind === 'missed' ? T.bad : T.accent2;
              return (
                <div key={i} style={{ padding: '10px 18px', borderBottom: `1px solid ${T.line}`, flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                    <div style={{ width: 8, height: 8, borderRadius: 99, background: c }}></div>
                    <span style={{ fontSize: 12, fontWeight: 700, color: T.ink }}>{e.who}</span>
                    <span style={{ flex: 1 }}></span>
                    <span style={bLabel(T, { fontSize: 8.5 })}>{e.t}</span>
                  </div>
                  <div style={{ fontSize: 12, color: T.ink, opacity: 0.85, lineHeight: 1.45 }}>{e.text}</div>
                  <div style={{ fontSize: 10.5, color: T.sub, marginTop: 3 }}>{e.meta}</div>
                  {e.kind === 'msg' && (
                    <div style={{ display: 'flex', gap: 6, marginTop: 7 }}>
                      {['Перенести', 'Ответить'].map((x, j) => (
                        <div key={j} className="r3b-btn" style={{ fontSize: 11, fontWeight: 700, padding: '5px 11px', borderRadius: 8, background: j ? B_GRAD(T) : T.card2, color: j ? '#fff' : T.ink, border: j ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Тренер · мобайл ----------
function BCoachMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px 10px', flexShrink: 0 }}>
        <BLogo T={T} />
        <div style={{ flex: 1 }}></div>
        <BLive T={T} label="3 события" />
        {R3Icon.bell(T.ink, 19)}
      </div>
      <div style={{ display: 'flex', gap: 8, padding: '0 16px', flexShrink: 0 }}>
        {[['Выполнение', '87%', T.good], ['Объём', '276', T.ink], ['Риск', '2', T.bad]].map(([l, v, c], i) => (
          <div key={i} className="r3b-card" style={bCard(T, { flex: 1, padding: '10px 13px' })}>
            <div style={bLabel(T, { fontSize: 8 })}>{l}</div>
            <div style={{ fontFamily: B_DISP, fontSize: 19, fontWeight: 700, color: c, marginTop: 2 }}>{v}</div>
          </div>
        ))}
      </div>
      <div style={{ padding: '14px 20px 6px', ...bLabel(T, { fontSize: 9 }) }}>Поток событий</div>
      <div style={{ flex: 1, minHeight: 0, padding: '0 16px', display: 'flex', flexDirection: 'column', gap: 8 }}>
        {R3.events.map((e, i) => {
          const c = e.kind === 'done' ? T.good : e.kind === 'pr' ? T.accent : e.kind === 'missed' ? T.bad : T.accent2;
          return (
            <div key={i} className="r3b-card r3b-hover" style={bCard(T, { padding: '11px 14px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center' })}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <div style={{ width: 8, height: 8, borderRadius: 99, background: c, flexShrink: 0 }}></div>
                <span style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>{e.who}</span>
                <span style={{ flex: 1 }}></span>
                <span style={bLabel(T, { fontSize: 8.5 })}>{e.t}</span>
              </div>
              <div style={{ fontSize: 12, color: T.ink, opacity: 0.85, marginTop: 4, lineHeight: 1.4 }}>{e.text}</div>
              {e.kind === 'msg' && (
                <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
                  {['Перенести', 'Ответить'].map((x, j) => (
                    <div key={j} className="r3b-btn" style={{ fontSize: 11, fontWeight: 700, padding: '6px 12px', borderRadius: 8, background: j ? B_GRAD(T) : T.card2, color: j ? '#fff' : T.ink, border: j ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>
      <div style={{ padding: '10px 16px 14px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('assign')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '13px 18px', fontSize: 14, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
          + Назначить тренировку
        </div>
      </div>
    </div>
  );
}

Object.assign(window, {
  BRunnerMobile, BRunnerDesktop, BCoachDesktop, BCoachMobile,
  // дизайн-система направления B — для остальных экранов v3B
  B_T, B_DISP, B_BODY, B_GRAD, BStyle, BDefs, bLabel, bCard,
  BLogo, BLive, BZoneBar, BMetricTile, BWeekDots, BNav,
  BIntervalBar, BModeChip,
});

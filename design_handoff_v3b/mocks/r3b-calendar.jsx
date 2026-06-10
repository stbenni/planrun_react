// ============================================================
// v3B · Календарь — неделя (мобайл) и план (десктоп).
// Язык «Телеметрии»: фазовая шкала, объём-рейл, day-sheet.
// ============================================================

const BCAL_PHASES = [
  { name: 'База', w: 30, done: true },
  { name: 'Build', w: 30, active: true },
  { name: 'Пик', w: 25 },
  { name: 'Тейпер', w: 15 },
];
const BCAL_VOLUME = [
  { w: 'Н21', km: 42 }, { w: 'Н22', km: 48 }, { w: 'Н23', km: 51 }, { w: 'Н24', km: 55, now: true },
];
const BCAL_DAYS = [
  { d: 'Пн', n: 8, label: 'Отдых', km: null, state: 'rest' },
  { d: 'Вт', n: 9, label: 'Интервалы 6×800', km: 9, state: 'done', fact: '9,2 км · 4:02 ср.' },
  { d: 'Ср', n: 10, label: 'Лёгкий бег', km: 8, state: 'done', fact: '8,1 км · 5:48 ср.' },
  { d: 'Чт', n: 11, label: 'Темповый бег', km: 10, state: 'today', fact: 'Z3 · 4:30–4:40' },
  { d: 'Пт', n: 12, label: 'Отдых', km: null, state: 'rest' },
  { d: 'Сб', n: 13, label: 'Длительная', km: 22, state: 'plan', fact: 'Z2 · 5:40–6:00' },
  { d: 'Вс', n: 14, label: 'Восстановит.', km: 6, state: 'plan', fact: 'Z1 · 6:20+' },
];

function BPhaseBar({ T, h = 8 }) {
  return (
    <div>
      <div style={{ display: 'flex', gap: 3, height: h }}>
        {BCAL_PHASES.map((p, i) => (
          <div key={i} style={{
            width: `${p.w}%`, borderRadius: 99, position: 'relative',
            background: p.active ? B_GRAD(T) : p.done ? T.good : T.track,
            opacity: p.done ? 0.55 : 1,
            boxShadow: p.active ? T.glow : 'none',
          }}></div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 3, marginTop: 6 }}>
        {BCAL_PHASES.map((p, i) => (
          <div key={i} style={{ width: `${p.w}%`, ...bLabel(T, { fontSize: 8.5, color: p.active ? T.accent : T.sub }) }}>{p.name}</div>
        ))}
      </div>
    </div>
  );
}

function BVolumeRail({ T, h = 64 }) {
  const max = 60;
  return (
    <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end' }}>
      {BCAL_VOLUME.map((v, i) => (
        <div key={i} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 5 }}>
          <div style={{ fontFamily: B_BODY, fontSize: 11, fontWeight: 700, color: v.now ? T.accent : T.sub }}>{v.km}</div>
          <div style={{ width: '100%', height: h, display: 'flex', alignItems: 'flex-end' }}>
            <div style={{
              width: '100%', height: `${(v.km / max) * 100}%`, borderRadius: 8,
              background: v.now ? B_GRAD(T) : T.track,
              boxShadow: v.now ? T.glow : 'none',
            }}></div>
          </div>
          <div style={bLabel(T, { fontSize: 8.5, color: v.now ? T.accent : T.sub })}>{v.w}</div>
        </div>
      ))}
    </div>
  );
}

function BDayRow({ T, day, onTone }) {
  const isToday = day.state === 'today';
  const done = day.state === 'done';
  const rest = day.state === 'rest';
  const c = done ? T.good : isToday ? T.accent : rest ? T.sub : T.ink;
  return (
    <div className="r3b-card r3b-hover" onClick={() => { if (done) prNav('workout'); else if (!rest) prNav('result'); }} style={bCard(T, {
      padding: '11px 14px', display: 'flex', alignItems: 'center', gap: 12,
      flex: 1, minHeight: 0,
      border: isToday ? `1.5px solid ${T.accent}` : `1px solid ${T.cardBorder}`,
      boxShadow: isToday ? T.glow : 'none',
      opacity: rest ? 0.6 : 1,
    })}>
      <div style={{ width: 34, textAlign: 'center', flexShrink: 0 }}>
        <div style={bLabel(T, { fontSize: 8.5, color: isToday ? T.accent : T.sub })}>{day.d}</div>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: isToday ? T.accent : T.ink }}>{day.n}</div>
      </div>
      <div style={{ width: 3, alignSelf: 'stretch', borderRadius: 3, background: done ? T.good : isToday ? B_GRAD(T) : T.track, flexShrink: 0 }}></div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{day.label}</div>
        {day.fact && <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{day.fact}</div>}
      </div>
      {day.km && (
        <div style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: c, flexShrink: 0 }}>
          {day.km}<span style={{ fontSize: 9, fontFamily: B_BODY, color: T.sub, marginLeft: 2 }}>км</span>
        </div>
      )}
      {done && R3Icon.check(T.good, 15)}
      {isToday && <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 10, padding: '7px 12px', fontSize: 11, fontWeight: 700, flexShrink: 0 }}>Начать</div>}
    </div>
  );
}

// ---------- Календарь · мобайл ----------
function BCalMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px 10px', flexShrink: 0 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Июнь</div>
        <div style={bLabel(T, { fontSize: 9 })}>неделя 24</div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {['Неделя', 'Месяц'].map((x, i) => (
            <div key={i} onClick={() => prNav(i === 0 ? 'cal' : 'cal-month')} style={{ fontSize: 11, fontWeight: 700, padding: '5px 12px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : 'transparent', color: i === 0 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div className="r3b-btn" onClick={() => prNav('planActions')} title="Действия с планом" style={{ width: 30, height: 30, borderRadius: 10, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {R3Icon.dots(T.sub, 15)}
        </div>
      </div>

      <div style={{ padding: '4px 20px 10px', flexShrink: 0 }}>
        <BPhaseBar T={T} />
      </div>

      <div style={{ padding: '0 16px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', gap: 7 }}>
        {BCAL_DAYS.map((d, i) => <BDayRow key={i} T={T} day={d} />)}
      </div>

      <div className="r3b-card" style={bCard(T, { margin: '10px 16px 8px', padding: '12px 18px', flexShrink: 0, background: T.card2 })}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <div style={bLabel(T, { fontSize: 9 })}>Объём · 4 недели</div>
          <div style={{ fontSize: 11, fontWeight: 700, color: T.accent }}>+8% к прошлой</div>
        </div>
        <BVolumeRail T={T} h={42} />
      </div>
      <BNav T={T} active="cal" />
    </div>
  );
}

// ---------- Календарь · десктоп ----------
function BCalDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const today = BCAL_DAYS[3];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 26, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '5px 6px' }), display: 'flex', gap: 2 }}>
          {['Сегодня', 'План', 'Данные', 'AI-тренер'].map((x, i) => (
            <div key={i} onClick={() => prNav(['home', 'cal', 'stats', 'chat'][i])} style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 99, background: i === 1 ? B_GRAD(T) : 'transparent', color: i === 1 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
        {/* AthleteSelect: тренер смотрит календарь атлета */}
        <div className="r3b-btn r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '5px 13px 5px 6px' }), display: 'flex', alignItems: 'center', gap: 8 }}>
          <div style={{ width: 22, height: 22, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 8, fontWeight: 800, color: '#fff', fontFamily: B_DISP }}>И</div>
          <span style={{ fontSize: 12, fontWeight: 700, color: T.ink }}>Мой календарь</span>
          <svg width="9" height="9" viewBox="0 0 10 10" fill="none" stroke={T.sub} strokeWidth="2" strokeLinecap="round"><path d="M2 3.5l3 3 3-3"></path></svg>
        </div>
        <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>Июнь · неделя 24</div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {['Неделя', 'Месяц'].map((x, i) => (
            <div key={i} style={{ fontSize: 12, fontWeight: 700, padding: '6px 14px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : 'transparent', color: i === 0 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div className="r3b-btn" onClick={() => prNav('planActions')} title="Действия с планом: пересчитать · следующий · очистить" style={{ width: 34, height: 34, borderRadius: 11, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {R3Icon.dots(T.sub, 16)}
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '270px 1fr 340px', gap: 16, padding: '2px 36px 24px' }}>
        {/* левая — макроцикл */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '18px 20px' })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 12 })}>Макроцикл · 16 недель</div>
            <BPhaseBar T={T} h={10} />
            <div style={{ fontSize: 12, color: T.sub, lineHeight: 1.5, marginTop: 14 }}>
              Build-фаза, неделя 4 из 5. Дальше — пиковые объёмы и две контрольные работы.
            </div>
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '18px 20px', flex: 1 })}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
              <div style={bLabel(T, { fontSize: 9 })}>Объём · 4 недели</div>
              <div style={{ fontSize: 11, fontWeight: 700, color: T.accent }}>+8%</div>
            </div>
            <BVolumeRail T={T} h={88} />
            <div style={{ borderTop: `1px solid ${T.line}`, marginTop: 16, paddingTop: 12, display: 'flex', justifyContent: 'space-between' }}>
              <div>
                <div style={bLabel(T, { fontSize: 8.5 })}>Выполнено</div>
                <div style={{ fontFamily: B_DISP, fontSize: 20, fontWeight: 700, color: T.ink }}>17 <span style={{ fontSize: 11, fontFamily: B_BODY, color: T.sub }}>/ 55 км</span></div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={bLabel(T, { fontSize: 8.5 })}>Тренировок</div>
                <div style={{ fontFamily: B_DISP, fontSize: 20, fontWeight: 700, color: T.ink }}>2 <span style={{ fontSize: 11, fontFamily: B_BODY, color: T.sub }}>/ 5</span></div>
              </div>
            </div>
          </div>
          <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '13px 18px', fontSize: 13.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow, flexShrink: 0 }}>
            + Добавить тренировку
          </div>
        </div>

        {/* центр — 7 дней */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, minHeight: 0 }}>
          {BCAL_DAYS.map((d, i) => <BDayRow key={i} T={T} day={d} />)}
        </div>

        {/* правая — day-sheet */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, padding: '20px 22px', border: `1.5px solid ${T.accent}`, boxShadow: T.glow })}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <BLive T={T} label="сегодня · чт 11" />
            <div style={bLabel(T, { fontSize: 9 })}>~48 мин</div>
          </div>
          <div style={{ fontFamily: B_DISP, fontSize: 24, fontWeight: 700, color: T.ink, margin: '12px 0 4px' }}>Темповый · 10 км</div>
          <div style={{ fontSize: 12.5, color: T.sub, lineHeight: 1.5, marginBottom: 14 }}>{R3.today.why}</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {R3.today.steps.map((s, i) => (
              <div key={i} style={{ background: T.card2, borderRadius: 12, padding: '10px 13px', border: `1px solid ${T.cardBorder}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>{s.name}</div>
                  <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{s.detail}</div>
                </div>
                <div style={bLabel(T, { fontSize: 8.5, color: T.accent })}>{s.n}</div>
              </div>
            ))}
          </div>
          <div style={{ margin: '16px 0' }}><BZoneBar T={T} /></div>
          <div style={{ flex: 1 }}></div>
          <div style={{ display: 'flex', gap: 8 }}>
            <div className="r3b-btn" onClick={() => prNav('workout')} style={{ flex: 1, background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '11px 16px', fontSize: 13, fontWeight: 700, textAlign: 'center' }}>Начать →</div>
            <div className="r3b-btn" onClick={() => prNav('result')} style={{ border: `1px solid ${T.cardBorder}`, background: T.card2, color: T.ink, borderRadius: 12, padding: '11px 14px', fontSize: 13, fontWeight: 600 }}>Изменить</div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BCalMobile, BCalDesktop });

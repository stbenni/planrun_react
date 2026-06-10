// ============================================================
// v3B · Календарь-месяц (мобайл), Статистика (десктоп),
// Лендинг (мобайл), Вход (мобайл).
// ============================================================

// ---------- Календарь · месяц · мобайл ----------
function BCalMonthMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  // июнь 2026: 1 июня — пн; 30 дней
  const cells = [];
  for (let d = 1; d <= 30; d++) {
    const dow = (d - 1) % 7;
    let st = 'empty';
    if (d < 11) st = [0, 4].includes(dow) ? 'rest' : 'done';
    else if (d === 11) st = 'today';
    else st = [0, 4].includes(dow) ? 'rest' : 'plan';
    if (d === 9 && st === 'done') st = 'missed';
    cells.push({ d, st });
  }
  const col = (st) => st === 'done' ? T.good : st === 'today' ? null : st === 'missed' ? T.bad : st === 'plan' ? T.sub : 'transparent';
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px 10px', flexShrink: 0 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Июнь 2026</div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {['Неделя', 'Месяц'].map((x, i) => (
            <div key={i} onClick={() => prNav(i === 0 ? 'cal' : 'cal-month')} style={{ fontSize: 11, fontWeight: 700, padding: '5px 12px', borderRadius: 99, background: i === 1 ? B_GRAD(T) : 'transparent', color: i === 1 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
      </div>
      <div style={{ padding: '0 20px 12px', flexShrink: 0 }}>
        <BPhaseBar T={T} />
      </div>
      {/* сетка месяца */}
      <div className="r3b-card" style={bCard(T, { margin: '0 16px', padding: '14px 14px 10px', flexShrink: 0 })}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6, marginBottom: 8 }}>
          {['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].map((d, i) => (
            <div key={i} style={bLabel(T, { fontSize: 8.5, textAlign: 'center' })}>{d}</div>
          ))}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6 }}>
          {cells.map((c) => (
            <div key={c.d} className="r3b-cell" style={{
              aspectRatio: '1', borderRadius: 10, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 2,
              background: c.st === 'today' ? B_GRAD(T) : T.card,
              border: c.st === 'today' ? 'none' : `1px solid ${c.st === 'missed' ? T.bad : T.cardBorder}`,
              boxShadow: c.st === 'today' ? T.glow : 'none', cursor: 'pointer',
            }}>
              <span style={{ fontSize: 11.5, fontWeight: 700, color: c.st === 'today' ? '#fff' : c.st === 'rest' ? T.sub : T.ink, opacity: c.st === 'rest' ? 0.5 : 1 }}>{c.d}</span>
              {c.st !== 'rest' && c.st !== 'today' && (
                <div style={{ width: 5, height: 5, borderRadius: 99, background: col(c.st), opacity: c.st === 'plan' ? 0.45 : 1 }}></div>
              )}
            </div>
          ))}
        </div>
      </div>
      {/* легенда */}
      <div style={{ display: 'flex', gap: 14, padding: '10px 22px', flexShrink: 0 }}>
        {[['выполнено', T.good], ['пропуск', T.bad], ['план', T.sub]].map(([l, c], i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
            <div style={{ width: 7, height: 7, borderRadius: 99, background: c }}></div>
            <span style={bLabel(T, { fontSize: 8.5 })}>{l}</span>
          </div>
        ))}
      </div>
      {/* итог месяца */}
      <div className="r3b-card" style={bCard(T, { margin: '0 16px', padding: '13px 18px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 10, background: T.card2 })}>
        <div style={bLabel(T, { fontSize: 9 })}>Итог июня · план</div>
        <div style={{ display: 'flex' }}>
          {[['212', 'км'], ['22', 'тренировки'], ['2', 'контрольных']].map(([v, l], i) => (
            <div key={i} style={{ flex: 1, borderLeft: i ? `1px solid ${T.line}` : 'none', paddingLeft: i ? 16 : 0 }}>
              <div style={{ fontFamily: B_DISP, fontSize: 22, fontWeight: 700, color: i === 0 ? T.accent : T.ink }}>{v}</div>
              <div style={bLabel(T, { fontSize: 8, marginTop: 2 })}>{l}</div>
            </div>
          ))}
        </div>
      </div>
      <div style={{ height: 10, flexShrink: 0 }}></div>
      <BNav T={T} active="cal" />
    </div>
  );
}

// ---------- Статистика · десктоп ----------
function BStatsDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 26, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '5px 6px' }), display: 'flex', gap: 2 }}>
          {['Сегодня', 'План', 'Данные', 'AI-тренер'].map((x, i) => (
            <div key={i} onClick={() => prNav(['home', 'cal', 'stats', 'chat'][i])} style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 99, background: i === 2 ? B_GRAD(T) : 'transparent', color: i === 2 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {['Месяц', 'Сезон', 'Год'].map((x, i) => (
            <div key={i} style={{ fontSize: 12, fontWeight: 700, padding: '6px 14px', borderRadius: 99, background: i === 1 ? B_GRAD(T) : 'transparent', color: i === 1 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '380px 1fr', gap: 16, padding: '2px 36px 24px' }}>
        {/* левая — hero + рекорды */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '22px 24px', border: `1px solid ${T.accent}` })}>
            <BLive T={T} label="главный инсайт сезона" />
            <div style={{ display: 'flex', alignItems: 'center', gap: 18, marginTop: 14 }}>
              <R3Ring pct={0.72} size={110} stroke={10} color="url(#r3b-grad)" track={T.track}>
                <div style={{ fontFamily: B_DISP, fontSize: 26, fontWeight: 700, color: T.ink }}>{R3.metrics.vdot}</div>
                <div style={bLabel(T, { fontSize: 7.5 })}>vdot</div>
              </R3Ring>
              <div style={{ fontSize: 13.5, fontWeight: 600, color: T.ink, lineHeight: 1.55, flex: 1 }}>
                Форма выросла на <span style={{ color: T.accent, fontWeight: 800 }}>+0.8 VDOT</span> за месяц. До целевого 53.4 — разрыв 1.3, план закрывает его к сентябрю.
              </div>
            </div>
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '18px 22px' })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Личные рекорды</div>
            {[...R3.prs, { dist: 'Марафон', time: '—' }].map((p, i) => (
              <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>
                <span style={{ fontSize: 13, fontWeight: 600, color: T.sub }}>{p.dist}</span>
                <span style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: i === 1 ? T.accent : T.ink }}>{p.time}{i === 1 && <span style={{ fontSize: 9, fontFamily: B_BODY, marginLeft: 6, color: T.good }}>новый</span>}</span>
              </div>
            ))}
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '18px 22px', flex: 1, background: T.card2 })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Прогноз марафона</div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div>
                <div style={{ fontFamily: B_DISP, fontSize: 26, fontWeight: 700, color: T.ink }}>{R3.goal.predict}</div>
                <div style={bLabel(T, { fontSize: 8.5, marginTop: 3 })}>текущий прогноз</div>
              </div>
              {R3Icon.arrow(T.sub, 18)}
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontFamily: B_DISP, fontSize: 26, fontWeight: 700, color: T.accent }}>{R3.goal.target}</div>
                <div style={bLabel(T, { fontSize: 8.5, marginTop: 3 })}>цель · 04.10</div>
              </div>
            </div>
          </div>
        </div>

        {/* правая — тренды */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '18px 24px', flex: 1.2, display: 'flex', flexDirection: 'column' })}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
              <div style={bLabel(T, { fontSize: 9 })}>Нагрузка · 8 недель</div>
              <div style={{ display: 'flex', gap: 14 }}>
                <span style={bLabel(T, { fontSize: 8.5, color: T.accent })}>— объём</span>
                <span style={bLabel(T, { fontSize: 8.5, color: T.good })}>— форма</span>
              </div>
            </div>
            <div style={{ flex: 1, minHeight: 0, position: 'relative' }}>
              <svg width="100%" height="100%" viewBox="0 0 600 180" preserveAspectRatio="none">
                {[45, 90, 135].map((y, i) => <line key={i} x1="0" y1={y} x2="600" y2={y} stroke={T.line} strokeWidth="1"></line>)}
                <polygon points="0,180 0,120 85,98 170,110 255,76 340,90 425,62 510,74 600,48 600,180" fill={dark ? 'rgba(255,90,31,0.14)' : 'rgba(244,72,10,0.1)'}></polygon>
                <polyline points="0,120 85,98 170,110 255,76 340,90 425,62 510,74 600,48" fill="none" stroke="url(#r3b-grad)" strokeWidth="3" strokeLinecap="round"></polyline>
                <polyline points="0,150 85,140 170,144 255,124 340,116 425,108 510,96 600,84" fill="none" stroke={T.good} strokeWidth="2.5" strokeLinecap="round" strokeDasharray="1 0"></polyline>
                <circle cx="600" cy="48" r="5" fill={T.accent}></circle>
              </svg>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
              {['Н17', 'Н18', 'Н19', 'Н20', 'Н21', 'Н22', 'Н23', 'Н24'].map((w, i) => (
                <span key={i} style={bLabel(T, { fontSize: 8.5, color: i === 7 ? T.accent : T.sub })}>{w}</span>
              ))}
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 10, flexShrink: 0 }}>
            <BMetricTile T={T} label="VDOT" value={R3.metrics.vdot} delta="+0.8" spark={R3.metrics.vdotSpark} />
            <BMetricTile T={T} label="Объём, км/нед" value="55" delta="+8%" spark={R3.metrics.loadSpark} color={T.accent2} />
            <BMetricTile T={T} label="Темп лёгкого" value="5:12" delta="−9 c" spark={R3.metrics.paceSpark} color={T.good} />
            <BMetricTile T={T} label="Пульс покоя" value="48" delta="−2" spark={[52, 51, 51, 50, 49, 49, 48, 48]} color={T.good} />
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '16px 24px', flexShrink: 0 })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Достижения сезона</div>
            <div style={{ display: 'flex', gap: 10 }}>
              {[['PR 21,1', 'полумарафон 1:43:05', true], ['Серия 14', 'дней без пропусков', true], ['1000 км', 'с начала года', false], ['Эльбрус', 'вертикальный км', false]].map(([t, s, on], i) => (
                <div key={i} style={{ flex: 1, padding: '11px 13px', borderRadius: 14, border: on ? `1.5px solid ${T.accent}` : `1px dashed ${T.cardBorder}`, opacity: on ? 1 : 0.55, background: on ? T.card2 : 'transparent' }}>
                  <div style={{ fontFamily: B_DISP, fontSize: 14, fontWeight: 700, color: on ? T.accent : T.sub }}>{t}</div>
                  <div style={{ fontSize: 10.5, color: T.sub, marginTop: 2 }}>{s}</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BCalMonthMobile, BStatsDesktop });

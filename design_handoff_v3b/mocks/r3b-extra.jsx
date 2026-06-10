// ============================================================
// v3B · Статистика (мобайл) — hero-инсайт, рекорды, тренды.
// ============================================================

function BStatsMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px 8px', flexShrink: 0 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Данные</div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {['Месяц', 'Сезон', 'Год'].map((x, i) => (
            <div key={i} style={{ fontSize: 11, fontWeight: 700, padding: '5px 11px', borderRadius: 99, background: i === 1 ? B_GRAD(T) : 'transparent', color: i === 1 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
      </div>

      {/* hero-инсайт */}
      <div className="r3b-card" style={bCard(T, { margin: '6px 16px 0', padding: '16px 18px', flexShrink: 0, border: `1px solid ${T.accent}` })}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <R3Ring pct={0.72} size={92} stroke={9} color="url(#r3b-grad)" track={T.track}>
            <div style={{ fontFamily: B_DISP, fontSize: 22, fontWeight: 700, color: T.ink, lineHeight: 1 }}>{R3.metrics.vdot}</div>
            <div style={bLabel(T, { fontSize: 7.5 })}>vdot</div>
          </R3Ring>
          <div style={{ flex: 1 }}>
            <BLive T={T} label="главный инсайт" />
            <div style={{ fontSize: 13, fontWeight: 600, color: T.ink, lineHeight: 1.5, marginTop: 6 }}>
              Форма выросла на <span style={{ color: T.accent, fontWeight: 800 }}>+0.8 VDOT</span> за месяц.
              До цели марафона не хватает 1.3 — план закрывает разрыв к сентябрю.
            </div>
          </div>
        </div>
      </div>

      {/* рекорды */}
      <div style={{ padding: '14px 20px 6px', ...bLabel(T, { fontSize: 9 }), flexShrink: 0 }}>Личные рекорды</div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, padding: '0 16px', flexShrink: 0 }}>
        {R3.prs.map((p, i) => (
          <div key={i} className="r3b-card r3b-hover" style={bCard(T, { padding: '11px 13px', textAlign: 'center' })}>
            <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: i === 1 ? T.accent : T.ink }}>{p.time}</div>
            <div style={bLabel(T, { fontSize: 8, marginTop: 3 })}>{p.dist}{i === 1 ? ' · новый' : ''}</div>
          </div>
        ))}
      </div>

      {/* тренды */}
      <div style={{ padding: '14px 20px 6px', ...bLabel(T, { fontSize: 9 }), flexShrink: 0 }}>Тренды · 8 недель</div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, padding: '0 16px', flex: 1, minHeight: 0, alignContent: 'start' }}>
        <BMetricTile T={T} label="VDOT" value={R3.metrics.vdot} delta="+0.8" spark={R3.metrics.vdotSpark} />
        <BMetricTile T={T} label="Объём, км/нед" value="55" delta="+8%" spark={R3.metrics.loadSpark} color={T.accent2} />
        <BMetricTile T={T} label="Темп лёгкого" value="5:12" delta="−9 c" spark={R3.metrics.paceSpark} color={T.good} />
        <BMetricTile T={T} label="Пульс покоя" value="48" delta="−2" spark={[52, 51, 51, 50, 49, 49, 48, 48]} color={T.good} />
      </div>

      {/* прогноз */}
      <div className="r3b-card" style={bCard(T, { margin: '4px 16px 12px', padding: '13px 18px', flexShrink: 0, background: T.card2 })}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <div style={bLabel(T, { fontSize: 8.5 })}>Прогноз марафона</div>
            <div style={{ fontFamily: B_DISP, fontSize: 22, fontWeight: 700, color: T.ink, marginTop: 2 }}>{R3.goal.predict}</div>
          </div>
          <div style={{ textAlign: 'right' }}>
            <div style={bLabel(T, { fontSize: 8.5 })}>Цель</div>
            <div style={{ fontFamily: B_DISP, fontSize: 22, fontWeight: 700, color: T.accent, marginTop: 2 }}>{R3.goal.target}</div>
          </div>
          <R3Ring pct={0.64} size={48} stroke={5} color="url(#r3b-grad)" track={T.track}>
            <span style={{ fontSize: 9, fontWeight: 800, color: T.ink }}>64%</span>
          </R3Ring>
        </div>
      </div>
      <BNav T={T} active="stats" />
    </div>
  );
}

// ============================================================
// v3B · Онбординг — цель, генерация, готово (фикс аудита №1–2)
// ============================================================

function BObShell({ T, step, children }) {
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '16px 22px 0', flexShrink: 0 }}>
        <BLogo T={T} />
        <div style={{ flex: 1, display: 'flex', gap: 5 }}>
          {[0, 1, 2].map((i) => (
            <div key={i} style={{ flex: 1, height: 4, borderRadius: 99, background: i < step ? B_GRAD(T) : T.track }}></div>
          ))}
        </div>
        <div style={bLabel(T, { fontSize: 9 })}>{step} / 3</div>
      </div>
      {children}
    </div>
  );
}

function BObGoal({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const goals = [
    { ic: 'flame', name: 'Здоровье', sub: 'Бегать для тонуса и формы' },
    { ic: 'run', name: 'Забег', sub: 'Подготовиться к дистанции', on: true },
    { ic: 'stats', name: 'Быстрее', sub: 'Улучшить личное время' },
  ];
  const progs = [
    { name: 'Марафон 42,2', sub: '16 недель · от 40 км/нед', on: true },
    { name: 'Полумарафон', sub: '12 недель · от 25 км/нед' },
    { name: '10 км', sub: '8 недель · с любого уровня' },
  ];
  return (
    <BObShell T={T} step={2}>
      <div style={{ padding: '26px 22px 0', flexShrink: 0 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 27, fontWeight: 700, color: T.ink, lineHeight: 1.15 }}>
          Какая цель?
        </div>
        <div style={{ fontSize: 13, color: T.sub, marginTop: 6 }}>Под неё соберём персональный план.</div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, padding: '16px 22px 0', flexShrink: 0 }}>
        {goals.map((g, i) => (
          <div key={i} className="r3b-card r3b-hover" style={bCard(T, {
            padding: '14px 12px', textAlign: 'center', cursor: 'pointer',
            border: g.on ? `1.5px solid ${T.accent}` : `1px solid ${T.cardBorder}`,
            boxShadow: g.on ? T.glow : 'none', background: g.on ? T.card2 : T.card,
          })}>
            <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 8 }}>{R3Icon[g.ic](g.on ? T.accent : T.sub, 22)}</div>
            <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{g.name}</div>
            <div style={{ fontSize: 10, color: T.sub, marginTop: 3, lineHeight: 1.35 }}>{g.sub}</div>
          </div>
        ))}
      </div>
      <div style={{ padding: '20px 22px 6px', ...bLabel(T, { fontSize: 9 }) }}>Программа</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, padding: '0 22px', flex: 1, minHeight: 0 }}>
        {progs.map((p, i) => (
          <div key={i} className="r3b-card r3b-hover" style={bCard(T, {
            padding: '13px 16px', display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer',
            border: p.on ? `1.5px solid ${T.accent}` : `1px solid ${T.cardBorder}`,
            background: p.on ? T.card2 : T.card,
          })}>
            <div style={{ width: 20, height: 20, borderRadius: 99, flexShrink: 0, border: p.on ? 'none' : `2px solid ${T.sub}`, background: p.on ? B_GRAD(T) : 'transparent', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              {p.on && R3Icon.check('#fff', 11)}
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 14, fontWeight: 700, color: T.ink }}>{p.name}</div>
              <div style={{ fontSize: 11.5, color: T.sub, marginTop: 2 }}>{p.sub}</div>
            </div>
          </div>
        ))}
        <div className="r3b-card" style={bCard(T, { padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12 })}>
          <div style={{ flex: 1 }}>
            <div style={bLabel(T, { fontSize: 8.5 })}>Старт плана</div>
            <div style={{ fontSize: 14, fontWeight: 700, color: T.ink, marginTop: 2 }}>Понедельник, 15 июня</div>
          </div>
          {R3Icon.cal(T.accent, 18)}
        </div>
      </div>
      <div style={{ display: 'flex', gap: 10, padding: '10px 22px 18px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('register')} style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 14, padding: '13px 20px', fontSize: 13.5, fontWeight: 600 }}>← Назад</div>
        <div className="r3b-btn" onClick={() => prNav('ob-gen')} style={{ flex: 1, background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '13px 16px', fontSize: 13.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>Дальше →</div>
      </div>
    </BObShell>
  );
}

function BObGenerating({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  // В прототипе — автопереход к «План готов» через 3 сек
  React.useEffect(() => {
    if (!window.__prNavigate) return undefined;
    const t = setTimeout(() => window.__prNavigate('ob-ready'), 3000);
    return () => clearTimeout(t);
  }, []);
  const steps = [
    { t: 'Профиль и цель', done: true },
    { t: 'История тренировок · 24 активности', done: true },
    { t: 'Расчёт зон и VDOT', done: true },
    { t: 'Сборка 16 недель плана', live: true },
    { t: 'Проверка нагрузки', wait: true },
  ];
  return (
    <BObShell T={T} step={3}>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '0 30px', gap: 22 }}>
        <R3Ring pct={0.68} size={150} stroke={12} color="url(#r3b-grad)" track={T.track}>
          <div style={{ fontFamily: B_DISP, fontSize: 32, fontWeight: 700, color: T.ink }}>68%</div>
          <div style={bLabel(T, { fontSize: 8 })}>сборка</div>
        </R3Ring>
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontFamily: B_DISP, fontSize: 23, fontWeight: 700, color: T.ink }}>Собираю твой план</div>
          <div style={{ fontSize: 13, color: T.sub, marginTop: 6, lineHeight: 1.5 }}>Марафон · 04.10 · цель 3:29:59.<br />Обычно это занимает 2–3 минуты.</div>
        </div>
        <div className="r3b-card" style={bCard(T, { padding: '6px 18px', width: '100%', maxWidth: 320 })}>
          {steps.map((s, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '10px 0', borderTop: i ? `1px solid ${T.line}` : 'none', opacity: s.wait ? 0.45 : 1 }}>
              {s.done ? R3Icon.check(T.good, 14) : s.live ? (
                <div style={{ width: 8, height: 8, borderRadius: 99, background: T.accent, animation: 'r3b-pulse 1.6s infinite', margin: 3 }}></div>
              ) : <div style={{ width: 8, height: 8, borderRadius: 99, border: `1.5px solid ${T.sub}`, margin: 3 }}></div>}
              <span style={{ fontSize: 12.5, fontWeight: 600, color: s.live ? T.ink : T.sub }}>{s.t}</span>
            </div>
          ))}
        </div>
      </div>
      <div style={{ padding: '0 22px 18px', textAlign: 'center', ...bLabel(T, { fontSize: 9 }) }}>
        Можно закрыть — пришлём уведомление
      </div>
    </BObShell>
  );
}

function BObReady({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <BObShell T={T} step={3}>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '0 24px', gap: 14 }}>
        <BLive T={T} label="план готов" />
        <div style={{ fontFamily: B_DISP, fontSize: 30, fontWeight: 700, color: T.ink, lineHeight: 1.15 }}>
          16 недель до<br />твоего марафона.
        </div>
        <div style={{ fontSize: 13.5, color: T.sub, lineHeight: 1.55 }}>
          221 тренировка · пик 62 км/нед · 2 контрольных забега. План будет адаптироваться под каждую твою тренировку.
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, marginTop: 6 }}>
          {[['62', 'км/нед пик'], ['5', 'дней в нед'], ['3:29:59', 'цель']].map(([v, l], i) => (
            <div key={i} className="r3b-card" style={bCard(T, { padding: '12px 10px', textAlign: 'center' })}>
              <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: i === 2 ? T.accent : T.ink }}>{v}</div>
              <div style={bLabel(T, { fontSize: 7.5, marginTop: 3 })}>{l}</div>
            </div>
          ))}
        </div>
        {/* первая неделя */}
        <div className="r3b-card" style={bCard(T, { padding: '14px 16px', background: T.card2 })}>
          <div style={bLabel(T, { fontSize: 8.5, marginBottom: 10 })}>Первая неделя · 15–21 июня</div>
          <BWeekDots T={T} />
        </div>
      </div>
      <div style={{ padding: '0 22px 18px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('cal')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '15px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
          Открыть календарь →
        </div>
      </div>
    </BObShell>
  );
}

Object.assign(window, { BStatsMobile, BObGoal, BObGenerating, BObReady });

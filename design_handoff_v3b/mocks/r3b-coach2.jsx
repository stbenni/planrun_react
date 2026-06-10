// ============================================================
// v3B · Тренер: drill-in атлета (AthleteOverlay), мастер
// назначения (BulkAssignModal), библиотека шаблонов (/library).
// ============================================================

// ---------- Drill-in атлета · десктоп ----------
function BAthleteOverlay({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const a = R3.athletes[0];
  const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', position: 'relative' }}>
      <BStyle T={T} /><BDefs />
      {/* затемнённый фон-подложка */}
      <div style={{ position: 'absolute', inset: 0, background: dark ? 'rgba(4,6,10,0.6)' : 'rgba(14,20,32,0.35)', backdropFilter: 'blur(6px)' }}></div>
      <div className="r3b-card" style={bCard(T, {
        position: 'absolute', top: 56, left: 160, right: 160, bottom: 56,
        background: dark ? '#101622' : '#FAFBFE', display: 'flex', flexDirection: 'column', overflow: 'hidden',
        boxShadow: '0 32px 96px rgba(0,0,0,0.5)',
      })}>
        {/* шапка */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '18px 28px', borderBottom: `1px solid ${T.line}`, flexShrink: 0 }}>
          <R3Ring pct={a.compl / 100} size={52} stroke={5} color="url(#r3b-grad)" track={T.track}>
            <span style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: T.ink }}>{a.ini}</span>
          </R3Ring>
          <div style={{ flex: 1 }}>
            <div style={{ fontFamily: B_DISP, fontSize: 19, fontWeight: 700, color: T.ink }}>{a.name}</div>
            <div style={{ fontSize: 12, color: T.sub, marginTop: 2 }}>Марафон 04.10 · цель 3:29:59 · в команде 8 месяцев</div>
          </div>
          {[['Выполнение', a.compl + '%', T.good], ['Объём', a.km + '/' + a.plan, T.ink], ['VDOT', a.vdot, T.ink]].map(([l, v, c], i) => (
            <div key={i} style={{ textAlign: 'right', marginLeft: 14 }}>
              <div style={bLabel(T, { fontSize: 8.5 })}>{l}</div>
              <div style={{ fontFamily: B_DISP, fontSize: 19, fontWeight: 700, color: c }}>{v}</div>
            </div>
          ))}
          <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 34, height: 34, borderRadius: 99, border: `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', marginLeft: 12, fontSize: 15, color: T.sub }}>✕</div>
        </div>

        <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '1.5fr 1fr', gap: 16, padding: '18px 28px' }}>
          {/* неделя + тренд */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12, minHeight: 0 }}>
            <div className="r3b-card" style={bCard(T, { padding: '14px 18px' })}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
                <div style={bLabel(T, { fontSize: 9 })}>Неделя 24</div>
                <div style={bLabel(T, { fontSize: 9, color: T.accent })}>build</div>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 7 }}>
                {days.map((d, i) => {
                  const st = i < 3 ? 'done' : i === 3 ? 'today' : i === 4 ? 'rest' : 'plan';
                  const c = st === 'done' ? T.good : st === 'today' ? T.accent : T.track;
                  return (
                    <div key={i} style={{ textAlign: 'center' }}>
                      <div style={bLabel(T, { fontSize: 8.5, color: st === 'today' ? T.accent : T.sub })}>{d}</div>
                      <div className="r3b-cell" style={{ height: 38, borderRadius: 10, marginTop: 5, background: c, opacity: st === 'plan' ? 0.5 : 0.95, boxShadow: st === 'today' ? T.glow : 'none' }}></div>
                    </div>
                  );
                })}
              </div>
            </div>
            <div className="r3b-card" style={bCard(T, { padding: '14px 18px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' })}>
              <div style={bLabel(T, { fontSize: 9, marginBottom: 8 })}>Последние тренировки</div>
              <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
                {[
                  ['Темповый 10 км', 'сегодня 07:40 · 4:34 ср. · ЧСС 158', 'в плане', T.good],
                  ['Интервалы 6×800', 'вт · отрезки 3:42–3:48 · ЧСС 172 макс', 'в плане', T.good],
                  ['Лёгкий 8 км', 'ср · 5:48 ср. · восстановительный', 'в плане', T.good],
                  ['Длительная 24 км', 'вс · 5:52 ср. · последние 4 км дрейф +9%', 'на грани', T.accent],
                ].map(([name, meta, tag, c], i) => (
                  <div key={i} className="r3b-cell" style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '9px 0', borderTop: i ? `1px solid ${T.line}` : 'none', flex: 1, minHeight: 0 }}>
                    <div style={{ width: 3, alignSelf: 'stretch', borderRadius: 3, background: c }}></div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>{name}</div>
                      <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1 }}>{meta}</div>
                    </div>
                    <div style={{ ...bLabel(T, { fontSize: 8, color: c }), border: `1px solid ${c}`, borderRadius: 99, padding: '3px 9px' }}>{tag}</div>
                  </div>
                ))}
              </div>
            </div>
          </div>
          {/* телеметрия + действия */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12, minHeight: 0 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <BMetricTile T={T} label="VDOT" value={a.vdot} delta="+0.6" spark={a.trend} />
              <BMetricTile T={T} label="Нагрузка" value="58" unit="ед" spark={R3.metrics.loadSpark} color={T.accent2} />
            </div>
            <div className="r3b-card" style={bCard(T, { padding: '14px 18px', border: `1px solid ${T.accent}`, background: T.card2 })}>
              <BLive T={T} label="AI-сводка для тренера" />
              <div style={{ fontSize: 12.5, color: T.ink, lineHeight: 1.55, marginTop: 8 }}>
                Алексей стабильно в зонах, но на длительных появляется дрейф пульса после 20 км. Рекомендация: добавить питание на дистанции и снизить темп длительной на 10 сек/км.
              </div>
            </div>
            <div style={{ flex: 1 }}></div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <div className="r3b-btn" onClick={() => prNav('assign')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '12px 18px', fontSize: 13, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>Назначить тренировку</div>
              <div style={{ display: 'flex', gap: 8 }}>
                <div className="r3b-btn" style={{ flex: 1, border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '11px 14px', fontSize: 12.5, fontWeight: 600, textAlign: 'center' }}>Написать</div>
                <div className="r3b-btn" style={{ flex: 1, border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '11px 14px', fontSize: 12.5, fontWeight: 600, textAlign: 'center' }}>Править план</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Мастер назначения · десктоп ----------
function BAssignWizard({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const tpls = [
    { name: 'Интервалы 6×800', sub: 'Z4 · отдых 400 м трусцой', on: true },
    { name: 'Темповый 8 км', sub: 'Z3 · разминка/заминка 2 км' },
    { name: 'Длительная 25 км', sub: 'Z2 · негатив-сплит' },
    { name: 'Фартлек 40 мин', sub: 'свободные ускорения' },
  ];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', position: 'relative' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ position: 'absolute', inset: 0, background: dark ? 'rgba(4,6,10,0.6)' : 'rgba(14,20,32,0.35)', backdropFilter: 'blur(6px)' }}></div>
      <div className="r3b-card" style={bCard(T, {
        position: 'absolute', top: 90, left: 320, right: 320, bottom: 90,
        background: dark ? '#101622' : '#FAFBFE', display: 'flex', flexDirection: 'column', overflow: 'hidden',
        boxShadow: '0 32px 96px rgba(0,0,0,0.5)',
      })}>
        <div style={{ padding: '20px 28px 0', flexShrink: 0 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div style={{ fontFamily: B_DISP, fontSize: 18, fontWeight: 700, color: T.ink }}>Назначить тренировку</div>
            <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 32, height: 32, borderRadius: 99, border: `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14, color: T.sub }}>✕</div>
          </div>
          {/* шаги */}
          <div style={{ display: 'flex', gap: 8, margin: '16px 0' }}>
            {[['Атлеты', 'done'], ['Тренировка', 'active'], ['Дата и параметры', 'wait']].map(([s, st], i) => (
              <div key={i} style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 8 }}>
                <div style={{ width: 24, height: 24, borderRadius: 99, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: st === 'done' ? T.good : st === 'active' ? B_GRAD(T) : T.track, color: '#fff', fontSize: 11, fontWeight: 800 }}>
                  {st === 'done' ? R3Icon.check('#fff', 11) : i + 1}
                </div>
                <span style={{ fontSize: 12, fontWeight: 700, color: st === 'wait' ? T.sub : T.ink }}>{s}</span>
                {i < 2 && <div style={{ flex: 1, height: 2, borderRadius: 2, background: st === 'done' ? T.good : T.track }}></div>}
              </div>
            ))}
          </div>
        </div>
        <div style={{ padding: '0 28px', flexShrink: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '9px 14px', display: 'flex', alignItems: 'center', gap: 8, background: T.card2 })}>
            <span style={bLabel(T, { fontSize: 8.5 })}>Кому:</span>
            {['Алексей П.', 'Мария С.', 'Анна В.'].map((x, i) => (
              <div key={i} style={{ fontSize: 11, fontWeight: 700, padding: '4px 11px', borderRadius: 99, background: B_GRAD(T), color: '#fff' }}>{x} ✕</div>
            ))}
            <span style={{ fontSize: 11, color: T.sub, fontWeight: 600 }}>+ добавить</span>
          </div>
        </div>
        <div style={{ flex: 1, minHeight: 0, padding: '14px 28px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, alignContent: 'start' }}>
          {tpls.map((t, i) => (
            <div key={i} className="r3b-card r3b-hover" style={bCard(T, { padding: '13px 16px', display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer', border: t.on ? `1.5px solid ${T.accent}` : `1px solid ${T.cardBorder}`, background: t.on ? T.card2 : T.card, boxShadow: t.on ? T.glow : 'none' })}>
              <div style={{ width: 20, height: 20, borderRadius: 99, flexShrink: 0, border: t.on ? 'none' : `2px solid ${T.sub}`, background: t.on ? B_GRAD(T) : 'transparent', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                {t.on && R3Icon.check('#fff', 11)}
              </div>
              <div>
                <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>{t.name}</div>
                <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{t.sub}</div>
              </div>
            </div>
          ))}
          <div className="r3b-card" style={bCard(T, { padding: '13px 16px', border: `1px dashed ${T.accent}`, background: 'transparent', display: 'flex', alignItems: 'center', gap: 10, gridColumn: '1 / -1' })}>
            {R3Icon.plus(T.accent, 15)}
            <span style={{ fontSize: 12.5, fontWeight: 700, color: T.accent }}>Создать новую — или описать словами, AI соберёт структуру</span>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10, padding: '0 28px 22px', flexShrink: 0 }}>
          <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '11px 20px', fontSize: 13, fontWeight: 600 }}>← Назад</div>
          <div style={{ flex: 1 }}></div>
          <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '11px 26px', fontSize: 13, fontWeight: 700, boxShadow: T.glow }}>Дальше: дата →</div>
        </div>
      </div>
    </div>
  );
}

// ---------- Библиотека шаблонов · десктоп (/library) ----------
function BTemplatesDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const tpls = [
    { name: 'Интервалы 6×800', kind: 'Интервалы', sub: '2 км разминка · 6×800 Z4 / 400 трусца · 2 км заминка', used: 14, c: '#FF5A1F' },
    { name: 'Темповый 8 км', kind: 'Темп', sub: '2 км разминка · 8 км Z3 · 2 км заминка', used: 22, c: '#FF2D78' },
    { name: 'Длительная 25 км', kind: 'Длительная', sub: 'Z2 ровно · последние 5 км негатив-сплит', used: 9, c: '#3DDC97' },
    { name: 'Фартлек 40 мин', kind: 'Фартлек', sub: '10 мин лёгкий · 8×(2 мин быстро / 2 мин трусца)', used: 11, c: '#FFB020' },
    { name: 'Восстановительный 6 км', kind: 'Лёгкий', sub: 'Z1 · по самочувствию · без часов', used: 31, c: '#9AA5B4' },
    { name: 'ОФП · силовая 45 мин', kind: 'ОФП', sub: 'Кор + стопа + ягодицы · 3 круга', used: 17, c: '#7E8AFF' },
  ];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Библиотека шаблонов</div>
        <div style={{ flex: 1 }}></div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, border: `1px solid ${T.cardBorder}`, borderRadius: 12, padding: '8px 14px', width: 240, color: T.sub, background: T.card }}>
          {R3Icon.search(T.sub, 15)}
          <span style={{ fontSize: 12, fontWeight: 500 }}>Найти шаблон…</span>
        </div>
        <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '9px 18px', fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 7, boxShadow: T.glow }}>
          {R3Icon.plus('#fff', 14)} Новый шаблон
        </div>
      </div>
      <div style={{ display: 'flex', gap: 8, padding: '4px 36px 14px', flexShrink: 0 }}>
        {['Все', 'Интервалы', 'Темп', 'Длительная', 'Лёгкий', 'ОФП'].map((x, i) => (
          <div key={i} className="r3b-btn" style={{ fontSize: 12, fontWeight: 700, padding: '7px 14px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : T.card, color: i === 0 ? '#fff' : T.sub, border: i === 0 ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
        ))}
      </div>
      <div style={{ flex: 1, minHeight: 0, padding: '0 36px 24px', display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gridTemplateRows: '1fr 1fr', gap: 12 }}>
        {tpls.map((t, i) => (
          <div key={i} className="r3b-card r3b-hover" style={bCard(T, { padding: '18px 20px', display: 'flex', flexDirection: 'column', cursor: 'pointer' })}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ fontSize: 10, fontWeight: 800, letterSpacing: '0.1em', textTransform: 'uppercase', color: t.c }}>{t.kind}</div>
              {R3Icon.dots(T.sub, 16)}
            </div>
            <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink, margin: '10px 0 6px' }}>{t.name}</div>
            <div style={{ fontSize: 12, color: T.sub, lineHeight: 1.5, flex: 1 }}>{t.sub}</div>
            {/* структурная полоска */}
            <div style={{ display: 'flex', gap: 3, height: 8, margin: '12px 0' }}>
              <div style={{ width: '20%', borderRadius: 99, background: T.track }}></div>
              <div style={{ width: '55%', borderRadius: 99, background: t.c, opacity: 0.85 }}></div>
              <div style={{ width: '25%', borderRadius: 99, background: T.track }}></div>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={bLabel(T, { fontSize: 8.5 })}>назначен {t.used} раз</span>
              <div className="r3b-btn" onClick={() => prNav('assign')} style={{ ...bLabel(T, { fontSize: 8.5, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '5px 12px' }}>назначить</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { BAthleteOverlay, BAssignWizard, BTemplatesDesktop });

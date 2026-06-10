// ============================================================
// v3B · Ключевые модальные состояния из реального кода:
// ModeSwitchPopup, ResultModal, Readiness-опрос, PlanActionsMenu,
// DashCustomizer.
// ============================================================

function BSheet({ T, dark, title, children, h = 'auto' }) {
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', position: 'relative', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ position: 'absolute', inset: 0, background: dark ? 'rgba(4,6,10,0.65)' : 'rgba(14,20,32,0.4)', backdropFilter: 'blur(5px)' }}></div>
      <div className="r3b-card" style={bCard(T, {
        position: 'relative', borderRadius: '26px 26px 0 0', background: dark ? '#10161F' : '#FAFBFE',
        padding: '10px 20px 22px', maxHeight: '94%', display: 'flex', flexDirection: 'column',
        boxShadow: '0 -24px 64px rgba(0,0,0,0.45)',
      })}>
        <div style={{ width: 40, height: 4.5, borderRadius: 99, background: T.track, margin: '0 auto 14px', flexShrink: 0 }}></div>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, flexShrink: 0 }}>
          <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>{title}</div>
          <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 30, height: 30, borderRadius: 99, border: `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 13, color: T.sub }}>✕</div>
        </div>
        {children}
      </div>
    </div>
  );
}

// ---------- Режим тренировок (ModeSwitchPopup) ----------
function BModeSwitch({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const modes = [
    { g: 'AI', name: 'AI-тренер', desc: 'Бесплатно · отвечает мгновенно · 24/7', cur: true, grad: true },
    { g: 'СК', name: 'Живой тренер', desc: 'Персональный план · человеческий подход · от 4 500 ₽' },
    { g: '✎', name: 'Сам', desc: 'Полный контроль над планом — без подсказок' },
  ];
  return (
    <BSheet T={T} dark={dark} title="Режим тренировок">
      <div style={{ fontSize: 12.5, color: T.sub, lineHeight: 1.5, marginBottom: 14 }}>
        План, история и прогресс сохраняются при смене режима.
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
        {modes.map((m, i) => (
          <div key={i} className="r3b-card r3b-hover" onClick={() => { if (i === 1) prNav('trainers'); }} style={bCard(T, {
            padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 13, cursor: 'pointer',
            border: m.cur ? `1.5px solid ${T.accent}` : `1px solid ${T.cardBorder}`,
            background: m.cur ? T.card2 : T.card, boxShadow: m.cur ? T.glow : 'none',
          })}>
            <div style={{ width: 44, height: 44, borderRadius: 99, flexShrink: 0, background: m.grad ? B_GRAD(T) : T.card2, border: m.grad ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: m.grad ? '#fff' : T.ink }}>{m.g}</div>
            <div style={{ flex: 1 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 14.5, fontWeight: 700, color: T.ink }}>{m.name}</span>
                {m.cur && <span style={{ ...bLabel(T, { fontSize: 8, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '3px 8px' }}>текущий</span>}
              </div>
              <div style={{ fontSize: 11.5, color: T.sub, marginTop: 3 }}>{m.desc}</div>
            </div>
            {!m.cur && R3Icon.arrow(T.sub, 15)}
          </div>
        ))}
      </div>
      <div className="r3b-card" style={bCard(T, { padding: '11px 14px', marginTop: 12, background: T.card2, display: 'flex', gap: 10, alignItems: 'flex-start' })}>
        <div style={{ width: 7, height: 7, borderRadius: 99, background: T.accent, marginTop: 4, flexShrink: 0 }}></div>
        <div style={{ fontSize: 11.5, color: T.sub, lineHeight: 1.5 }}>
          Переход к живому тренеру: выбери тренера в каталоге и отправь заявку — после подтверждения план перейдёт под его управление.
        </div>
      </div>
    </BSheet>
  );
}

// ---------- Запись результата (ResultModal) ----------
function BResultModal({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const seg = (label, value, w = '1fr') => (
    <div>
      <div style={bLabel(T, { fontSize: 8, marginBottom: 4 })}>{label}</div>
      <div className="r3b-card" style={bCard(T, { padding: '9px 12px', background: T.card2, fontSize: 13.5, fontWeight: 700, color: T.ink, fontFamily: B_DISP })}>{value}</div>
    </div>
  );
  return (
    <BSheet T={T} dark={dark} title="Записать результат · Чт 11">
      {/* план-подсказка */}
      <div className="r3b-card" style={bCard(T, { padding: '10px 14px', background: T.card2, display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 })}>
        <div style={{ width: 3, alignSelf: 'stretch', borderRadius: 3, background: B_GRAD(T) }}></div>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>План: Темповый 10 км</div>
          <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1 }}>2 разминка · 6 темп 4:30–4:40 · 2 заминка</div>
        </div>
        <div style={{ ...bLabel(T, { fontSize: 8, color: T.good }), border: `1px solid ${T.good}`, borderRadius: 99, padding: '4px 9px' }}>из Garmin</div>
      </div>
      {/* тип */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 14, flexWrap: 'wrap' }}>
        {['Бег', 'Интервалы', 'Фартлек', 'ОФП', 'СБУ'].map((x, i) => (
          <div key={i} className="r3b-btn" style={{ fontSize: 11.5, fontWeight: 700, padding: '7px 14px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : T.card, color: i === 0 ? '#fff' : T.sub, border: i === 0 ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
        ))}
      </div>
      {/* блок бега: дистанция/время/темп/пульс — авторасчёт */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 9 }}>
        {seg('Дистанция, км', '10,2')}
        {seg('Время, чч:мм:сс', '46:51')}
        {seg('Темп /км · авто', '4:35')}
        {seg('Пульс ср.', '158')}
      </div>
      <div style={{ ...bLabel(T, { fontSize: 8.5 }), margin: '14px 0 7px' }}>Самочувствие</div>
      <div style={{ display: 'flex', gap: 6 }}>
        {['Легко', 'Норм', 'Тяжело', 'На пределе'].map((x, i) => (
          <div key={i} className="r3b-btn" style={{ flex: 1, fontSize: 11.5, fontWeight: 700, padding: '9px 0', borderRadius: 12, textAlign: 'center', background: i === 1 ? B_GRAD(T) : T.card, color: i === 1 ? '#fff' : T.sub, border: i === 1 ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
        ))}
      </div>
      <div style={{ marginTop: 12 }}>
        <div style={bLabel(T, { fontSize: 8, marginBottom: 4 })}>Заметки</div>
        <div className="r3b-card" style={bCard(T, { padding: '11px 13px', background: T.card2, fontSize: 12.5, color: T.sub, minHeight: 52 })}>Седьмой км ушёл быстрее цели, ветер в спину…</div>
      </div>
      <div style={{ display: 'flex', gap: 9, marginTop: 16 }}>
        <div className="r3b-btn" onClick={() => prNav('back')} style={{ flex: 1, background: B_GRAD(T), color: '#fff', borderRadius: 13, padding: '13px 16px', fontSize: 13.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>Сохранить результат</div>
        <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.sub, borderRadius: 13, padding: '13px 16px', fontSize: 13, fontWeight: 600 }}>+ блок</div>
      </div>
    </BSheet>
  );
}

// ---------- Опрос готовности (readiness) ----------
function BReadiness({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <BSheet T={T} dark={dark} title="Как ты сегодня?">
      <div style={{ fontSize: 12.5, color: T.sub, lineHeight: 1.5, marginBottom: 16 }}>
        30 секунд перед пересчётом плана — AI учтёт самочувствие.
      </div>
      <div style={bLabel(T, { fontSize: 8.5, marginBottom: 8 })}>Боль или дискомфорт · 0–10</div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 6 }}>
        <div style={{ flex: 1, height: 10, borderRadius: 99, background: T.track, position: 'relative' }}>
          <div style={{ width: '20%', height: '100%', borderRadius: 99, background: B_GRAD(T) }}></div>
          <div style={{ position: 'absolute', left: 'calc(20% - 11px)', top: -6, width: 22, height: 22, borderRadius: 99, background: '#fff', boxShadow: '0 2px 8px rgba(0,0,0,0.35)', border: `2px solid ${T.accent}` }}></div>
        </div>
        <div style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: T.ink, width: 26, textAlign: 'right' }}>2</div>
      </div>
      <div style={{ fontSize: 11, color: T.sub, marginBottom: 16 }}>Лёгкая жёсткость в икрах после длительной</div>
      {[
        ['Стало хуже со вчера?', ['Да', 'Нет'], 1],
        ['Менял технику или обувь?', ['Да', 'Нет'], 1],
      ].map(([q, opts, sel], i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 0', borderTop: `1px solid ${T.line}` }}>
          <div style={{ flex: 1, fontSize: 13, fontWeight: 600, color: T.ink }}>{q}</div>
          <div style={{ display: 'flex', gap: 5 }}>
            {opts.map((o, j) => (
              <div key={j} className="r3b-btn" style={{ fontSize: 11.5, fontWeight: 700, padding: '7px 16px', borderRadius: 99, background: j === sel ? B_GRAD(T) : T.card, color: j === sel ? '#fff' : T.sub, border: j === sel ? 'none' : `1px solid ${T.cardBorder}` }}>{o}</div>
            ))}
          </div>
        </div>
      ))}
      <div style={{ marginTop: 10 }}>
        <div style={bLabel(T, { fontSize: 8, marginBottom: 4 })}>Что ещё важно знать</div>
        <div className="r3b-card" style={bCard(T, { padding: '11px 13px', background: T.card2, fontSize: 12.5, color: T.sub, minHeight: 44 })}>Плохо спал две ночи, командировка…</div>
      </div>
      <div className="r3b-btn" onClick={() => prNav('back')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 13, padding: '13px 16px', fontSize: 13.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow, marginTop: 16 }}>
        Пересчитать план с учётом ответов →
      </div>
    </BSheet>
  );
}

// ---------- Действия с планом (PlanActionsMenuV3) ----------
function BPlanActions({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const items = [
    { ic: 'plus', t: 'Добавить тренировку', s: 'Вручную в любой день' },
    { ic: 'run', t: 'Пересчитать план', s: 'AI скорректирует с учётом готовности', hot: true },
    { ic: 'cal', t: 'Следующий план', s: 'Цикл заканчивается — задай новые цели' },
    { ic: 'stats', t: 'Сменить цель', s: 'Дистанция, дата или целевое время' },
    { ic: 'dots', t: 'Очистить план', s: 'Удалить все будущие тренировки', danger: true },
  ];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', position: 'relative', display: 'flex', flexDirection: 'column' }}>
      <BStyle T={T} /><BDefs />
      {/* фон: шапка календаря */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px 10px', flexShrink: 0, opacity: 0.45 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Июнь</div>
        <div style={bLabel(T, { fontSize: 9 })}>неделя 24</div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-btn" style={{ width: 34, height: 34, borderRadius: 12, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: T.glow }}>{R3Icon.dots('#fff', 16)}</div>
      </div>
      <div style={{ position: 'absolute', inset: 0, background: dark ? 'rgba(4,6,10,0.55)' : 'rgba(14,20,32,0.3)', backdropFilter: 'blur(4px)' }}></div>
      {/* меню */}
      <div className="r3b-card" style={bCard(T, { position: 'absolute', top: 58, right: 16, width: 300, background: dark ? '#10161F' : '#FAFBFE', padding: 8, boxShadow: '0 24px 64px rgba(0,0,0,0.45)' })}>
        {items.map((it, i) => (
          <div key={i} className="r3b-btn" onClick={() => { if (it.t === 'Пересчитать план') prNav('readiness'); else if (it.t === 'Добавить тренировку') prNav('result'); }} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 12px', borderRadius: 13, background: it.hot ? T.card2 : 'transparent', border: it.hot ? `1px solid ${T.accent}` : '1px solid transparent', marginTop: i ? 3 : 0 }}>
            <div style={{ width: 34, height: 34, borderRadius: 11, flexShrink: 0, background: it.danger ? 'transparent' : it.hot ? B_GRAD(T) : T.card2, border: it.danger ? `1px solid ${T.bad}` : it.hot ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              {R3Icon[it.ic](it.danger ? T.bad : it.hot ? '#fff' : T.sub, 15)}
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 13, fontWeight: 700, color: it.danger ? T.bad : T.ink }}>{it.t}</div>
              <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1 }}>{it.s}</div>
            </div>
          </div>
        ))}
      </div>
      {/* подтверждение пересчёта */}
      <div className="r3b-card" style={bCard(T, { position: 'absolute', left: 16, right: 16, bottom: 18, background: dark ? '#10161F' : '#FAFBFE', padding: '16px 18px', boxShadow: '0 24px 64px rgba(0,0,0,0.45)' })}>
        <div style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: T.ink, marginBottom: 6 }}>Пересчитать план?</div>
        <div style={{ fontSize: 12, color: T.sub, lineHeight: 1.5, marginBottom: 10 }}>
          AI пересоберёт оставшиеся 10 недель с учётом выполнения и опроса готовности. Прошедшие тренировки не изменятся.
        </div>
        <div style={bLabel(T, { fontSize: 8, marginBottom: 4 })}>Причина (увидит AI)</div>
        <div className="r3b-card" style={bCard(T, { padding: '10px 13px', background: T.card2, fontSize: 12.5, color: T.sub, marginBottom: 12 })}>Пропустил неделю из-за болезни…</div>
        <div style={{ display: 'flex', gap: 8 }}>
          <div className="r3b-btn" onClick={() => prNav('readiness')} style={{ flex: 1, background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '11px 14px', fontSize: 13, fontWeight: 700, textAlign: 'center' }}>Пересчитать</div>
          <div className="r3b-btn" onClick={() => prNav('back')} style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '11px 16px', fontSize: 13, fontWeight: 600 }}>Отмена</div>
        </div>
      </div>
    </div>
  );
}

// ---------- Кастомизатор дашборда (DashCustomizerV3) ----------
function BDashCustomizer({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const widgets = [
    ['Тренировка дня', 'герой экрана — всегда включён', true, true],
    ['Готовность (TSB)', 'кольцо + график формы', true],
    ['Неделя', 'точки-статусы и объём', true],
    ['Цель и обратный отсчёт', 'прогресс к марафону', true],
    ['VDOT и тренды', 'спарклайны 8 недель', true],
    ['Личные рекорды', '5К / 10К / 21,1 / 42,2', false],
    ['Зоны темпа', 'из VDOT, обновляются сами', false],
    ['Прогноз результата', 'на целевую дистанцию', true],
  ];
  return (
    <BSheet T={T} dark={dark} title="Настроить дашборд">
      <div style={{ fontSize: 12.5, color: T.sub, lineHeight: 1.5, marginBottom: 14 }}>
        Включай только то, на что смотришь. Порядок — перетаскиванием.
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
        {widgets.map(([t, s, on, locked], i) => (
          <div key={i} className="r3b-card" style={bCard(T, { padding: '11px 14px', display: 'flex', alignItems: 'center', gap: 12, opacity: on ? 1 : 0.6 })}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 3, flexShrink: 0, cursor: 'grab' }}>
              {[0, 1, 2].map((j) => <div key={j} style={{ width: 14, height: 2, borderRadius: 2, background: T.track }}></div>)}
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{t}</div>
              <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1 }}>{s}</div>
            </div>
            {locked
              ? <div style={bLabel(T, { fontSize: 8 })}>закреплён</div>
              : <BToggle T={T} on={!!on} />}
          </div>
        ))}
      </div>
      <div className="r3b-btn" onClick={() => prNav('back')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 13, padding: '13px 16px', fontSize: 13.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow, marginTop: 14 }}>
        Готово
      </div>
    </BSheet>
  );
}

Object.assign(window, { BModeSwitch, BResultModal, BReadiness, BPlanActions, BDashCustomizer });

// ============================================================
// v3B · Чат с AI-тренером — tool-calling, result-карточки,
// quick-replies. Мобайл + десктоп.
// ============================================================

function BToolCard({ T, running }) {
  return (
    <div className="r3b-card" style={bCard(T, { padding: '11px 14px', background: T.card2, border: `1px solid ${running ? T.accent : T.cardBorder}` })}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
        {running ? (
          <div style={{ display: 'flex', gap: 2.5, alignItems: 'flex-end', height: 14 }}>
            {[0, 1, 2].map((i) => (
              <div key={i} style={{ width: 3.5, height: 14, borderRadius: 2, background: T.accent, animation: `r3b-livebar 1s ${i * 0.18}s infinite ease-in-out`, transformOrigin: 'bottom' }}></div>
            ))}
          </div>
        ) : R3Icon.check(T.good, 15)}
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: T.ink }}>
            {running ? 'Правлю план…' : 'План обновлён'}
          </div>
          <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1 }}>
            {running ? 'update_plan · перенос длительной' : 'Сб 22 км → Вс · объём недели сохранён'}
          </div>
        </div>
        {!running && <div className="r3b-btn" onClick={() => prNav('cal')} style={{ ...bLabel(T, { fontSize: 8.5, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '4px 10px' }}>открыть план</div>}
      </div>
    </div>
  );
}

function BMsg({ T, who, children, time }) {
  const ai = who === 'ai';
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: ai ? 'flex-start' : 'flex-end', gap: 4 }}>
      <div style={{
        maxWidth: '82%', padding: '11px 15px', fontSize: 13.5, lineHeight: 1.5,
        borderRadius: ai ? '4px 18px 18px 18px' : '18px 4px 18px 18px',
        background: ai ? T.card2 : B_GRAD(T),
        border: ai ? `1px solid ${T.cardBorder}` : 'none',
        color: ai ? T.ink : '#fff',
        backdropFilter: 'blur(10px)',
      }}>{children}</div>
      {time && <div style={bLabel(T, { fontSize: 8, padding: '0 6px' })}>{time}</div>}
    </div>
  );
}

function BComposer({ T }) {
  return (
    <div className="r3b-card" style={bCard(T, { borderRadius: 22, padding: '7px 8px 7px 10px', display: 'flex', alignItems: 'center', gap: 9, background: T.card2 })}>
      <div className="r3b-btn" title="Прикрепить тренировку или файл" style={{ width: 30, height: 30, borderRadius: 99, border: `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
        {R3Icon.plus(T.sub, 14)}
      </div>
      <div style={{ flex: 1, fontSize: 13.5, color: T.sub }}>Спросить AI-тренера…</div>
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke={T.sub} strokeWidth="1.8" strokeLinecap="round"><rect x="9" y="2.5" width="6" height="11.5" rx="3"></rect><path d="M5 11a7 7 0 0 0 14 0M12 18v3.5"></path></svg>
      <div className="r3b-btn" style={{ width: 36, height: 36, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: T.glow }}>
        {R3Icon.arrow('#fff', 16)}
      </div>
    </div>
  );
}

function BChatThread({ T, compact = false }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: compact ? 10 : 12, justifyContent: 'flex-end', flex: 1, minHeight: 0 }}>
      <BMsg T={T} who="ai" time="08:02">
        Доброе утро! Вчерашний темповый — отличный: 6 км в коридоре 4:30–4:40, дрейф пульса 4%. Сегодня по плану отдых.
      </BMsg>
      <BMsg T={T} who="me" time="08:05">
        В субботу не смогу — уезжаю. Куда деть длительную 22 км?
      </BMsg>
      <BToolCard T={T} running={false} />
      <BMsg T={T} who="ai" time="08:05">
        Перенёс длительную на воскресенье, а восстановительные 6 км — на субботнее утро, до отъезда. Объём недели сохранён: 55 км. Если в воскресенье будет жарко — стартуй до 9:00.
      </BMsg>
      <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
        {['Отлично, спасибо', 'А если совсем пропущу?', 'Покажи неделю'].map((x, i) => (
          <div key={i} className="r3b-btn" style={{ fontSize: 12, fontWeight: 600, padding: '8px 14px', borderRadius: 99, border: `1px solid ${T.accent}`, color: T.accent, background: 'transparent' }}>{x}</div>
        ))}
      </div>
    </div>
  );
}

// ---------- Чат · мобайл ----------
function BChatMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '14px 18px 10px', flexShrink: 0 }}>
        <div style={{ position: 'relative' }}>
          <div style={{ width: 38, height: 38, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 11, fontWeight: 700, color: '#fff' }}>AI</div>
          <div style={{ position: 'absolute', right: -1, bottom: -1, width: 11, height: 11, borderRadius: 99, background: T.good, border: `2.5px solid ${T.bgFlat}` }}></div>
        </div>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 14.5, fontWeight: 700, color: T.ink }}>AI-тренер</div>
          <div style={bLabel(T, { fontSize: 8.5, color: T.good })}>онлайн · знает твой план</div>
        </div>
        {R3Icon.dots(T.sub, 18)}
      </div>
      {/* директории чатов: AI + тренер */}
      <div style={{ display: 'flex', gap: 6, padding: '0 16px 10px', borderBottom: `1px solid ${T.line}`, flexShrink: 0 }}>
        {[['AI-тренер', true], ['Сергей Климов · 2', false]].map(([x, act], i) => (
          <div key={i} className="r3b-btn" style={{ fontSize: 11.5, fontWeight: 700, padding: '7px 14px', borderRadius: 99, background: act ? B_GRAD(T) : T.card, color: act ? '#fff' : T.sub, border: act ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
        ))}
      </div>
      <div style={{ flex: 1, minHeight: 0, padding: '14px 16px', display: 'flex', flexDirection: 'column' }}>
        <div style={{ alignSelf: 'center', ...bLabel(T, { fontSize: 8.5 }), marginBottom: 10 }}>сегодня</div>
        <BChatThread T={T} compact />
      </div>
      <div style={{ padding: '8px 14px 0', flexShrink: 0 }}>
        <BComposer T={T} />
      </div>
      <div style={{ height: 8, flexShrink: 0 }}></div>
      <BNav T={T} active="chat" />
    </div>
  );
}

// ---------- Чат · десктоп ----------
function BChatDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const chats = [
    { name: 'AI-тренер', ini: 'AI', last: 'Перенёс длительную на воскресенье…', t: '08:05', active: true, grad: true },
    { name: 'Сергей Климов', ini: 'СК', last: 'Посмотрел твой темповый — солидно!', t: 'вт', active: false },
  ];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 26, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '5px 6px' }), display: 'flex', gap: 2 }}>
          {['Сегодня', 'План', 'Данные', 'AI-тренер'].map((x, i) => (
            <div key={i} onClick={() => prNav(['home', 'cal', 'stats', 'chat'][i])} style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 99, background: i === 3 ? B_GRAD(T) : 'transparent', color: i === 3 ? '#fff' : T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
        {R3Icon.bell(T.ink, 19)}
        <div style={{ width: 36, height: 36, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 12, fontWeight: 700, color: '#fff' }}>И</div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '320px 1fr 320px', gap: 16, padding: '2px 36px 24px' }}>
        {/* список чатов */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', overflow: 'hidden' })}>
          <div style={{ padding: '16px 18px 12px', borderBottom: `1px solid ${T.line}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div style={bLabel(T, { fontSize: 9 })}>Чаты</div>
            {R3Icon.search(T.sub, 15)}
          </div>
          {chats.map((c, i) => (
            <div key={i} style={{ display: 'flex', gap: 11, padding: '13px 18px', alignItems: 'center', background: c.active ? T.card2 : 'transparent', borderLeft: c.active ? `3px solid ${T.accent}` : '3px solid transparent', cursor: 'pointer' }}>
              <div style={{ width: 40, height: 40, borderRadius: 99, flexShrink: 0, background: c.grad ? B_GRAD(T) : T.card2, border: c.grad ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 11, fontWeight: 700, color: c.grad ? '#fff' : T.ink }}>{c.ini}</div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <span style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>{c.name}</span>
                  <span style={bLabel(T, { fontSize: 8 })}>{c.t}</span>
                </div>
                <div style={{ fontSize: 11.5, color: T.sub, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', marginTop: 2 }}>{c.last}</div>
              </div>
            </div>
          ))}
          <div style={{ flex: 1 }}></div>
          <div style={{ padding: 16 }}>
            <div className="r3b-card" style={bCard(T, { padding: '12px 14px', background: T.card2 })}>
              <div style={bLabel(T, { fontSize: 8.5, color: T.accent, marginBottom: 6 })}>Подсказки</div>
              {['Сделай неделю легче', 'Оцени готовность к марафону', 'Почему болят икры?'].map((x, i) => (
                <div key={i} className="r3b-btn" style={{ fontSize: 12, fontWeight: 600, color: T.ink, padding: '7px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>{x}</div>
              ))}
            </div>
          </div>
        </div>

        {/* диалог */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, overflow: 'hidden' })}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '13px 22px', borderBottom: `1px solid ${T.line}`, flexShrink: 0 }}>
            <div style={{ width: 34, height: 34, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 10, fontWeight: 700, color: '#fff' }}>AI</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 14, fontWeight: 700, color: T.ink }}>AI-тренер</div>
              <div style={bLabel(T, { fontSize: 8.5, color: T.good })}>онлайн · имеет доступ к плану и тренировкам</div>
            </div>
            <BLive T={T} label="план обновлён" />
          </div>
          <div style={{ flex: 1, minHeight: 0, padding: '16px 22px', display: 'flex', flexDirection: 'column' }}>
            <BChatThread T={T} />
          </div>
          <div style={{ padding: '6px 18px 16px', flexShrink: 0 }}>
            <BComposer T={T} />
          </div>
        </div>

        {/* контекст */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '16px 20px' })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Контекст AI</div>
            {[['Цель', 'Марафон · 3:29:59'], ['Фаза', 'Build · нед 4/5'], ['Готовность', '82 / 100'], ['Объём недели', '17 / 55 км']].map(([l, v], i) => (
              <div key={i} style={{ display: 'flex', justifyContent: 'space-between', padding: '7px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>
                <span style={{ fontSize: 12, color: T.sub, fontWeight: 600 }}>{l}</span>
                <span style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>{v}</span>
              </div>
            ))}
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '16px 20px', flex: 1 })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Изменения плана · сегодня</div>
            <BToolCard T={T} running={false} />
            <div style={{ marginTop: 10 }}>
              <BToolCard T={T} running={true} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BChatMobile, BChatDesktop, BToolCard });

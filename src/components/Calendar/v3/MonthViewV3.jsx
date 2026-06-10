/* MonthViewV3 — месячный вид (порт CalMonthMobile/CalMonthDesktop).
   Мобайл: мини volume-rail + сетка + легенда + bottom-sheet.
   Десктоп: сетка + side-rail (объём + фазы) + embedded sheet. */
import React, { useMemo, useState } from 'react';
import CalHeaderV3 from './CalHeaderV3';
import VolumeRailV3 from './VolumeRailV3';
import DaySheetV3 from './DaySheetV3';
import PhasesListV3 from './PhasesListV3';
import useIsMobile from './useIsMobile';
import {
  buildMonthMatrix, formatMonthTitle, buildVolumeWindow, getMondayOfToday,
  typeColorVar, typeLabel, todayYmd,
} from './calV3';

const WD_SHORT = ['ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ', 'ВС'];
const LEGEND = [
  ['easy', 'Лёгкий'], ['tempo', 'Темп'], ['interval', 'Интервалы'],
  ['long', 'Длительный'], ['race', 'Гонка'], ['sbu', 'СБУ'],
];

function MonthCellMobile({ day, onClick }) {
  const isRest = day.type === 'rest' || day.type === 'free';
  const isDone = day.status === 'done';
  return (
    <button
      type="button"
      className={`calv3-mcell${day.isToday ? ' is-today' : ''}${isDone ? ' is-done' : ''}`}
      onClick={onClick}
    >
      <span className="calv3-mcell__d">{day.d}</span>
      {isDone
        ? <span className="calv3-mcell__check">✓</span>
        : <span className="calv3-mcell__dot" style={isRest ? undefined : { background: typeColorVar(day.type) }} data-rest={isRest ? '1' : undefined} />}
    </button>
  );
}

function MonthCellDesktop({ day, active, onClick }) {
  const isRest = day.type === 'rest' || day.type === 'free';
  const isDone = day.status === 'done';
  return (
    <button
      type="button"
      className={`calv3-mcell-d${day.isToday ? ' is-today' : ''}${active ? ' is-active' : ''}${isDone ? ' is-done' : ''}`}
      onClick={onClick}
    >
      <div className="calv3-mcell-d__top">
        <span className="calv3-mcell-d__d">{day.d}</span>
        {isDone && <span className="calv3-mcell-d__check">✓</span>}
      </div>
      {!isRest && (
        <>
          <span className="calv3-mcell-d__accent" style={{ background: typeColorVar(day.type) }} />
          <div className="calv3-mcell-d__label">{typeLabel(day.type)}</div>
          {day.km > 0 && <div className="calv3-mcell-d__km">{day.km} км</div>}
        </>
      )}
      {isRest && <div className="calv3-mcell-d__rest">Отдых</div>}
    </button>
  );
}

export default function MonthViewV3({
  plan,
  data,
  canEdit = false,
  viewMode = 'full',
  onViewMode,
  lockView = false,
  hideSeg = false,
  initialDate = null,
  api = null,
  viewContext = null,
  onEditDay,
  onMarkDone,
  onEditResult,
  onDeleteResult,
  onAddTraining,
  planMenu = null,
}) {
  const isMobile = useIsMobile();
  const base = initialDate ? new Date(initialDate + 'T00:00:00') : new Date();
  const [cursor, setCursor] = useState(() => ({ year: base.getFullYear(), month: base.getMonth() }));
  const [sheetDayDate, setSheetDayDate] = useState(null); // храним дату, модель выводим из свежих cells
  const [selectedDate, setSelectedDate] = useState(null);

  const cells = useMemo(() => buildMonthMatrix(plan, cursor.year, cursor.month, data), [plan, cursor, data]);
  const volume = useMemo(() => buildVolumeWindow(plan, getMondayOfToday()), [plan]);
  // Модель дня для шторки — из свежих cells (пересобираются при loadPlan), иначе попап залипал бы со старым планом.
  const sheetDay = sheetDayDate ? (cells.find((c) => c && c.date === sheetDayDate) || null) : null;

  const goPrev = () => { setCursor((c) => { const d = new Date(c.year, c.month - 1, 1); return { year: d.getFullYear(), month: d.getMonth() }; }); };
  const goNext = () => { setCursor((c) => { const d = new Date(c.year, c.month + 1, 1); return { year: d.getFullYear(), month: d.getMonth() }; }); };
  const goToday = () => { const n = new Date(); setCursor({ year: n.getFullYear(), month: n.getMonth() }); };

  const sheetCallbacks = {
    canEdit,
    api,
    viewContext,
    onEdit: (planDay, d) => onEditDay?.(planDay, d),
    onMarkDone: (d) => onMarkDone?.(d),
    onEditResult,
    onDeleteResult,
    // закрываем шторку перед открытием модалки добавления
    onAddTraining: onAddTraining ? (date) => { setSheetDayDate(null); onAddTraining(date); } : undefined,
  };

  // Мобайл: ⋯-меню плана уезжает в шапку (как в неделе); «+ Тренировка» — из попапа дня.
  const header = (
    <CalHeaderV3
      title={formatMonthTitle(cursor.year, cursor.month)}
      onPrev={goPrev}
      onNext={goNext}
      viewMode={viewMode}
      onViewMode={onViewMode}
      lockView={lockView}
      hideSeg={hideSeg}
      menu={planMenu}
    />
  );

  // ── MOBILE ──────────────────────────────────────────────────────────
  if (isMobile) {
    return (
      <div className="calv3-month calv3-month--mobile">
        {header}
        {volume.items.length > 0 && (
          <div className="calv3-card" style={{ marginBottom: 14 }}>
            <div className="calv3-card-label">ОБЪЁМ ПО НЕДЕЛЯМ</div>
            <VolumeRailV3 items={volume.items} max={volume.max} />
          </div>
        )}
        <div className="calv3-mgrid-head">
          {WD_SHORT.map((d) => <div key={d} className="calv3-mgrid-head__c">{d}</div>)}
        </div>
        <div className="calv3-mgrid">
          {cells.map((c, i) => c === null
            ? <div key={`e${i}`} />
            : <MonthCellMobile key={c.date} day={c} onClick={() => setSheetDayDate(c.date)} />)}
        </div>
        <div className="calv3-legend">
          {LEGEND.map(([t, l]) => (
            <div key={t} className="calv3-legend__item">
              <span className="calv3-legend__dot" style={{ background: typeColorVar(t) }} />
              <span>{l}</span>
            </div>
          ))}
        </div>
        {sheetDay && <DaySheetV3 day={sheetDay} onClose={() => setSheetDayDate(null)} {...sheetCallbacks} />}
      </div>
    );
  }

  // ── DESKTOP ─────────────────────────────────────────────────────────
  const selectedDay = selectedDate ? cells.find((c) => c && c.date === selectedDate) : null;
  const todayMonth = (() => { const n = new Date(); return n.getFullYear() === cursor.year && n.getMonth() === cursor.month; })();
  return (
    <div className="calv3-month calv3-month--desktop">
      <div className="calv3-desk-row">
        <button type="button" className="calv3-nav-btn" onClick={goPrev} aria-label="Предыдущий месяц">‹</button>
        <div className="calv3-desk-title">{formatMonthTitle(cursor.year, cursor.month)}</div>
        <button type="button" className="calv3-nav-btn" onClick={goNext} aria-label="Следующий месяц">›</button>
        <div className="calv3-desk-row-spacer" />
        {!todayMonth && (
          <button type="button" className="calv3-ghost-btn" onClick={goToday}>Сегодня</button>
        )}
        {canEdit && onAddTraining && (
          <button type="button" className="calv3-primary-btn" onClick={() => onAddTraining(selectedDate || todayYmd())}>+ Тренировка</button>
        )}
        {planMenu}
      </div>
      <div className="calv3-desk-body">
        <div className="calv3-desk-main">
          <div className="calv3-mgrid-head calv3-mgrid-head--desk">
            {['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].map((d) => <div key={d} className="calv3-mgrid-head__c">{d}</div>)}
          </div>
          <div className="calv3-mgrid calv3-mgrid--desk">
            {cells.map((c, i) => c === null
              ? <div key={`e${i}`} className="calv3-mcell-d calv3-mcell-d--empty" />
              : <MonthCellDesktop key={c.date} day={c} active={selectedDate === c.date} onClick={() => setSelectedDate(c.date)} />)}
          </div>
        </div>
        <aside className="calv3-desk-side">
          {volume.items.length > 0 && (
            <div className="calv3-card">
              <div className="calv3-card-label">ОБЪЁМ · 4 НЕДЕЛИ</div>
              <VolumeRailV3 items={volume.items} max={volume.max} horizontal />
            </div>
          )}
          {volume.items.length > 0 && (
            <div style={{ marginTop: 12 }}>
              <PhasesListV3 items={volume.items} />
            </div>
          )}
          {selectedDay && (
            <div className="calv3-card" style={{ marginTop: 12, padding: 0, background: 'transparent', border: 'none', boxShadow: 'none' }}>
              <DaySheetV3 day={selectedDay} embedded {...sheetCallbacks} />
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}

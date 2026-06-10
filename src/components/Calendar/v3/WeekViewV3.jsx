/* WeekViewV3 — недельный вид (порт CalWeekMobile/CalWeekDesktop).
   Мобайл: список DayRow + bottom-sheet. Десктоп: 7 карточек + embedded sheet + side-rail.
   Логика данных — через calV3 (buildWeekDays/getWeekForStartDate/...). */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import CalHeaderV3 from './CalHeaderV3';
import PhaseRibbonV3 from './PhaseRibbonV3';
import VolumeRailV3 from './VolumeRailV3';
import DaySheetV3 from './DaySheetV3';
import WeekNotesV3 from './WeekNotesV3';
import CopyWeekV3 from './CopyWeekV3';
import PhasesListV3 from './PhasesListV3';
import useIsMobile from './useIsMobile';
import useWorkoutRefreshStore from '../../../stores/useWorkoutRefreshStore';
import { prefetchDays } from './dayCache';
import {
  buildWeekDays, buildDayModel, getWeekForStartDate, getMondayOfToday, getMondayForDate,
  addDays, todayYmd, formatWeekRange, deriveCurrentPhase, buildVolumeWindow,
  typeColorVar, PHASES,
} from './calV3';

function DayRow({ day, onClick }) {
  const isRest = day.type === 'rest' || day.type === 'free';
  const isDone = day.status === 'done';
  return (
    <button
      type="button"
      className={`calv3-dayrow${day.isToday ? ' is-today' : ''}${isRest ? ' is-rest' : ''}`}
      onClick={onClick}
    >
      <div className="calv3-dayrow__date">
        <div className="calv3-dayrow__dow">{day.dow}</div>
        <div className="calv3-dayrow__d">{day.d}</div>
      </div>
      <span className="calv3-dayrow__accent" style={{ background: typeColorVar(day.type), opacity: isRest ? 0.4 : 1 }} />
      <div className="calv3-dayrow__body">
        <div className="calv3-dayrow__title">{day.title}</div>
        {day.km > 0 && (
          <div className="calv3-dayrow__meta">{day.km} км{day.pace ? ` · ${day.pace}` : (day.paceSuggested ? ` · ≈${day.paceSuggested}` : '')}</div>
        )}
      </div>
      {day.key && !isDone && <span className="calv3-dayrow__key" />}
      {isDone
        ? <span className="calv3-dayrow__check">✓</span>
        : !isRest && <span className="calv3-dayrow__chev">›</span>}
    </button>
  );
}

function DayCard({ day, active, onClick }) {
  const isRest = day.type === 'rest' || day.type === 'free';
  return (
    <button
      type="button"
      className={`calv3-daycard${active ? ' is-active' : ''}${day.isToday ? ' is-today' : ''}`}
      onClick={onClick}
    >
      <div className="calv3-daycard__top">
        <span className="calv3-daycard__dow">{day.dow}</span>
        <span className="calv3-daycard__d">{day.d}</span>
      </div>
      <span className="calv3-daycard__accent" style={{ background: typeColorVar(day.type), opacity: isRest ? 0.3 : 1 }} />
      <div className="calv3-daycard__body">
        <div className="calv3-daycard__title">{day.title}</div>
        {day.km > 0 && <div className="calv3-daycard__meta">{day.km} км{day.pace ? ` · ${day.pace}` : (day.paceSuggested ? ` · ≈${day.paceSuggested}` : '')}</div>}
      </div>
      <div className="calv3-daycard__foot">
        {day.status === 'done' && <span className="calv3-daycard__done">✓ Выполнено</span>}
        {day.key && <span className="calv3-tag">КЛЮЧ</span>}
      </div>
    </button>
  );
}

export default function WeekViewV3({
  plan,
  data,
  canEdit = false,
  viewMode = 'week',
  onViewMode,
  lockView = false,
  hideSeg = false,
  initialDate = null,
  initialDateKey = null,
  api = null,
  viewContext = null,
  onEditDay,
  onMarkDone,
  onEditResult,
  onDeleteResult,
  onAddTraining,
  onTrainingChanged,
  planMenu = null,
}) {
  const isMobile = useIsMobile();
  const [weekStart, setWeekStart] = useState(() => (initialDate ? getMondayForDate(initialDate) : getMondayOfToday()));
  const [selectedDate, setSelectedDate] = useState(() => initialDate || todayYmd());
  const [sheetDayDate, setSheetDayDate] = useState(null); // mobile bottom sheet — храним дату, модель выводим из свежих days
  const containerRef = useRef(null);
  const swipe = useRef({ x: 0, y: 0 });
  const refreshVersion = useWorkoutRefreshStore((s) => s.version);

  // Снапим неделю/выбранный день ТОЛЬКО при переходе с дашборда (initialDate/initialDateKey).
  // НЕ завязываемся на plan: тихий перезагруз плана (после добавления тренировки и т.п.)
  // не должен сбрасывать выбранный пользователем день обратно на сегодня.
  useEffect(() => {
    const base = initialDate || todayYmd();
    setWeekStart(getMondayForDate(base));
    setSelectedDate(base);
  }, [initialDate, initialDateKey]);

  // переход с дашборда по дате — открыть день (на мобайле шторка), один раз на ключ навигации
  const navHandledRef = useRef(null);
  useEffect(() => {
    if (!initialDate || initialDateKey == null) return;
    if (navHandledRef.current === initialDateKey) return;
    navHandledRef.current = initialDateKey;
    if (isMobile) setSheetDayDate(initialDate);
  }, [initialDate, initialDateKey, isMobile, plan, data]);

  const week = useMemo(() => getWeekForStartDate(plan, weekStart), [plan, weekStart]);
  const days = useMemo(() => buildWeekDays(plan, week, data), [plan, week, data]);

  // Префетч всех дней недели в кэш (фоном) → открытие любого дня мгновенно, без лоадера.
  useEffect(() => {
    prefetchDays(api, days.map((d) => d.date), viewContext, refreshVersion);
  }, [api, days, viewContext, refreshVersion]);
  const phaseKey = useMemo(() => deriveCurrentPhase(plan, weekStart), [plan, weekStart]);
  const volume = useMemo(() => buildVolumeWindow(plan, weekStart), [plan, weekStart]);

  // Смотрим неделю ПОСЛЕ окончания плана (плана на неё нет) — показываем «План завершён»,
  // а не фейковые дни отдыха и устаревшее окно объёма.
  const planEnded = useMemo(() => {
    const ws = (plan?.weeks_data || []).filter((w) => w?.start_date);
    if (ws.length === 0) return false;
    const lastStart = ws.reduce((m, w) => (w.start_date > m ? w.start_date : m), ws[0].start_date);
    return weekStart > lastStart;
  }, [plan, weekStart]);

  const totalKm = days.reduce((s, d) => s + (d.km || 0), 0);
  const doneKm = days.filter((d) => d.status === 'done').reduce((s, d) => s + (d.km || 0), 0);
  const phaseLabel = phaseKey ? PHASES[phaseKey]?.label : null;
  const subtitle = totalKm > 0
    ? `${Math.round(doneKm)} из ${Math.round(totalKm)} км${phaseLabel ? ` · ${phaseLabel.toLowerCase()}` : ''}`
    : (phaseLabel ? `${phaseLabel} фаза` : '');

  const goPrev = () => { const s = addDays(weekStart, -7); setWeekStart(s); setSelectedDate(s); setSheetDayDate(null); };
  const goNext = () => { const s = addDays(weekStart, 7); setWeekStart(s); setSelectedDate(s); setSheetDayDate(null); };

  // swipe (mobile)
  useEffect(() => {
    const el = containerRef.current;
    if (!el || !isMobile) return undefined;
    const onStart = (e) => { swipe.current = { x: e.touches[0].clientX, y: e.touches[0].clientY }; };
    const onMove = (e) => {
      const dx = e.touches[0].clientX - swipe.current.x;
      const dy = e.touches[0].clientY - swipe.current.y;
      if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) e.preventDefault();
    };
    const onEnd = (e) => {
      const dx = e.changedTouches[0].clientX - swipe.current.x;
      const dy = e.changedTouches[0].clientY - swipe.current.y;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { dx > 0 ? goPrev() : goNext(); }
    };
    el.addEventListener('touchstart', onStart, { passive: true });
    el.addEventListener('touchmove', onMove, { passive: false });
    el.addEventListener('touchend', onEnd, { passive: true });
    return () => {
      el.removeEventListener('touchstart', onStart);
      el.removeEventListener('touchmove', onMove);
      el.removeEventListener('touchend', onEnd);
    };
  }, [isMobile, weekStart]);

  const selectedDay = days.find((d) => d.date === selectedDate) || days[0];
  // Модель дня для шторки выводим из свежих days (пересобираются при loadPlan),
  // иначе после правки тренировки в попапе осталась бы устаревшая модель плана.
  const sheetDay = sheetDayDate
    ? (days.find((d) => d.date === sheetDayDate) || buildDayModel(sheetDayDate, plan, data))
    : null;

  const sheetCallbacks = {
    canEdit,
    api,
    viewContext,
    onEdit: (planDay, d) => onEditDay?.(planDay, d),
    onMarkDone: (d) => onMarkDone?.(d),
    onEditResult,
    onDeleteResult,
    // закрываем шторку перед открытием модалки добавления (иначе под ней останется устаревший sheet)
    onAddTraining: onAddTraining ? (date) => { setSheetDayDate(null); onAddTraining(date); } : undefined,
  };

  // ── MOBILE ──────────────────────────────────────────────────────────
  if (isMobile) {
    return (
      <div className="calv3-week calv3-week--mobile" ref={containerRef}>
        {/* ⋯-меню (с «Копировать неделю» внутри) уехало в шапку, к стрелкам недель.
            «+ Тренировка» на мобиле убрана — добавление теперь из попапа дня. */}
        <CalHeaderV3
          title={formatWeekRange(weekStart)}
          subtitle={subtitle}
          onPrev={goPrev}
          onNext={goNext}
          viewMode={viewMode}
          onViewMode={onViewMode}
          lockView={lockView}
          hideSeg={hideSeg}
          menu={planMenu && canEdit && week.id && api
            ? React.cloneElement(planMenu, {
              copyWeekSlot: (
                <CopyWeekV3 api={api} weekId={week.id} viewContext={viewContext} onCopied={onTrainingChanged} variant="menuitem" />
              ),
            })
            : planMenu}
        />
        <div className="calv3-week-list">
          {days.map((d) => (
            <DayRow key={d.date} day={d} onClick={() => setSheetDayDate(d.date)} />
          ))}
        </div>
        {api && (
          <WeekNotesV3 api={api} weekStartDate={weekStart} viewContext={viewContext} canEdit={canEdit} />
        )}
        {sheetDay && (
          <DaySheetV3 day={sheetDay} onClose={() => setSheetDayDate(null)} {...sheetCallbacks} />
        )}
      </div>
    );
  }

  // ── DESKTOP ─────────────────────────────────────────────────────────
  const deskYear = new Date(weekStart + 'T00:00:00').getFullYear();
  const deskSub = `${week.number ? `Неделя ${week.number} · ` : ''}${Math.round(totalKm) > 0 ? `${Math.round(totalKm)} км запланировано` : (phaseLabel ? `${phaseLabel} фаза` : '')}`;
  return (
    <div className="calv3-week calv3-week--desktop">
      <div className="calv3-desk-row">
        <button type="button" className="calv3-nav-btn" onClick={goPrev} aria-label="Назад">‹</button>
        <div>
          <div className="calv3-desk-title">{formatWeekRange(weekStart)} {deskYear}</div>
          {deskSub && <div className="calv3-desk-sub">{deskSub}</div>}
        </div>
        <button type="button" className="calv3-nav-btn" onClick={goNext} aria-label="Вперёд">›</button>
        <div className="calv3-desk-row-spacer" />
        {canEdit && week.id && api && (
          <CopyWeekV3 api={api} weekId={week.id} viewContext={viewContext} onCopied={onTrainingChanged} />
        )}
        {canEdit && onAddTraining && (
          <button type="button" className="calv3-primary-btn" onClick={() => onAddTraining(selectedDate || weekStart)}>+ Тренировка</button>
        )}
        {planMenu}
      </div>
      <div className="calv3-desk-body">
        <div className="calv3-desk-main">
          {phaseKey && (
            <div className="calv3-card" style={{ marginBottom: 14 }}>
              <PhaseRibbonV3 phase={phaseKey} />
            </div>
          )}
          <div className="calv3-week-grid">
            {days.map((d) => (
              <DayCard key={d.date} day={d} active={selectedDate === d.date} onClick={() => setSelectedDate(d.date)} />
            ))}
          </div>
          {selectedDay && (
            <div style={{ marginTop: 16 }}>
              <DaySheetV3 day={selectedDay} embedded {...sheetCallbacks} />
            </div>
          )}
        </div>
        <aside className="calv3-desk-side">
          {planEnded ? (
            <div className="calv3-card calv3-ended-card">
              <span className="calv3-ended-card__icon">✓</span>
              <span>План завершён</span>
            </div>
          ) : (
            <>
              {volume.items.length > 0 && (
                <div className="calv3-card">
                  <div className="calv3-card-label">ОБЪЁМ · 4 НЕДЕЛИ</div>
                  <VolumeRailV3 items={volume.items} max={volume.max} horizontal />
                  {(() => {
                    const peak = volume.items.reduce((a, b) => (b.vol > a.vol ? b : a), volume.items[0]);
                    if (!peak || peak.vol <= 0) return null;
                    return <div className="calv3-vol-note">📈 Пик объёма на неделе {peak.n} — {peak.vol} км. Дальше плавно к цели.</div>;
                  })()}
                </div>
              )}
              {volume.items.length > 0 && (
                <div style={{ marginTop: 12 }}>
                  <PhasesListV3 items={volume.items} />
                </div>
              )}
            </>
          )}
          {api && (
            <div style={{ marginTop: 12 }}>
              <WeekNotesV3 api={api} weekStartDate={weekStart} viewContext={viewContext} canEdit={canEdit} />
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}

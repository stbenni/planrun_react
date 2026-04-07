/**
 * WeekCalendar - Недельный календарь в стиле OMY! Sports
 * Показывает неделю с цветовыми индикаторами и карточками тренировок
 * Поддерживает swipe-жесты для навигации между неделями
 * При пустом плане всегда показывается виртуальная текущая неделя (пустая сетка).
 * Карточка дня: план (без отметки выполненности) + блок выполненных тренировок.
 */

import { useState, useEffect, useRef, useCallback } from 'react';
import useAuthStore from '../../stores/useAuthStore';
import WorkoutCard from './WorkoutCard';
import { OFPIcon, SbuIcon, CompletedIcon } from './WeekCalendarIcons';
import { ActivityTypeIcon, DistanceIcon, TimeIcon, PaceIcon, CloseIcon, PenLineIcon } from '../common/Icons';
import LogoLoading from '../common/LogoLoading';
import { getPlanDayForDate, getDayCompletionStatus } from '../../utils/calendarHelpers';
import './WeekCalendar.css';
import './DayModal.modern.css';
import '../../screens/StatsScreen.css';

const stripHtml = (s) => (s || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

const TYPE_NAMES = {
  easy: 'Легкий бег', long: 'Длительный бег', 'long-run': 'Длительный бег',
  tempo: 'Темповый бег', interval: 'Интервалы', fartlek: 'Фартлек',
  control: 'Контрольный забег', race: 'Соревнование', other: 'ОФП', sbu: 'СБУ',
  rest: 'День отдыха', free: 'Пустой день', walking: 'Ходьба', hiking: 'Поход',
  cycling: 'Велосипед', swimming: 'Плавание', run: 'Бег', running: 'Бег',
};

const formatDurationDisplay = (minutesOrSeconds, isSeconds = false) => {
  if (minutesOrSeconds == null) return null;
  const totalSec = isSeconds ? minutesOrSeconds : minutesOrSeconds * 60;
  const h = Math.floor(totalSec / 3600);
  const m = Math.floor((totalSec % 3600) / 60);
  const s = Math.round(totalSec % 60);
  if (h > 0) return `${h}ч ${m}м`;
  if (m > 0) return s > 0 ? `${m}м ${s}с` : `${m}м`;
  return s > 0 ? `${s}с` : null;
};

const EMPTY_DAYS = { mon: null, tue: null, wed: null, thu: null, fri: null, sat: null, sun: null };

/** API может вернуть day как массив { type, text, id } или один объект. Возвращаем массив активностей дня. */
function normalizeDayActivities(rawDayData) {
  if (!rawDayData) return [];
  const list = Array.isArray(rawDayData) ? rawDayData : [rawDayData];
  return list.filter((d) => d && typeof d.type === 'string').map((d) => ({
    type: d.type,
    is_key_workout: !!(d.is_key_workout || d.key),
    target_hr_min: d.target_hr_min || null,
    target_hr_max: d.target_hr_max || null,
  }));
}

/** Первый не-rest тип дня (для класса ячейки). */
function firstNonRestType(activities) {
  const a = activities.find((d) => d.type !== 'rest' && d.type !== 'free');
  return a ? a.type : null;
}

/** Добавить дни к дате YYYY-MM-DD, вернуть новую YYYY-MM-DD */
function addDays(dateStr, delta) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + delta);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/** Понедельник текущей недели в формате YYYY-MM-DD */
function getMondayOfToday() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const dayOfWeek = today.getDay();
  const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
  const monday = new Date(today);
  monday.setDate(today.getDate() + diff);
  const y = monday.getFullYear();
  const m = String(monday.getMonth() + 1).padStart(2, '0');
  const d = String(monday.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Понедельник недели для произвольной даты YYYY-MM-DD */
function getMondayForDate(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setHours(0, 0, 0, 0);
  const dayOfWeek = d.getDay();
  const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
  return addDays(dateStr, diff);
}

/** Неделя для отображения: понедельник + пустая сетка дней (календарь не привязан к плану) */
function getVirtualWeekForStartDate(startDateStr) {
  return {
    number: 0,
    start_date: startDateStr,
    total_volume: '',
    days: { ...EMPTY_DAYS },
  };
}

/** Неделя для отображения: из плана, если есть на эту дату, иначе просто календарная неделя */
function getWeekForStartDate(plan, startDateStr) {
  const weeksData = plan?.weeks_data;
  if (Array.isArray(weeksData)) {
    let found = weeksData.find((w) => w && String(w.start_date) === String(startDateStr));
    if (found) return { ...found };
    // Fallback: найти неделю, в которую попадает startDateStr (на случай расхождения формата даты)
    const start = new Date(startDateStr + 'T00:00:00');
    start.setHours(0, 0, 0, 0);
    found = weeksData.find((w) => {
      if (!w?.start_date) return false;
      const weekStart = new Date(w.start_date + 'T00:00:00');
      weekStart.setHours(0, 0, 0, 0);
      const weekEnd = new Date(weekStart);
      weekEnd.setDate(weekEnd.getDate() + 6);
      return start >= weekStart && start <= weekEnd;
    });
    if (found) return { ...found };
  }
  return getVirtualWeekForStartDate(startDateStr);
}

function getVirtualCurrentWeek() {
  return getVirtualWeekForStartDate(getMondayOfToday());
}

const MOBILE_BREAKPOINT = '(max-width: 640px)';

const WeekCalendar = ({ plan, workoutsData, workoutsListByDate = {}, resultsData, api, canEdit = false, canView = false, viewContext = null, onDayPress, onOpenResultModal, onOpenWorkoutDetails, onAddTraining, onTrainingAdded, initialDate, initialDateKey = null }) => {
  const [isMobile, setIsMobile] = useState(
    () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(MOBILE_BREAKPOINT).matches : false)
  );
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    const m = window.matchMedia(MOBILE_BREAKPOINT);
    const fn = () => setIsMobile(m.matches);
    m.addEventListener('change', fn);
    fn();
    return () => m.removeEventListener('change', fn);
  }, []);

  const [currentWeek, setCurrentWeek] = useState(getVirtualCurrentWeek);
  const [selectedDate, setSelectedDate] = useState(() => {
    const t = new Date();
    t.setHours(0, 0, 0, 0);
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
  });
  const [dayDetails, setDayDetails] = useState({});
  const [, setLoadingDays] = useState(false);
  const [isSwiping, setIsSwiping] = useState(false);
  const [showCopyWeek, setShowCopyWeek] = useState(false);
  const [copyWeekTarget, setCopyWeekTarget] = useState('');
  const [copyingWeek, setCopyingWeek] = useState(false);
  const { user: currentUser } = useAuthStore();

  // Week notes
  const [weekNotes, setWeekNotes] = useState([]);
  const [showWeekNotes, setShowWeekNotes] = useState(false);
  const [newWeekNoteText, setNewWeekNoteText] = useState('');
  const [savingWeekNote, setSavingWeekNote] = useState(false);
  const [editingWeekNoteId, setEditingWeekNoteId] = useState(null);
  const [editingWeekNoteText, setEditingWeekNoteText] = useState('');
  const canManageWeekNote = useCallback((note) => {
    if (!currentUser || !note) return false;
    return note.author_id == currentUser.id || currentUser.role === 'coach' || currentUser.role === 'admin';
  }, [currentUser]);

  const swipeStartX = useRef(0);
  const swipeStartY = useRef(0);
  const containerRef = useRef(null);

  useEffect(() => {
    const todayStr = (() => {
      const t = new Date();
      t.setHours(0, 0, 0, 0);
      return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    })();
    const useDate = initialDate || todayStr;
    const mondayStr = useDate === todayStr ? getMondayOfToday() : getMondayForDate(useDate);
    let week = getWeekForStartDate(plan, mondayStr);
    // Если план есть, но начинается в будущем (текущая неделя пустая) — показываем первую неделю плана.
    const weeksData = plan?.weeks_data;
    const isEmptyWeek = week?.number === 0 || !Object.values(week?.days ?? {}).some((d) => d && (Array.isArray(d) ? d.length > 0 : true));
    if (isEmptyWeek && Array.isArray(weeksData) && weeksData.length > 0) {
      const firstPlanWeek = weeksData.find((w) => w.start_date);
      if (firstPlanWeek && firstPlanWeek.start_date > mondayStr) {
        week = { ...firstPlanWeek };
      }
    }
    setCurrentWeek(week);
    setSelectedDate(useDate);
  }, [plan, initialDate, initialDateKey]);

  const getWeekDays = (week) => {
    if (!week || !week.start_date) return [];
    const days = [];
    const [sy, sm, sd] = week.start_date.split('-').map(Number);
    const startDate = new Date(sy, sm - 1, sd);
    startDate.setHours(0, 0, 0, 0);
    const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let i = 0; i < 7; i++) {
      const date = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate() + i);
      date.setHours(0, 0, 0, 0);
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      const dateStr = `${year}-${month}-${day}`;
      const dayKey = dayKeys[i];
      const rawDay = week.days && week.days[dayKey];
      // Используем getPlanDayForDate как основной источник — он ищет по диапазону дат и надёжнее
      const planDayForDate = plan ? getPlanDayForDate(dateStr, plan) : null;
      const dayActivities = planDayForDate?.items
        ? planDayForDate.items
            .filter((d) => d && typeof d.type === 'string')
            .map((d) => ({ type: d.type, is_key_workout: !!(d.is_key_workout || d.key), target_hr_min: d.target_hr_min || null, target_hr_max: d.target_hr_max || null }))
        : normalizeDayActivities(rawDay);
      const cellType = firstNonRestType(dayActivities);
      const dayData = dayActivities.length
        ? (planDayForDate?.items?.find((d) => d && d.type !== 'rest' && d.type !== 'free') || planDayForDate?.items?.[0]
          || (Array.isArray(rawDay) ? rawDay.find((d) => d && d.type !== 'rest' && d.type !== 'free') || rawDay[0] : rawDay))
        : null;
      const isToday = date.getTime() === today.getTime();
      const completion = getDayCompletionStatus(dateStr, planDayForDate, workoutsData, resultsData, workoutsListByDate);
      const isCompleted = completion.status === 'completed';
      const isRestExtra = completion.status === 'rest_extra';
      const restExtraType = completion.extraWorkoutType;
      const hasPlanned = dayActivities.some((a) => a.type !== 'rest' && a.type !== 'free');
      const status = isCompleted ? 'completed' : isRestExtra ? 'rest_extra' : (hasPlanned ? 'planned' : 'rest');

      days.push({
        date: dateStr,
        dateObj: date,
        dayKey,
        dayLabel: dayLabels[i],
        dayData,
        dayActivities,
        cellType,
        isToday,
        status,
        isRestExtra,
        restExtraType,
        weekNumber: week.number
      });
    }
    return days;
  };

  const loadDayDataForDate = useCallback(async (date) => {
    if (!date || !api?.getDay) return;
    setLoadingDays(true);
    try {
      const response = await api.getDay(date, viewContext || undefined);
      const data = response?.data || response;
      if (data && !data.error) {
        setDayDetails(prev => ({
          ...prev,
          [date]: {
            plan: data.plan || data.planHtml || '',
            planHtml: data.planHtml || null,
            planDays: data.planDays || [],
            dayExercises: data.dayExercises || [],
            workouts: data.workouts || []
          }
        }));
      }
    } catch (error) {
      if (error?.code === 'TIMEOUT' || error?.message?.includes('aborted')) return;
      console.error(`Error loading day ${date}:`, error);
    } finally {
      setLoadingDays(false);
    }
  }, [api, viewContext]);

  // Загрузка/обновление дня при смене даты или при обновлении плана (после добавления/удаления тренировки)
  useEffect(() => {
    if (!selectedDate) return;
    loadDayDataForDate(selectedDate);
  }, [plan, selectedDate, loadDayDataForDate]);

  const goToPreviousWeek = () => {
    if (!currentWeek?.start_date) return;
    const prevStart = addDays(currentWeek.start_date, -7);
    setCurrentWeek(getWeekForStartDate(plan, prevStart));
    setSelectedDate(prevStart);
  };

  const goToNextWeek = () => {
    if (!currentWeek?.start_date) return;
    const nextStart = addDays(currentWeek.start_date, 7);
    setCurrentWeek(getWeekForStartDate(plan, nextStart));
    setSelectedDate(nextStart);
  };

  const handleCopyWeek = async () => {
    if (!copyWeekTarget || !currentWeek?.id || !api?.copyWeek) return;
    // Ensure target is Monday
    const targetDate = new Date(copyWeekTarget + 'T12:00:00');
    const dayOfWeek = targetDate.getDay(); // 0=Sun
    const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
    targetDate.setDate(targetDate.getDate() + diff);
    const targetMonday = `${targetDate.getFullYear()}-${String(targetDate.getMonth() + 1).padStart(2, '0')}-${String(targetDate.getDate()).padStart(2, '0')}`;

    setCopyingWeek(true);
    try {
      await api.copyWeek(currentWeek.id, targetMonday, viewContext || undefined);
      setShowCopyWeek(false);
      setCopyWeekTarget('');
      onTrainingAdded?.();
    } catch (e) {
      alert(e.message || 'Ошибка копирования недели');
    }
    setCopyingWeek(false);
  };

  // Week notes
  const loadWeekNotes = useCallback(async () => {
    if (!currentWeek?.start_date || !api?.getWeekNotes) return;
    try {
      const res = await api.getWeekNotes(currentWeek.start_date, viewContext || undefined);
      setWeekNotes(res?.data?.notes ?? res?.notes ?? []);
    } catch { /* silent */ }
  }, [currentWeek?.start_date, api, viewContext]);

  useEffect(() => { loadWeekNotes(); }, [loadWeekNotes]);

  const handleSaveWeekNote = async () => {
    if (!newWeekNoteText.trim() || savingWeekNote || !currentWeek?.start_date) return;
    setSavingWeekNote(true);
    try {
      await api.saveWeekNote(currentWeek.start_date, newWeekNoteText.trim(), null, viewContext || undefined);
      setNewWeekNoteText('');
      await loadWeekNotes();
    } catch (e) { alert(e.message || 'Ошибка сохранения заметки'); }
    setSavingWeekNote(false);
  };

  const handleUpdateWeekNote = async (noteId) => {
    if (!editingWeekNoteText.trim() || savingWeekNote) return;
    setSavingWeekNote(true);
    try {
      await api.saveWeekNote(currentWeek.start_date, editingWeekNoteText.trim(), noteId, viewContext || undefined);
      setEditingWeekNoteId(null);
      setEditingWeekNoteText('');
      await loadWeekNotes();
    } catch (e) { alert(e.message || 'Ошибка обновления заметки'); }
    setSavingWeekNote(false);
  };

  const handleDeleteWeekNote = async (noteId) => {
    if (!window.confirm('Удалить заметку?')) return;
    try {
      await api.deleteWeekNote(noteId, viewContext || undefined);
      await loadWeekNotes();
    } catch (e) { alert(e.message || 'Ошибка удаления'); }
  };

  // Swipe жесты для мобильных устройств
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const handleTouchStart = (e) => {
      swipeStartX.current = e.touches[0].clientX;
      swipeStartY.current = e.touches[0].clientY;
      setIsSwiping(false);
    };

    const handleTouchMove = (e) => {
      if (!swipeStartX.current || !swipeStartY.current) return;
      
      const deltaX = e.touches[0].clientX - swipeStartX.current;
      const deltaY = e.touches[0].clientY - swipeStartY.current;
      
      // Проверяем, что это горизонтальный swipe (не вертикальный скролл)
      if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
        setIsSwiping(true);
        e.preventDefault(); // Предотвращаем скролл при горизонтальном swipe
      }
    };

    const handleTouchEnd = (e) => {
      if (!swipeStartX.current || !swipeStartY.current) return;
      
      const deltaX = e.changedTouches[0].clientX - swipeStartX.current;
      const deltaY = e.changedTouches[0].clientY - swipeStartY.current;
      
      // Минимальное расстояние для swipe (50px)
      if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY)) {
        if (deltaX > 0) {
          // Swipe вправо - предыдущая неделя
          goToPreviousWeek();
        } else {
          // Swipe влево - следующая неделя
          goToNextWeek();
        }
      }
      
      swipeStartX.current = 0;
      swipeStartY.current = 0;
      setIsSwiping(false);
    };

    container.addEventListener('touchstart', handleTouchStart, { passive: false });
    container.addEventListener('touchmove', handleTouchMove, { passive: false });
    container.addEventListener('touchend', handleTouchEnd, { passive: true });

    return () => {
      container.removeEventListener('touchstart', handleTouchStart);
      container.removeEventListener('touchmove', handleTouchMove);
      container.removeEventListener('touchend', handleTouchEnd);
    };
  }, [currentWeek]);

  if (!currentWeek) {
    return (
      <div className="week-calendar-empty">
        <LogoLoading size="sm" />
      </div>
    );
  }

  const weekDays = getWeekDays(currentWeek);
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return (
    <div className={`week-calendar-container ${isSwiping ? 'swiping' : ''}`} ref={containerRef}>
      <div className="week-calendar-header">
        <div className="week-calendar-nav">
          <button
            type="button"
            className="week-nav-btn"
            onClick={goToPreviousWeek}
            aria-label="Предыдущая неделя"
          />
          <span className="week-current-label">
            {weekDays[0] && weekDays[6]
              ? `${weekDays[0].dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })} – ${weekDays[6].dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}`
              : 'Сегодня'}
          </span>
          <button
            type="button"
            className="week-nav-btn week-nav-btn-next"
            onClick={goToNextWeek}
            aria-label="Следующая неделя"
          />
        </div>
        {canEdit && currentWeek?.id && (
          <div className="week-copy-controls">
            {!showCopyWeek ? (
              <button type="button" className="btn btn-ghost btn--sm week-copy-btn" onClick={() => setShowCopyWeek(true)}>
                Скопировать неделю
              </button>
            ) : (
              <div className="week-copy-input">
                <input
                  type="date"
                  className="week-copy-date"
                  value={copyWeekTarget}
                  onChange={e => setCopyWeekTarget(e.target.value)}
                />
                <button type="button" className="btn btn-primary btn--sm" onClick={handleCopyWeek} disabled={!copyWeekTarget || copyingWeek}>
                  {copyingWeek ? '...' : 'OK'}
                </button>
                <button type="button" className="btn btn-ghost btn--sm" onClick={() => { setShowCopyWeek(false); setCopyWeekTarget(''); }}>
                  <CloseIcon className="modal-close-icon" />
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Week notes */}
      <div className="week-notes-section">
        <button
          type="button"
          className="week-notes-toggle"
          onClick={() => setShowWeekNotes(v => !v)}
          aria-expanded={showWeekNotes}
        >
          <span className="week-notes-toggle-label">Заметки к неделе{weekNotes.length > 0 ? ` (${weekNotes.length})` : ''}</span>
          <span className={`week-notes-arrow ${showWeekNotes ? 'week-notes-arrow--open' : ''}`}>&#9662;</span>
        </button>
        <div
          className={`week-notes-collapse ${showWeekNotes ? 'is-open' : ''}`}
          aria-hidden={!showWeekNotes}
        >
          <div className="week-notes-collapse-inner">
            <div className="week-notes-list">
              {weekNotes.length === 0 && <div className="week-notes-empty">Нет заметок</div>}
              {weekNotes.map(note => (
                <div key={note.id} className="week-note">
                  {canManageWeekNote(note) && (
                    <button
                      type="button"
                      className="week-note-remove"
                      onClick={() => handleDeleteWeekNote(note.id)}
                      title="Удалить"
                      aria-label="Удалить заметку"
                    >
                      <CloseIcon className="modal-close-icon" />
                    </button>
                  )}
                  <div className="week-note-header">
                    <span className="week-note-author">{note.author_username}</span>
                    <span className="week-note-date">{new Date(note.created_at).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</span>
                    {canManageWeekNote(note) && (
                      <span className="week-note-actions">
                        <button type="button" className="week-note-btn" onClick={() => { setEditingWeekNoteId(note.id); setEditingWeekNoteText(note.content); }} title="Редактировать" aria-label="Редактировать заметку">
                          <PenLineIcon className="week-note-btn-icon" size={14} />
                        </button>
                      </span>
                    )}
                  </div>
                  {editingWeekNoteId === note.id ? (
                    <div className="week-note-edit">
                      <textarea className="week-note-textarea" value={editingWeekNoteText} onChange={e => setEditingWeekNoteText(e.target.value)} rows={2} maxLength={2000} />
                      <div className="week-note-edit-btns">
                        <button type="button" className="btn btn-primary btn--sm" onClick={() => handleUpdateWeekNote(note.id)} disabled={savingWeekNote}>Сохранить</button>
                        <button type="button" className="btn btn-ghost btn--sm" onClick={() => { setEditingWeekNoteId(null); setEditingWeekNoteText(''); }}>Отмена</button>
                      </div>
                    </div>
                  ) : (
                    <div className="week-note-content">{note.content}</div>
                  )}
                </div>
              ))}
              <div className="week-note-add">
                <textarea className="week-note-textarea" placeholder="Добавить заметку к неделе..." value={newWeekNoteText} onChange={e => setNewWeekNoteText(e.target.value)} rows={2} maxLength={2000} />
                <button type="button" className="btn btn-primary btn--sm" onClick={handleSaveWeekNote} disabled={!newWeekNoteText.trim() || savingWeekNote}>
                  {savingWeekNote ? 'Сохранение...' : 'Отправить'}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="week-days-grid">
        {weekDays.map((day) => (
          <div
            key={day.date}
            className={`week-day-cell ${day.isToday ? 'today' : ''} ${day.status} ${selectedDate === day.date ? 'selected active' : ''} ${day.cellType ? `type-${day.cellType}` : ''}`}
            onClick={() => {
              setSelectedDate(day.date);
              /* Информация показывается ниже в WorkoutCard, popup не открываем */
            }}
          >
            <div className="week-day-date-square">
              <span className={`week-day-number ${day.isToday ? 'today-number' : ''}`}>
                {day.dateObj.getDate()}
              </span>
              <span className="week-day-date-sep">/</span>
              <span className="week-day-label">{day.dayLabel}</span>
              {day.dayActivities.some(a => a.type === 'control') && (
                <span className="week-day-key-dot" title="Контрольная тренировка" />
              )}
              {day.dayActivities.some(a => a.is_key_workout) && !day.dayActivities.some(a => a.type === 'control') && (
                <span className="week-day-key-dot week-day-key-dot--key" title="Ключевая тренировка" />
              )}
              {day.status === 'completed' && (() => {
                const actual = workoutsListByDate?.[day.date] || [];
                const actualKm = actual.reduce((s, w) => s + (parseFloat(w.distance_km) || 0), 0);
                const plannedKm = day.dayData?.distance_km ? parseFloat(day.dayData.distance_km) : 0;
                if (actualKm > 0 && plannedKm > 0 && actualKm > plannedKm * 1.15) {
                  return <span className="week-day-ai-dot week-day-ai-dot--exceeded" title="Перевыполнение плана" />;
                }
                return null;
              })()}
            </div>

            <div className="week-day-icons-grid">
              {day.status === 'completed' && (
                <div className="week-day-icon-square">
                  <CompletedIcon className="week-day-svg-icon week-day-svg-icon--completed" aria-hidden />
                </div>
              )}
              {day.status === 'rest_extra' && (
                <div className={`week-day-icon-square week-day-icon-square--${day.restExtraType || 'run'}`} title="Тренировка в день отдыха">
                  <span className="week-day-rest-extra-dot" aria-hidden />
                </div>
              )}
              {day.status !== 'completed' && day.status !== 'rest_extra' && day.dayActivities.length === 0 && (
                <div className="week-day-icon-square">
                  <span className="week-day-empty-dash">—</span>
                </div>
              )}
              {day.status !== 'completed' && day.status !== 'rest_extra' && day.dayActivities.length > 0 && (() => {
                const activities = day.dayActivities;
                const mobileShowTwo = isMobile && activities.length > 2;
                const hasMore = isMobile ? activities.length > 2 : activities.length > 4;
                const show = isMobile
                  ? (mobileShowTwo ? [activities[0], { type: '_more' }] : activities.slice(0, 2))
                  : (hasMore ? activities.slice(0, 3) : activities);
                return (
                  <>
                    {show.map((activity, idx) => (
                      <div key={idx} className={`week-day-icon-square${activity.type === '_more' ? ' week-day-icon-square--more' : ''}${activity.type !== '_more' && activity.type ? ` week-day-icon-square--${activity.type}` : ''}`} aria-label={activity.type === '_more' ? `Ещё ${activities.length - 1} тренировок` : undefined}>
                        {activity.type === '_more' && <span className="week-day-more-dots">…</span>}
                        {activity.type !== '_more' && activity.type === 'rest' && <span className="week-day-empty-dash">—</span>}
                        {activity.type !== '_more' && activity.type === 'free' && <span className="week-day-empty-dash">—</span>}
                        {activity.type !== '_more' && activity.type === 'other' && <OFPIcon className="week-day-svg-icon" aria-hidden />}
                        {activity.type !== '_more' && activity.type === 'sbu' && <SbuIcon className="week-day-svg-icon" aria-hidden />}
                        {activity.type !== '_more' && activity.type !== 'rest' && activity.type !== 'free' && activity.type !== 'other' && activity.type !== 'sbu' && (
                          <ActivityTypeIcon type={activity.type} className="week-day-svg-icon" aria-hidden />
                        )}
                      </div>
                    ))}
                    {!isMobile && hasMore && (
                      <div className="week-day-icon-square week-day-icon-square--more" aria-label={`Ещё ${day.dayActivities.length - 3} тренировок`}>
                        <span className="week-day-more-dots">…</span>
                      </div>
                    )}
                  </>
                );
              })()}
            </div>
          </div>
        ))}
      </div>

      {selectedDate && (() => {
        const selectedDay = weekDays.find(d => d.date === selectedDate);
        if (!selectedDay) return null;
        
        const dayDetail = dayDetails[selectedDay.date] || {};
        const workouts = dayDetail.workouts || [];
        
        const hasCompletedWorkouts = (() => {
          if (!workouts || !Array.isArray(workouts) || workouts.length === 0) return false;
          const hasMeaningfulData = (w) => {
            const dist = w.distance_km ?? w.distance;
            const dur = w.duration_minutes ?? w.duration ?? w.duration_seconds;
            return (dist != null && Number(dist) > 0) || (dur != null && Number(dur) > 0);
          };
          return workouts.some(hasMeaningfulData);
        })();
        
        const planText = dayDetail.plan || selectedDay.dayData?.text || '';
        const planTextClean = stripHtml(planText);
        
        const workoutData = {
          ...selectedDay.dayData,
          text: planTextClean,
          dayExercises: dayDetail.dayExercises || []
        };
        
        const hasPlan = (dayDetail.planDays?.length > 0 || dayDetail.dayExercises?.length > 0 || dayDetail.plan);
        const planStatus = hasPlan ? 'planned' : 'rest';
        
        return (
          <div className="week-selected-day">
            <div className="week-selected-day-two-blocks">
              {/* Блок 1: Запланированная тренировка — только информация */}
              <div
                className="week-selected-day-workout-card-wrapper"
                role={(canEdit || canView) && onDayPress ? 'button' : undefined}
                tabIndex={(canEdit || canView) && onDayPress ? 0 : undefined}
                onClick={(canEdit || canView) && onDayPress ? () => onDayPress(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey) : undefined}
                onKeyDown={(canEdit || canView) && onDayPress ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onDayPress(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey); } } : undefined}
                style={(canEdit || canView) && onDayPress ? { cursor: 'pointer' } : undefined}
              >
                <WorkoutCard
                  workout={workoutData}
                  date={selectedDay.date}
                  status={planStatus}
                  isToday={selectedDay.isToday}
                  dayDetail={{ plan: dayDetail.plan, planDays: dayDetail.planDays, dayExercises: dayDetail.dayExercises, workouts }}
                  workoutMetrics={null}
                  results={[]}
                  planDays={dayDetail.planDays || []}
                  canEdit={false}
                  extraActions={null}
                />
              </div>

              {/* Блок 2: Выполненные тренировки — стиль как в статистике (workout-item) */}
              {hasCompletedWorkouts && workouts.length > 0 && (
                <div className="week-completed-workouts stats-style">
                  <h2 className="section-title">Выполненные тренировки</h2>
                  <div className="recent-workouts-list">
                    {workouts.map((workout) => {
                      const dist = workout.distance_km ?? workout.distance;
                      const durSec = workout.duration_seconds;
                      const durMin = workout.duration_minutes ?? (workout.duration != null ? Number(workout.duration) / 60 : null);
                      const durationDisplay = durSec != null ? formatDurationDisplay(durSec, true) : (durMin != null ? formatDurationDisplay(durMin, false) : null);
                      const pace = workout.avg_pace ?? workout.pace;
                      const typeKey = (workout.activity_type ?? workout.type ?? 'run').toLowerCase().trim();
                      const typeName = TYPE_NAMES[typeKey] || typeKey;
                      const workoutId = workout.is_manual ? `log_${workout.id}` : (workout.id ?? workout.workout_id);
                      const immediateDayData = {
                        ...dayDetail,
                        planDays: dayDetail.planDays || [],
                        dayExercises: dayDetail.dayExercises || [],
                        workouts,
                      };
                      return (
                        <div
                          key={workout.id || workout.workout_id || Math.random()}
                          className="workout-item"
                          onClick={onOpenWorkoutDetails ? () => onOpenWorkoutDetails(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey, workoutId, immediateDayData) : undefined}
                          style={{ cursor: onOpenWorkoutDetails ? 'pointer' : 'default' }}
                          role={onOpenWorkoutDetails ? 'button' : undefined}
                          tabIndex={onOpenWorkoutDetails ? 0 : undefined}
                          onKeyDown={onOpenWorkoutDetails ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onOpenWorkoutDetails(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey, workoutId, immediateDayData); } } : undefined}
                        >
                          <div className="workout-item-type" data-type={typeKey}>
                            <ActivityTypeIcon type={typeKey} className="workout-item-type__icon" aria-hidden />
                            <span className="workout-item-type__label">{typeName}</span>
                          </div>
                          <div className="workout-item-main">
                            <div className="workout-item-date">
                              {selectedDay.dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })}
                            </div>
                            <div className="workout-item-metrics">
                              {dist != null && Number(dist) > 0 && (
                                <span className="workout-metric">
                                  <DistanceIcon className="workout-metric__icon" aria-hidden />
                                  {Number(dist).toFixed(1)} км
                                </span>
                              )}
                              {durationDisplay && (
                                <span className="workout-metric">
                                  <TimeIcon className="workout-metric__icon" aria-hidden />
                                  {durationDisplay}
                                </span>
                              )}
                              {pace && (
                                <span className="workout-metric">
                                  <PaceIcon className="workout-metric__icon" aria-hidden />
                                  {pace} /км
                                </span>
                              )}
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>

            {canEdit && (onAddTraining || (onOpenResultModal && (selectedDay.dayData || (dayDetail.planDays && dayDetail.planDays.length > 0)))) && (
              <div className="week-selected-day-actions">
                {onAddTraining && (
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => onAddTraining(selectedDay.date)}
                  >
                    <span className="week-add-training-btn-icon" aria-hidden>+</span>
                    Запланировать тренировку
                  </button>
                )}
                {onOpenResultModal && (selectedDay.dayData || (dayDetail.planDays && dayDetail.planDays.length > 0)) && (
                  <button
                    type="button"
                    className="btn btn-primary"
                    onClick={() => onOpenResultModal(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey)}
                  >
                    Отметить выполненной
                  </button>
                )}
              </div>
            )}
          </div>
        );
      })()}
    </div>
  );
};

export default WeekCalendar;

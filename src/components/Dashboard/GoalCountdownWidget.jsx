/**
 * Goal Countdown — крупный обратный отсчёт до главной цели пользователя.
 * Показывает: дни до старта, целевая дистанция, целевое время/темп, фаза макроцикла.
 * Данные: api.getRacePrediction (goal) + текущая неделя плана (phase).
 */

import { useState, useEffect, useMemo } from 'react';
import { FlagIcon, TargetIcon, TimeIcon, PaceIcon } from '../common/Icons';
import './GoalCountdownWidget.css';

const DISTANCE_LABELS = {
  '5k': '5 км',
  '10k': '10 км',
  'half': 'Полумарафон',
  '21.1k': 'Полумарафон',
  'marathon': 'Марафон',
  '42.2k': 'Марафон',
};

const PHASE_LABELS = {
  base: 'База',
  build: 'Строительная',
  peak: 'Пиковая',
  taper: 'Сужение',
  recovery: 'Восстановление',
  race: 'Старт',
};

const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const DAY_LABELS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

/** Парсит "YYYY-MM-DD" в локальную дату без UTC-сдвига. */
function parseDate(iso) {
  if (!iso) return null;
  const [y, m, d] = String(iso).split('-').map(Number);
  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d);
}

function formatRaceDate(iso) {
  const d = parseDate(iso);
  if (!d) return '';
  return `${DAY_LABELS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]}`;
}

/** "01:02:30" → "1 час 02 мин", "00:42:15" → "42 мин". */
function formatTargetTime(time) {
  if (!time) return '';
  const parts = String(time).split(':').map((n) => Number(n) || 0);
  let h = 0, m = 0, s = 0;
  if (parts.length === 3) [h, m, s] = parts;
  else if (parts.length === 2) [m, s] = parts;
  if (h > 0) return `${h}ч ${String(m).padStart(2, '0')}м`;
  if (m > 0) return `${m}м ${String(s).padStart(2, '0')}с`;
  return `${s}с`;
}

/** ISO HH:MM:SS / MM:SS / число секунд → "X:YZ". Для goal.target_pace используется формат "MM:SS". */
function formatPace(pace) {
  if (!pace) return '';
  return String(pace).trim();
}

function getCurrentWeekPhase(plan) {
  const weeksData = plan?.weeks_data;
  if (!Array.isArray(weeksData)) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  for (const week of weeksData) {
    const start = parseDate(week.start_date);
    if (!start) continue;
    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    end.setHours(23, 59, 59, 999);
    if (today >= start && today <= end) {
      return week.phase || null;
    }
  }
  return null;
}

const GoalCountdownWidget = ({ api, plan, onNavigate }) => {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!api?.getRacePrediction) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const res = await api.getRacePrediction();
        const d = res?.data ?? res;
        if (!cancelled) setData(d);
      } catch {
        if (!cancelled) setData(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [api]);

  const phase = useMemo(() => getCurrentWeekPhase(plan), [plan]);

  if (loading || !data?.goal) return null;

  const goal = data.goal;
  if (!goal.days_to_race || goal.days_to_race <= 0) return null;

  const distanceLabel = DISTANCE_LABELS[goal.race_distance] || goal.race_distance || '';
  const dateLabel = formatRaceDate(goal.race_date);
  const targetTime = formatTargetTime(goal.race_target_time);
  const predictionTime = data.predictions?.[goal.race_distance]?.formatted;
  const predictionPace = data.predictions?.[goal.race_distance]?.pace_formatted;
  const phaseLabel = phase ? (PHASE_LABELS[phase] || phase) : null;

  const days = goal.days_to_race;
  const weeks = goal.weeks_to_race ?? Math.ceil(days / 7);

  const handleClick = () => {
    if (onNavigate) onNavigate('calendar');
  };

  return (
    <button
      type="button"
      className="goal-countdown"
      onClick={handleClick}
      aria-label={`До цели ${days} дней. Открыть календарь`}
    >
      <span className="goal-countdown__glow" aria-hidden />

      <div className="goal-countdown__head">
        <span className="goal-countdown__icon" aria-hidden>
          <FlagIcon size={18} />
        </span>
        <span className="goal-countdown__title">{distanceLabel}</span>
        {phaseLabel && (
          <span className="goal-countdown__phase">{phaseLabel}</span>
        )}
      </div>

      <div className="goal-countdown__main">
        <div className="goal-countdown__days">
          <span className="goal-countdown__days-value">{days}</span>
          <span className="goal-countdown__days-label">
            {days === 1 ? 'день до старта' : days < 5 ? 'дня до старта' : 'дней до старта'}
          </span>
        </div>

        <div className="goal-countdown__meta">
          {dateLabel && (
            <div className="goal-countdown__meta-row">
              <span className="goal-countdown__meta-label">Дата</span>
              <span className="goal-countdown__meta-value">{dateLabel}</span>
            </div>
          )}
          {targetTime && (
            <div className="goal-countdown__meta-row">
              <span className="goal-countdown__meta-label">
                <TargetIcon size={14} /> Цель
              </span>
              <span className="goal-countdown__meta-value goal-countdown__meta-value--accent">{targetTime}</span>
            </div>
          )}
          {predictionTime && (
            <div className="goal-countdown__meta-row">
              <span className="goal-countdown__meta-label">
                <TimeIcon size={14} /> Прогноз
              </span>
              <span className="goal-countdown__meta-value">{predictionTime}</span>
            </div>
          )}
          {predictionPace && (
            <div className="goal-countdown__meta-row">
              <span className="goal-countdown__meta-label">
                <PaceIcon size={14} /> Темп
              </span>
              <span className="goal-countdown__meta-value">{formatPace(predictionPace)}/км</span>
            </div>
          )}
        </div>
      </div>

      {weeks > 0 && (
        <div className="goal-countdown__footer">
          <span className="goal-countdown__weeks">~{weeks} {weeks === 1 ? 'неделя' : weeks < 5 ? 'недели' : 'недель'} подготовки осталось</span>
        </div>
      )}
    </button>
  );
};

export default GoalCountdownWidget;

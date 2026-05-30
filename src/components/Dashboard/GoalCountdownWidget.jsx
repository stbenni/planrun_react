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

const DISTANCE_KM = {
  '5k': 5,
  '10k': 10,
  'half': 21.0975,
  '21.1k': 21.0975,
  'marathon': 42.195,
  '42.2k': 42.195,
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

function parseTimeToSec(time) {
  if (!time) return null;
  const parts = String(time).split(':').map((n) => Number(n) || 0);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return null;
}

function formatPaceFromSec(secPerKm) {
  if (!secPerKm || secPerKm <= 0) return '';
  const m = Math.floor(secPerKm / 60);
  const s = Math.round(secPerKm - m * 60);
  return `${m}:${String(s).padStart(2, '0')}`;
}

/** Дельта прогноза от цели: отрицательная = быстрее (хорошо), положительная = медленнее. */
function formatGoalDelta(predictionSec, targetSec) {
  if (predictionSec == null || targetSec == null) return null;
  const delta = predictionSec - targetSec;
  if (Math.abs(delta) < 5) return { text: 'на уровне цели', tone: 'neutral' };
  const sign = delta < 0 ? '−' : '+';
  const abs = Math.abs(delta);
  const h = Math.floor(abs / 3600);
  const m = Math.floor((abs % 3600) / 60);
  const s = abs % 60;
  let formatted;
  if (h > 0) formatted = `${h}ч ${String(m).padStart(2, '0')}м`;
  else if (m > 0) formatted = `${m}м ${String(s).padStart(2, '0')}с`;
  else formatted = `${s}с`;
  return {
    text: `${sign}${formatted} от цели`,
    tone: delta < 0 ? 'up' : 'down',
  };
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

  const distKm = DISTANCE_KM[goal.race_distance] || null;
  const targetSec = parseTimeToSec(goal.race_target_time);
  const targetPace = (targetSec && distKm) ? formatPaceFromSec(targetSec / distKm) : null;
  const predictionSec = parseTimeToSec(data.predictions?.[goal.race_distance]?.formatted);
  const delta = formatGoalDelta(predictionSec, targetSec);

  const days = goal.days_to_race;
  const weeks = goal.weeks_to_race ?? Math.ceil(days / 7);

  const handleClick = () => {
    if (onNavigate) onNavigate('calendar');
  };

  const daysWord = days === 1 ? 'день до старта' : days < 5 ? 'дня до старта' : 'дней до старта';
  const weeksWord = weeks === 1 ? 'неделя' : weeks < 5 ? 'недели' : 'недель';

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
        <div className="goal-countdown__hero">
          <div className="goal-countdown__days">
            <span className="goal-countdown__days-value">{days}</span>
            <span className="goal-countdown__days-label">{daysWord}</span>
          </div>
          {(dateLabel || weeks > 0) && (
            <div className="goal-countdown__hero-bottom">
              {dateLabel && (
                <div className="goal-countdown__date">{dateLabel}</div>
              )}
              {weeks > 0 && (
                <div className="goal-countdown__weeks">~{weeks} {weeksWord} подготовки</div>
              )}
            </div>
          )}
        </div>

        <div className="goal-countdown__grid">
          {targetTime && (
            <div className="goal-countdown__card goal-countdown__card--accent">
              <span className="goal-countdown__card-label">
                <TargetIcon size={12} /> Цель
              </span>
              <span className="goal-countdown__card-value">{targetTime}</span>
              {targetPace && (
                <span className="goal-countdown__card-sub">темп {targetPace}/км</span>
              )}
            </div>
          )}
          {predictionTime && (
            <div className="goal-countdown__card">
              <span className="goal-countdown__card-label">
                <TimeIcon size={12} /> Прогноз
              </span>
              <span className="goal-countdown__card-value">{predictionTime}</span>
              {delta && (
                <span className={`goal-countdown__card-sub goal-countdown__card-sub--${delta.tone}`}>
                  {delta.text}
                </span>
              )}
            </div>
          )}
          {predictionPace && (
            <div className="goal-countdown__card">
              <span className="goal-countdown__card-label">
                <PaceIcon size={12} /> Темп
              </span>
              <span className="goal-countdown__card-value">{formatPace(predictionPace)}/км</span>
              <span className="goal-countdown__card-sub">прогнозный</span>
            </div>
          )}
        </div>
      </div>
    </button>
  );
};

export default GoalCountdownWidget;

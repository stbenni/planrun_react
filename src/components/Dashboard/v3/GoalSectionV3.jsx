/**
 * GoalSectionV3 — главная цель: countdown + прогноз vs цель.
 * Тёмный countdown-box с днями до старта + прогресс-бар недель.
 * Под ним: цель → прогноз (с цветовой подсказкой).
 */

import { useEffect, useState } from 'react';
import { TrendingDownIcon, TrendingUpIcon } from '../../common/Icons';
import './GoalSectionV3.css';

const USER_DIST_TO_KEY = {
  '5k': '5k', '10k': '10k', half: 'half', half_marathon: 'half',
  marathon: 'marathon', '21.1k': 'half', '42.2k': 'marathon',
};

const DISTANCE_LABELS = {
  '5k': '5 км', '10k': '10 км', half: 'Полумарафон', half_marathon: 'Полумарафон',
  marathon: 'Марафон', ultra: 'Ультра',
};

const MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function daysToRace(iso) {
  if (!iso) return null;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return null;
  return Math.max(0, Math.ceil((t - Date.now()) / 86400000));
}

function formatRaceDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`;
}

export default function GoalSectionV3({ user, plan, api }) {
  const raceDate = user?.race_date || user?.target_marathon_date;
  const distance = user?.race_distance;
  const targetTime = user?.race_target_time;
  const days = daysToRace(raceDate);
  const distLabel = DISTANCE_LABELS[distance] || distance;

  // Прогресс по неделям: считаем из плана
  const { weeksDone, weeksTotal } = useWeeksProgress(plan);
  const progress = weeksTotal > 0 ? Math.min(1, weeksDone / weeksTotal) : 0;
  const phaseLabel = derivePhase(weeksDone, weeksTotal);

  // Прогноз на целевую дистанцию из race_prediction API
  const [prediction, setPrediction] = useState(null);
  useEffect(() => {
    if (!api?.getRacePrediction || !distance) return undefined;
    let cancelled = false;
    api.getRacePrediction()
      .then((res) => {
        if (cancelled) return;
        const data = res?.data || res;
        const key = USER_DIST_TO_KEY[distance];
        const pred = data?.predictions?.[key];
        if (pred) {
          setPrediction({
            formatted: pred.formatted,
            delta: targetTime ? computeDelta(targetTime, pred.formatted) : null,
          });
        }
      })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api, distance, targetTime]);

  if (!raceDate || !distLabel) {
    return (
      <div className="card goal-v3 goal-v3--empty">
        <div className="goal-v3__eyebrow">ГЛАВНАЯ ЦЕЛЬ</div>
        <div className="goal-v3__placeholder">Цель не указана. Заполните в настройках.</div>
      </div>
    );
  }

  return (
    <div className="card goal-v3">
      <div className="goal-v3__eyebrow">ГЛАВНАЯ ЦЕЛЬ</div>
      <h2 className="goal-v3__title">{distLabel}</h2>
      {raceDate && <div className="goal-v3__date">{formatRaceDate(raceDate)}</div>}

      <div className="goal-v3__dark">
        <div className="goal-v3__countdown-row">
          <span className="goal-v3__days">{days != null ? days : '—'}</span>
          <span className="goal-v3__days-label">{days != null ? 'дней до старта' : ''}</span>
        </div>
        {weeksTotal > 0 && (
          <>
            <div className="goal-v3__bar">
              <div className="goal-v3__bar-fill" style={{ width: `${progress * 100}%` }} />
            </div>
            <div className="goal-v3__bar-meta">
              <span>Неделя {weeksDone}/{weeksTotal}</span>
              {phaseLabel && <span>Фаза: {phaseLabel}</span>}
            </div>
          </>
        )}
      </div>

      {(targetTime || prediction) && (
        <div className="goal-v3__pred">
          {targetTime && (
            <div>
              <div className="goal-v3__pred-lbl">ЦЕЛЬ</div>
              <div className="goal-v3__pred-num">{targetTime}</div>
            </div>
          )}
          {targetTime && prediction && <span className="goal-v3__arrow" aria-hidden>→</span>}
          {prediction && (
            <div>
              <div className="goal-v3__pred-lbl">ПРОГНОЗ</div>
              <div className="goal-v3__pred-num-row">
                <span
                  className={`goal-v3__pred-num ${prediction.delta?.faster ? 'goal-v3__pred-num--ok' : prediction.delta?.slower ? 'goal-v3__pred-num--warn' : ''}`}
                >
                  {prediction.formatted}
                </span>
                {prediction.delta && (
                  <span className={`goal-v3__pred-delta ${prediction.delta.faster ? 'goal-v3__pred-delta--ok' : 'goal-v3__pred-delta--warn'}`}>
                    {prediction.delta.faster ? <TrendingDownIcon size={12} /> : <TrendingUpIcon size={12} />}
                    {prediction.delta.text}
                  </span>
                )}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function parseHhMmSs(t) {
  if (!t) return null;
  const parts = String(t).split(':').map((p) => parseInt(p, 10) || 0);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return null;
}

function formatDeltaSec(sec) {
  const abs = Math.abs(sec);
  if (abs < 60) return `${abs} сек`;
  const m = Math.floor(abs / 60);
  const s = abs % 60;
  return s === 0 ? `${m} мин` : `${m}:${String(s).padStart(2, '0')}`;
}

/** Сравнение target vs predicted времени. Возвращает {faster, slower, text}. */
function computeDelta(targetTime, predictedTime) {
  const targetSec = parseHhMmSs(targetTime);
  const predSec = parseHhMmSs(predictedTime);
  if (targetSec == null || predSec == null) return null;
  const diff = predSec - targetSec;
  if (diff < 0) return { faster: true, text: `−${formatDeltaSec(diff)}` };
  if (diff > 0) return { slower: true, text: `+${formatDeltaSec(diff)}` };
  return { text: 'точно цель' };
}

/**
 * Фаза тренировочного цикла по прогрессу недель:
 *   < 25% → База, < 50% → Развивающая, < 80% → Пиковая, < 100% → Подводка, иначе Старт.
 */
function derivePhase(weeksDone, weeksTotal) {
  if (!weeksTotal || weeksTotal <= 0) return null;
  const pct = weeksDone / weeksTotal;
  if (pct < 0.25) return 'базовая';
  if (pct < 0.5) return 'развивающая';
  if (pct < 0.8) return 'пиковая';
  if (pct < 1) return 'подводка';
  return 'старт';
}

function useWeeksProgress(plan) {
  if (!plan) return { weeksDone: 0, weeksTotal: 0 };
  const phases = Array.isArray(plan?.phases) ? plan.phases : null;
  const allWeeks = phases
    ? phases.flatMap((p) => Array.isArray(p?.weeks_data) ? p.weeks_data : [])
    : (Array.isArray(plan?.weeks_data) ? plan.weeks_data : []);
  const total = allWeeks.length;
  if (total === 0) return { weeksDone: 0, weeksTotal: 0 };
  const todayIso = new Date().toISOString().slice(0, 10);
  let done = 0;
  for (const w of allWeeks) {
    if (!w?.start_date) continue;
    const end = new Date(w.start_date);
    end.setDate(end.getDate() + 6);
    if (end.toISOString().slice(0, 10) < todayIso) done += 1;
  }
  return { weeksDone: done, weeksTotal: total };
}

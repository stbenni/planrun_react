/**
 * DashHeaderV3 — заголовок дашборда v3 (mobile/desktop).
 * Слева: аватар + дата + приветствие.
 * Справа: mode badge (AI или тренер) с индикатором online.
 */

import { CoachAvatar } from '../../Coach/CoachPrimitives';
import NotificationBell from '../../common/NotificationBell';
import './DashHeaderV3.css';

const MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const WEEKDAYS = ['ВС', 'ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ'];
const WEEKDAYS_FULL = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

function formatEyebrowMobile(d) {
  return `${WEEKDAYS[d.getDay()]} · ${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`.toUpperCase();
}

function formatEyebrowDesktop(d) {
  const w = WEEKDAYS_FULL[d.getDay()];
  return `${w[0].toUpperCase()}${w.slice(1)} · ${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`;
}

export default function DashHeaderV3({ user, mode = 'ai', weekSummary, api, isAdmin }) {
  const now = new Date();
  // На мобиле формат «ВТ · 12 МАЯ», на десктопе «Вторник · 12 мая» —
  // выбираем сразу оба, CSS показывает нужный.
  const eyebrowMobile = formatEyebrowMobile(now);
  const eyebrowDesktop = formatEyebrowDesktop(now);
  const rawName = user?.name || user?.username || '';
  const firstName = rawName ? String(rawName).trim().split(/\s+/)[0] : '';

  return (
    <div className="dash-header-v3">
      <div className="dash-header-v3__left">
        <CoachAvatar athlete={user} size={48} radius={14} />
        <div className="dash-header-v3__text">
          <div className="dash-header-v3__eyebrow dash-header-v3__eyebrow--mobile">{eyebrowMobile}</div>
          <div className="dash-header-v3__eyebrow dash-header-v3__eyebrow--desktop">{eyebrowDesktop}</div>
          <div className="dash-header-v3__greeting">
            Привет{firstName ? `, ${firstName}` : ''}
            <span className="dash-header-v3__greeting-emoji"> 👋</span>
          </div>
          {weekSummary && (
            <div className="dash-header-v3__week-summary">
              На этой неделе: <b>{weekSummary}</b>
            </div>
          )}
        </div>
      </div>

      <button type="button" className="dash-header-v3__mode" title={mode === 'ai' ? 'AI-тренер' : 'Тренер'}>
        <span className="dash-header-v3__mode-avatar-wrap">
          {mode === 'ai' ? (
            <span className="dash-header-v3__ai-avatar" aria-hidden>AI</span>
          ) : (
            <span className="dash-header-v3__coach-avatar" aria-hidden>МК</span>
          )}
          <span className="dash-header-v3__mode-status" aria-hidden />
        </span>
        <span className="dash-header-v3__mode-text">
          <span className="dash-header-v3__mode-eyebrow">РЕЖИМ</span>
          <span className="dash-header-v3__mode-name">
            {mode === 'ai' ? 'AI-тренер' : 'Тренер'}
            <span className="dash-header-v3__mode-status-inline" aria-hidden />
          </span>
        </span>
      </button>

      <NotificationBell api={api} isAdmin={isAdmin} user={user} />
    </div>
  );
}

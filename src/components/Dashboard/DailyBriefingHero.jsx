/**
 * Daily AI Briefing — hero-карточка на дашборде.
 * Показывает последний свежий брифинг от AI-тренера (event_key=coach.proactive_daily_briefing),
 * сгенерированный утренним cron-job. Если briefing нет — карточка не рендерится.
 */

import { useState, useEffect } from 'react';
import { BotIcon } from '../common/Icons';
import './DailyBriefingHero.css';

const MAX_AGE_HOURS = 36;
const RELATIVE_DAY_LABELS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function formatBriefingDate(iso) {
  if (!iso) return '';
  const d = new Date(iso.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  if (isToday) return 'Сегодня';
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
  return `${RELATIVE_DAY_LABELS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]}`;
}

const DailyBriefingHero = ({ api, onOpenChat }) => {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!api?.getLatestProactiveMessage) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const res = await api.getLatestProactiveMessage('daily_briefing', MAX_AGE_HOURS);
        const msg = res?.data?.message ?? res?.message ?? null;
        if (!cancelled) setData(msg);
      } catch {
        if (!cancelled) setData(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [api]);

  if (loading || !data || !data.content) return null;

  const dateLabel = formatBriefingDate(data.created_at);
  const handleClick = () => { if (onOpenChat) onOpenChat(); };

  return (
    <button
      type="button"
      className="daily-briefing-hero"
      onClick={handleClick}
      aria-label="Открыть чат с AI-тренером"
    >
      <span className="daily-briefing-hero__glow" aria-hidden />
      <div className="daily-briefing-hero__head">
        <span className="daily-briefing-hero__icon" aria-hidden>
          <BotIcon size={20} />
        </span>
        <span className="daily-briefing-hero__title">Брифинг тренера</span>
        {dateLabel && <span className="daily-briefing-hero__date">{dateLabel}</span>}
      </div>
      <p className="daily-briefing-hero__content">{data.content}</p>
      <span className="daily-briefing-hero__cta">
        Открыть чат
        <span aria-hidden>→</span>
      </span>
    </button>
  );
};

export default DailyBriefingHero;

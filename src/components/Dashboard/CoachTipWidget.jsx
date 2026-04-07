import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import LogoLoading from '../common/LogoLoading';
import { BotIcon } from '../common/Icons';
import { getProactiveTypeLabel } from '../../utils/proactiveMessages';
import './CoachTipWidget.css';

const ACTIVATION_KEYS = new Set(['Enter', ' ']);

const getPreviewText = (content, compact) => {
  const normalized = String(content || '')
    .replace(/\s+/g, ' ')
    .trim();

  if (!normalized) return '';

  const limit = compact ? 160 : 280;
  if (normalized.length <= limit) return normalized;

  return `${normalized.slice(0, limit).trimEnd()}…`;
};

const formatTipDate = (value) => {
  if (!value) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  const now = new Date();
  const formatter = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'short',
    ...(date.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}),
  });

  return formatter.format(date);
};

const parseMetadata = (metadata) => {
  if (!metadata) return null;

  if (typeof metadata === 'string') {
    try {
      return JSON.parse(metadata);
    } catch {
      return null;
    }
  }

  return typeof metadata === 'object' ? metadata : null;
};

const pickLatestProactiveTip = (messages) => {
  let latestTip = null;
  let latestTimestamp = Number.NEGATIVE_INFINITY;

  for (const message of messages) {
    if (message?.sender_type !== 'ai') continue;

    const metadata = parseMetadata(message.metadata);
    if (!metadata?.proactive_type) continue;

    const createdAt = new Date(message.created_at || 0).getTime();
    const timestamp = Number.isFinite(createdAt) ? createdAt : latestTimestamp + 1;

    if (latestTip && timestamp < latestTimestamp) continue;

    latestTimestamp = timestamp;
    latestTip = {
      content: message.content,
      type: metadata.proactive_type,
      time: message.created_at,
    };
  }

  return latestTip;
};

const CoachTipWidget = ({ api, compact = false, isTabActive = true }) => {
  const navigate = useNavigate();
  const [tip, setTip] = useState(null);
  const [loading, setLoading] = useState(true);
  const mountedRef = useRef(false);
  const requestIdRef = useRef(0);

  const loadTip = useCallback(async ({ silent = false } = {}) => {
    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;

    if (!api) {
      if (!mountedRef.current || requestId !== requestIdRef.current) return;
      setTip(null);
      setLoading(false);
      return;
    }

    if (!silent && mountedRef.current) {
      setLoading(true);
    }

    try {
      const res = await api.chatGetMessages();
      if (!mountedRef.current || requestId !== requestIdRef.current) return;

      const messages = res?.data?.messages || res?.messages || [];
      setTip(pickLatestProactiveTip(messages));
      setLoading(false);
    } catch {
      if (!mountedRef.current || requestId !== requestIdRef.current) return;

      if (!silent) {
        setLoading(false);
      }
    }
  }, [api]);

  useEffect(() => {
    mountedRef.current = true;

    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    if (!api) {
      setTip(null);
      setLoading(false);
      return;
    }

    if (!isTabActive) return;

    loadTip();
  }, [api, isTabActive, loadTip]);

  useEffect(() => {
    if (!api || !isTabActive) return undefined;

    const handleWindowFocus = () => {
      loadTip({ silent: true });
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState !== 'visible') return;
      loadTip({ silent: true });
    };

    window.addEventListener('focus', handleWindowFocus);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      window.removeEventListener('focus', handleWindowFocus);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [api, isTabActive, loadTip]);

  const openChat = () => navigate('/chat');
  const handleKeyDown = (event) => {
    if (!ACTIVATION_KEYS.has(event.key)) return;
    event.preventDefault();
    openChat();
  };

  if (loading) {
    return (
      <div className={`coach-tip-widget coach-tip-widget--loading${compact ? ' coach-tip-widget--compact' : ''}`} aria-busy="true">
        <LogoLoading size="sm" />
        <span className="coach-tip-widget__loading-note">Загружаем совет тренера</span>
      </div>
    );
  }

  if (!tip) {
    return (
      <div
        className={`coach-tip-widget coach-tip-widget--empty${compact ? ' coach-tip-widget--compact' : ''}`}
        role="button"
        tabIndex={0}
        onClick={openChat}
        onKeyDown={handleKeyDown}
      >
        <div className="coach-tip-widget__header">
          <div className="coach-tip-widget__eyebrow">
            <span className="coach-tip-widget__icon-wrap" aria-hidden>
              <BotIcon size={18} className="coach-tip-widget__icon" />
            </span>
            <div className="coach-tip-widget__meta">
              <span className="coach-tip-widget__kicker">ИИ-тренер</span>
              <span className="coach-tip-widget__heading">Советы тренера</span>
            </div>
          </div>
        </div>
        <p className="coach-tip-widget__content coach-tip-widget__content--empty">
          Спросите что-нибудь у ИИ-тренера, и здесь появятся разборы тренировок, брифинги и напоминания.
        </p>
        <div className="coach-tip-widget__footer">
          <span className="coach-tip-widget__type-pill">Диалог с тренером</span>
          <span className="coach-tip-widget__cta">Открыть чат</span>
        </div>
      </div>
    );
  }

  const typeLabel = getProactiveTypeLabel(tip.type);
  const preview = getPreviewText(tip.content, compact);
  const formattedDate = formatTipDate(tip.time);

  return (
    <div
      className={`coach-tip-widget${compact ? ' coach-tip-widget--compact' : ''}`}
      role="button"
      tabIndex={0}
      onClick={openChat}
      onKeyDown={handleKeyDown}
    >
      <div className="coach-tip-widget__header">
        <div className="coach-tip-widget__eyebrow">
          <span className="coach-tip-widget__icon-wrap" aria-hidden>
            <BotIcon size={18} className="coach-tip-widget__icon" />
          </span>
          <div className="coach-tip-widget__meta">
            <span className="coach-tip-widget__kicker">ИИ-тренер</span>
            <span className="coach-tip-widget__heading">Совет тренера</span>
          </div>
        </div>
        {formattedDate ? (
          <span className="coach-tip-widget__date">{formattedDate}</span>
        ) : null}
      </div>
      <p className="coach-tip-widget__content">{preview}</p>
      <div className="coach-tip-widget__footer">
        <span className="coach-tip-widget__type-pill">{typeLabel}</span>
        <span className="coach-tip-widget__cta">Открыть чат</span>
      </div>
    </div>
  );
};

export default CoachTipWidget;

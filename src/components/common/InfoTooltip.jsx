/**
 * InfoTooltip — иконка «i» в кружке с подсказкой по клику/наведению.
 * Popover рендерится через portal на document.body с position: fixed,
 * позиционируется относительно триггера с учётом границ viewport.
 */

import React, { useEffect, useRef, useState, useCallback, useLayoutEffect } from 'react';
import { createPortal } from 'react-dom';
import { InfoIcon } from './Icons';
import './InfoTooltip.css';

const VIEWPORT_PADDING = 8;
const POPOVER_OFFSET = 8;

const InfoTooltip = ({ title, content, size = 14 }) => {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState({ top: 0, left: 0, arrowLeft: 0, placement: 'top' });
  const triggerRef = useRef(null);
  const popoverRef = useRef(null);

  const close = useCallback(() => setOpen(false), []);
  const toggle = useCallback((e) => {
    e.preventDefault();
    e.stopPropagation();
    setOpen((v) => !v);
  }, []);

  // Положение popover относительно триггера и viewport.
  const updatePosition = useCallback(() => {
    const trigger = triggerRef.current;
    const popover = popoverRef.current;
    if (!trigger || !popover) return;

    const tr = trigger.getBoundingClientRect();
    const pr = popover.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    const triggerCenterX = tr.left + tr.width / 2;
    // Желаемая позиция: центр над иконкой.
    let left = triggerCenterX - pr.width / 2;
    // Клампим в границы viewport.
    left = Math.max(VIEWPORT_PADDING, Math.min(left, vw - pr.width - VIEWPORT_PADDING));

    // По умолчанию открываем сверху; если места нет — снизу.
    let top = tr.top - pr.height - POPOVER_OFFSET;
    let placement = 'top';
    if (top < VIEWPORT_PADDING) {
      top = tr.bottom + POPOVER_OFFSET;
      placement = 'bottom';
    }
    if (top + pr.height > vh - VIEWPORT_PADDING) {
      top = Math.max(VIEWPORT_PADDING, vh - pr.height - VIEWPORT_PADDING);
    }

    // Стрелка указывает на центр иконки. Координата относительно левого края popover.
    const arrowLeft = Math.max(12, Math.min(pr.width - 12, triggerCenterX - left));

    setPos({ top, left, arrowLeft, placement });
  }, []);

  useLayoutEffect(() => {
    if (open) updatePosition();
  }, [open, updatePosition]);

  useEffect(() => {
    if (!open) return undefined;
    const onScroll = () => updatePosition();
    const onResize = () => updatePosition();
    const onDocClick = (e) => {
      if (
        triggerRef.current && !triggerRef.current.contains(e.target)
        && popoverRef.current && !popoverRef.current.contains(e.target)
      ) {
        close();
      }
    };
    const onEsc = (e) => { if (e.key === 'Escape') close(); };
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onResize);
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('touchstart', onDocClick, { passive: true });
    document.addEventListener('keydown', onEsc);
    return () => {
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', onResize);
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('touchstart', onDocClick);
      document.removeEventListener('keydown', onEsc);
    };
  }, [open, close, updatePosition]);

  const popover = open && typeof document !== 'undefined' ? createPortal(
    <span
      ref={popoverRef}
      className="info-tooltip-popover"
      role="tooltip"
      data-placement={pos.placement}
      style={{
        top: `${pos.top}px`,
        left: `${pos.left}px`,
        '--info-tooltip-arrow-left': `${pos.arrowLeft}px`,
      }}
    >
      {title && <span className="info-tooltip-title">{title}</span>}
      <span className="info-tooltip-content">{content}</span>
    </span>,
    document.body,
  ) : null;

  return (
    <span className="info-tooltip-wrap">
      <button
        ref={triggerRef}
        type="button"
        className="info-tooltip-trigger"
        onClick={toggle}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        aria-label={title ? `Что такое ${title}` : 'Подсказка'}
        aria-expanded={open}
      >
        <InfoIcon size={size} strokeWidth={2} aria-hidden />
      </button>
      {popover}
    </span>
  );
};

export default InfoTooltip;

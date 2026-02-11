/**
 * Базовый компонент модального окна
 * Рендер через портал в body, чтобы модалка всегда была поверх всего
 */

import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import './Modal.css';

const Modal = ({ isOpen, onClose, title, children, size = 'medium', hideHeader = false, centerBody = false, variant = 'default' }) => {
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  useEffect(() => {
    const handleEscape = (e) => {
      if (e.key === 'Escape' && isOpen) onClose();
    };
    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const portalTarget = typeof document !== 'undefined' && document.getElementById('modal-root');
  const target = portalTarget || (typeof document !== 'undefined' ? document.body : null);
  if (!target) return null;

  const isModern = variant === 'modern';
  const content = (
    <div
      className={`app-modal ${isModern ? 'app-modal--modern' : ''}`}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
      role="dialog"
      aria-modal="true"
    >
      <div
        className={`app-modal-content modal-${size} ${isModern ? 'app-modal-content--modern' : ''}`}
        onClick={(e) => e.stopPropagation()}
      >
        {!hideHeader && (
          <div className={`app-modal-header ${isModern ? 'app-modal-header--modern' : ''} ${title ? '' : 'app-modal-header--close-only'}`}>
            {title != null && title !== '' && <h2>{title}</h2>}
            <span className={`app-modal-close ${isModern ? 'app-modal-close--modern' : ''}`} onClick={onClose} aria-label="Закрыть">&times;</span>
          </div>
        )}
        {hideHeader && (
          <span className="app-modal-close app-modal-close--float" onClick={onClose} aria-label="Закрыть">&times;</span>
        )}
        <div className={`app-modal-body ${isModern ? 'app-modal-body--modern' : ''} ${centerBody ? 'app-modal-body--center' : ''}`}>
          {children}
        </div>
      </div>
    </div>
  );

  return createPortal(content, target);
};

export default Modal;

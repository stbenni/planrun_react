/**
 * Базовый компонент модального окна
 * Рендер через портал в body, чтобы модалка всегда была поверх всего
 */

import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon } from './Icons';
import './Modal.css';

const Modal = ({
  isOpen,
  onClose,
  title,
  children,
  size = 'medium',
  hideHeader = false,
  centerBody = false,
  variant = 'default',
  headerActions = null,
  headerSubtitle = null,
  contentClassName = '',
  bodyClassName = '',
  mobilePresentation = 'default',
}) => {
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
  const isMobileFullscreen = mobilePresentation === 'fullscreen';
  const content = (
    <div
      className={`app-modal ${isModern ? 'app-modal--modern' : ''} ${isMobileFullscreen ? 'app-modal--mobile-fullscreen' : ''}`.trim()}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
      role="dialog"
      aria-modal="true"
    >
      <div
        className={`app-modal-content modal-${size} ${isModern ? 'app-modal-content--modern' : ''} ${isMobileFullscreen ? 'app-modal-content--mobile-fullscreen' : ''} ${contentClassName}`.trim()}
        onClick={(e) => e.stopPropagation()}
      >
        {!hideHeader && (
          <div className={`app-modal-header ${isModern ? 'app-modal-header--modern' : ''} ${isMobileFullscreen ? 'app-modal-header--mobile-fullscreen' : ''} ${title ? '' : 'app-modal-header--close-only'}`.trim()}>
            <div className="app-modal-header-left">
              {title != null && title !== '' && <h2>{title}</h2>}
              {headerSubtitle && <div className="app-modal-header-subtitle">{headerSubtitle}</div>}
            </div>
            <div className="app-modal-header-right">
              {headerActions}
              <span className={`app-modal-close ${isModern ? 'app-modal-close--modern' : ''}`} onClick={onClose} aria-label="Закрыть">
                <CloseIcon className="modal-close-icon" />
              </span>
            </div>
          </div>
        )}
        {hideHeader && (
          <span className="app-modal-close app-modal-close--float" onClick={onClose} aria-label="Закрыть">
            <CloseIcon className="modal-close-icon" />
          </span>
        )}
        <div className={`app-modal-body ${isModern ? 'app-modal-body--modern' : ''} ${isMobileFullscreen ? 'app-modal-body--mobile-fullscreen' : ''} ${centerBody ? 'app-modal-body--center' : ''} ${bodyClassName}`.trim()}>
          {children}
        </div>
      </div>
    </div>
  );

  return createPortal(content, target);
};

export default Modal;

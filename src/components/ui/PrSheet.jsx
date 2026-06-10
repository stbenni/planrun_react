import { useEffect } from 'react';
import { createPortal } from 'react-dom';

export default function PrSheet({ open, onClose, title, children, maxWidth = 560 }) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') onClose?.();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  return createPortal(
    <div className="pr-sheet-overlay" onClick={onClose}>
      <div
        className="pr-sheet"
        style={{ maxWidth }}
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="pr-sheet-handle" />
        {(title != null || onClose) && (
          <div className="pr-sheet-head">
            <div className="pr-sheet-title">{title}</div>
            {onClose && (
              <button type="button" className="pr-sheet-close" onClick={onClose} aria-label="Закрыть">
                ✕
              </button>
            )}
          </div>
        )}
        <div className="pr-sheet-body">{children}</div>
      </div>
    </div>,
    document.getElementById('modal-root') || document.body
  );
}

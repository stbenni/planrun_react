/**
 * BulkAssignModal — мастер «Назначить тренировку» из 3 шагов.
 *
 * Step 1: выбор шаблона тренировки.
 * Step 2: выбор атлетов (с предзаполнением по selected) + быстрые чипы групп.
 * Step 3: дата + сводка + кнопка «Назначить».
 *
 * Mock-шаблоны временно из workoutTemplatesMock.js. Бэк API: Фаза 2.4.
 */

import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon, ClipboardListIcon } from '../common/Icons';
import { CoachAvatar, WORKOUT_TYPE_COLOR } from './CoachPrimitives';
import { getTemplateIcon } from './templateIcons';
import './BulkAssignModal.css';

const DATE_PRESETS = [
  { id: 'today', label: 'сегодня', offset: 0 },
  { id: 'tomorrow', label: 'завтра', offset: 1 },
  { id: 'day_after', label: 'послезавтра', offset: 2 },
];

function dateForOffset(offset) {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + offset);
  return d;
}

function formatIsoDate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function formatHumanDate(d) {
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'short' });
}

export default function BulkAssignModal({
  isOpen,
  onClose,
  athletes,
  groups,
  templates = [],
  initialSelected,
  busy = false,
  onConfirm, // ({ template_id, athlete_ids, date }) => void
}) {
  const [step, setStep] = useState(1);
  const [templateId, setTemplateId] = useState(null);
  const [selectedIds, setSelectedIds] = useState(() => new Set(initialSelected || []));
  const [datePreset, setDatePreset] = useState('tomorrow');
  const [customDate, setCustomDate] = useState('');

  // Сброс при открытии: если есть pre-selected — стартуем со step 1, но selected уже заполнен.
  useEffect(() => {
    if (!isOpen) return;
    setStep(1);
    setTemplateId(templates[0]?.id ?? null);
    setSelectedIds(new Set(initialSelected || []));
    setDatePreset('tomorrow');
    setCustomDate('');
  }, [isOpen, initialSelected, templates]);

  // Если открыли из «Применить шаблон» (атлеты уже выбраны) — Step 2 не нужен.
  const skipAthletesStep = Array.isArray(initialSelected) && initialSelected.length > 0;

  useEffect(() => {
    if (!isOpen) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [isOpen, onClose]);

  const template = useMemo(
    () => templates.find((t) => String(t.id) === String(templateId)),
    [templates, templateId]
  );

  const selectedAthletes = useMemo(
    () => athletes.filter((a) => selectedIds.has(a.id)),
    [athletes, selectedIds]
  );

  const finalDate = useMemo(() => {
    if (datePreset === 'custom' && customDate) return customDate;
    const preset = DATE_PRESETS.find((p) => p.id === datePreset);
    if (preset) return formatIsoDate(dateForOffset(preset.offset));
    return formatIsoDate(dateForOffset(1));
  }, [datePreset, customDate]);

  const canGoNext =
    (step === 1 && !!templateId) ||
    (step === 2 && selectedIds.size > 0) ||
    (step === 3 && !!finalDate && selectedIds.size > 0 && !!templateId);

  const goNext = () => {
    if (step === 1 && skipAthletesStep) {
      setStep(3);
    } else {
      setStep(step + 1);
    }
  };

  const goBack = () => {
    if (step === 3 && skipAthletesStep) {
      setStep(1);
    } else {
      setStep(step - 1);
    }
  };

  const toggle = (id) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const addGroup = (groupId) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      athletes.forEach((a) => {
        const gs = Array.isArray(a.groups) ? a.groups : [];
        if (gs.some((g) => String(g.id) === String(groupId))) next.add(a.id);
      });
      return next;
    });
  };

  const addAll = () => setSelectedIds(new Set(athletes.map((a) => a.id)));
  const clearAll = () => setSelectedIds(new Set());

  const handleConfirm = () => {
    onConfirm?.({
      template_id: templateId,
      athlete_ids: Array.from(selectedIds),
      date: finalDate,
    });
  };

  if (!isOpen) return null;

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const content = (
    <>
      <div className="coach-assign__scrim" onClick={onClose} aria-hidden />
      <div className="coach-assign" role="dialog" aria-modal="true" aria-label="Назначить тренировку">
        <header className="coach-assign__head">
          <div>
            <div className="coach-assign__eyebrow">ШАГ {step} ИЗ 3</div>
            <h2 className="coach-assign__title">
              {step === 1 && 'Выберите шаблон тренировки'}
              {step === 2 && 'Кому назначаем'}
              {step === 3 && 'Когда и подтверждение'}
            </h2>
          </div>
          <button type="button" className="coach-assign__close" onClick={onClose} aria-label="Закрыть">
            <CloseIcon size={18} />
          </button>
        </header>

        <div className="coach-assign__step-bar" aria-hidden>
          {[1, 2, 3].map((n) => (
            <div
              key={n}
              className={`coach-assign__step-bar-item ${n <= step ? 'coach-assign__step-bar-item--active' : ''}`}
            />
          ))}
        </div>

        <div className="coach-assign__body">
          {step === 1 && (
            templates.length === 0 ? (
              <div className="coach-assign__empty">
                У вас пока нет шаблонов. Создайте их в разделе «Шаблоны» на дашборде тренера.
              </div>
            ) : (
            <div className="coach-assign__templates">
              {templates.map((t) => {
                const active = String(templateId) === String(t.id);
                return (
                  <button
                    key={t.id}
                    type="button"
                    className={`coach-assign__template ${active ? 'coach-assign__template--active' : ''}`}
                    onClick={() => setTemplateId(t.id)}
                  >
                    <div className="coach-assign__template-head">
                      {(() => {
                        const TIcon = getTemplateIcon(t.emoji) || ClipboardListIcon;
                        return <span className="coach-assign__template-emoji" aria-hidden><TIcon size={18} /></span>;
                      })()}
                      <div className="coach-assign__template-info">
                        <div className="coach-assign__template-name">{t.name}</div>
                        <div className="coach-assign__template-meta">
                          {t.distance > 0 ? `${t.distance} км · ` : ''}
                          использован {t.uses_count} раз
                        </div>
                      </div>
                    </div>
                    <div className="coach-assign__template-desc">{t.description}</div>
                  </button>
                );
              })}
            </div>
            )
          )}

          {step === 2 && (
            <>
              <div className="coach-assign__quick-chips">
                {groups.map((g) => (
                  <button
                    key={g.id}
                    type="button"
                    className="coach-assign__chip coach-assign__chip--ghost"
                    onClick={() => addGroup(g.id)}
                  >
                    <span className="coach-assign__chip-dot" style={{ background: g.color || 'var(--primary-500)' }} />
                    + вся группа «{g.name}»
                  </button>
                ))}
                <button type="button" className="coach-assign__chip coach-assign__chip--ghost" onClick={addAll}>
                  + Все атлеты
                </button>
                <button type="button" className="coach-assign__chip coach-assign__chip--danger" onClick={clearAll}>
                  Очистить
                </button>
              </div>
              <div className="coach-assign__athletes">
                {athletes.map((a) => {
                  const sel = selectedIds.has(a.id);
                  return (
                    <button
                      key={a.id}
                      type="button"
                      className={`coach-assign__athlete ${sel ? 'coach-assign__athlete--active' : ''}`}
                      onClick={() => toggle(a.id)}
                    >
                      <input type="checkbox" checked={sel} readOnly tabIndex={-1} />
                      <CoachAvatar athlete={a} size={28} />
                      <div className="coach-assign__athlete-info">
                        <div className="coach-assign__athlete-name">{a.name || a.username}</div>
                        <div className="coach-assign__athlete-meta">
                          {a.race_distance || a.goal_type || '—'}
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </>
          )}

          {step === 3 && (
            <>
              <section className="coach-assign__summary">
                <div className="coach-assign__section-label">СВОДКА</div>
                <div className="coach-assign__summary-card">
                  {(() => {
                    const TIcon = getTemplateIcon(template?.emoji) || ClipboardListIcon;
                    return <span className="coach-assign__template-emoji" aria-hidden><TIcon size={18} /></span>;
                  })()}
                  <div>
                    <div className="coach-assign__summary-name" style={{ color: WORKOUT_TYPE_COLOR[template?.type] }}>
                      {template?.name}
                    </div>
                    <div className="coach-assign__summary-desc">{template?.description}</div>
                  </div>
                </div>
              </section>

              <section className="coach-assign__section">
                <div className="coach-assign__section-label">КОГДА</div>
                <div className="coach-assign__date-chips">
                  {DATE_PRESETS.map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      className={`coach-assign__chip ${datePreset === p.id ? 'coach-assign__chip--active' : ''}`}
                      onClick={() => setDatePreset(p.id)}
                    >
                      {p.label}
                    </button>
                  ))}
                  <button
                    type="button"
                    className={`coach-assign__chip ${datePreset === 'custom' ? 'coach-assign__chip--active' : ''}`}
                    onClick={() => setDatePreset('custom')}
                  >
                    выбрать дату…
                  </button>
                </div>
                {datePreset === 'custom' && (
                  <input
                    type="date"
                    value={customDate}
                    onChange={(e) => setCustomDate(e.target.value)}
                    className="coach-assign__date-input"
                  />
                )}
                <div className="coach-assign__date-hint">
                  {formatHumanDate(new Date(`${finalDate}T00:00:00`))}
                </div>
              </section>

              <section className="coach-assign__section">
                <div className="coach-assign__section-label">АТЛЕТОВ · {selectedAthletes.length}</div>
                <div className="coach-assign__pills">
                  {selectedAthletes.map((a) => (
                    <div key={a.id} className="coach-assign__pill">
                      <CoachAvatar athlete={a} size={22} />
                      <span>{(a.name || a.username || '').split(/\s+/)[0]}</span>
                    </div>
                  ))}
                </div>
              </section>
            </>
          )}
        </div>

        <footer className="coach-assign__foot">
          {step > 1 ? (
            <button type="button" className="coach-assign__btn coach-assign__btn--ghost" onClick={goBack}>
              ← Назад
            </button>
          ) : <span />}
          <span style={{ flex: 1 }} />
          {step < 3 ? (
            <button
              type="button"
              className="coach-assign__btn coach-assign__btn--primary"
              onClick={goNext}
              disabled={!canGoNext}
            >
              Дальше →
            </button>
          ) : (
            <button
              type="button"
              className="coach-assign__btn coach-assign__btn--success"
              onClick={handleConfirm}
              disabled={!canGoNext || busy}
            >
              {busy ? 'Назначаю…' : `✓ Назначить · ${selectedAthletes.length} атлетам`}
            </button>
          )}
        </footer>
      </div>
    </>
  );

  return createPortal(content, target);
}

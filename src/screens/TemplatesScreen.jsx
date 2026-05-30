/**
 * TemplatesScreen — управление шаблонами тренировок тренера.
 * Маршрут: /library (только для role coach/admin).
 *
 * Список шаблонов карточками + CTA «+ Создать шаблон» + редактирование/удаление.
 * Редактор открывается через TemplateEditorModal.
 */

import { useEffect, useState, useCallback } from 'react';
import useAuthStore from '../stores/useAuthStore';
import useCoachStore from '../stores/useCoachStore';
import TemplateEditorModal from '../components/Coach/TemplateEditorModal';
import { WORKOUT_TYPE_COLOR } from '../components/Coach/CoachPrimitives';
import { getTemplateIcon } from '../components/Coach/templateIcons';
import { PenLineIcon, CloseIcon, PlusIcon, ClipboardListIcon } from '../components/common/Icons';
import './TemplatesScreen.css';

const TYPE_LABELS = {
  rest: 'Отдых', tempo: 'Темповая', interval: 'Интервалы', long: 'Длительная',
  race: 'Гонка', other: 'ОФП', free: 'Свободно', easy: 'Лёгкая', sbu: 'СБУ',
  fartlek: 'Фартлек', control: 'Контрольная', walking: 'Ходьба',
};

export default function TemplatesScreen() {
  const { api } = useAuthStore();
  const templates = useCoachStore((s) => s.templates);
  const reloadTemplates = useCoachStore((s) => s.reloadTemplates);

  const [editorOpen, setEditorOpen] = useState(false);
  const [editing, setEditing] = useState(null); // null = create, object = edit
  const [exerciseLibrary, setExerciseLibrary] = useState([]);
  const [busy, setBusy] = useState(false);

  // Load exercise library on mount
  useEffect(() => {
    if (!api) return;
    let cancelled = false;
    api.listExerciseLibrary()
      .then((res) => {
        if (cancelled) return;
        const list = res?.data?.exercises || res?.exercises || [];
        setExerciseLibrary(list);
      })
      .catch((e) => console.error('listExerciseLibrary error:', e));
    return () => { cancelled = true; };
  }, [api]);

  // Reload templates on mount
  useEffect(() => {
    if (api) reloadTemplates(api);
  }, [api, reloadTemplates]);

  const handleSave = useCallback(async (templateData) => {
    if (!api) return;
    setBusy(true);
    try {
      await api.saveWorkoutTemplate(templateData);
      await reloadTemplates(api);
      setEditorOpen(false);
      setEditing(null);
    } catch (e) {
      console.error('saveTemplate error:', e);
      alert(e?.message || 'Не удалось сохранить шаблон');
    } finally {
      setBusy(false);
    }
  }, [api, reloadTemplates]);

  const handleDelete = useCallback(async (templateId) => {
    if (!api) return;
    if (!window.confirm('Удалить шаблон? Это действие нельзя отменить.')) return;
    setBusy(true);
    try {
      await api.deleteWorkoutTemplate(templateId);
      await reloadTemplates(api);
    } catch (e) {
      console.error('deleteTemplate error:', e);
      alert(e?.message || 'Не удалось удалить шаблон');
    } finally {
      setBusy(false);
    }
  }, [api, reloadTemplates]);

  return (
    <div className="templates-screen">
      <div className="templates-screen__header">
        <div>
          <h1 className="templates-screen__title">Шаблоны тренировок</h1>
          <p className="templates-screen__subtitle">
            Готовые тренировки для массового назначения атлетам через мастер
          </p>
        </div>
        <button
          type="button"
          className="templates-screen__cta"
          onClick={() => { setEditing(null); setEditorOpen(true); }}
        >
          <PlusIcon size={16} /> Создать шаблон
        </button>
      </div>

      {templates.length === 0 ? (
        <div className="templates-screen__empty">
          <p>У вас пока нет шаблонов</p>
          <button
            type="button"
            className="templates-screen__cta templates-screen__cta--small"
            onClick={() => { setEditing(null); setEditorOpen(true); }}
          >
            Создать первый шаблон
          </button>
        </div>
      ) : (
        <div className="templates-screen__grid">
          {templates.map((t) => {
            const TplIcon = getTemplateIcon(t.emoji) || ClipboardListIcon;
            return (
            <div key={t.id} className="templates-screen__card">
              <div className="templates-screen__card-head">
                <span className="templates-screen__emoji" aria-hidden><TplIcon size={24} /></span>
                <div className="templates-screen__card-info">
                  <div className="templates-screen__card-name">{t.name}</div>
                  <div className="templates-screen__card-meta">
                    <span
                      className="templates-screen__type-dot"
                      style={{ background: WORKOUT_TYPE_COLOR[t.type] || 'var(--gray-400)' }}
                      aria-hidden
                    />
                    {TYPE_LABELS[t.type] || t.type}
                    {t.distance > 0 ? ` · ${t.distance} км` : ''}
                    {t.is_key_workout ? ' · ключ' : ''}
                  </div>
                </div>
                <span className="templates-screen__uses" title="Сколько раз использован">
                  {t.uses_count || 0}×
                </span>
              </div>
              {t.description && (
                <div className="templates-screen__card-desc">{t.description}</div>
              )}
              {Array.isArray(t.exercises) && t.exercises.length > 0 && (
                <div className="templates-screen__card-exercises">
                  <span className="templates-screen__exercises-label">Упражнения:</span>{' '}
                  {t.exercises.map((ex) => ex.name).join(' · ')}
                </div>
              )}
              <div className="templates-screen__card-actions">
                <button
                  type="button"
                  className="templates-screen__card-btn"
                  onClick={() => { setEditing(t); setEditorOpen(true); }}
                  disabled={busy}
                >
                  <PenLineIcon size={14} /> Редактировать
                </button>
                <button
                  type="button"
                  className="templates-screen__card-btn templates-screen__card-btn--danger"
                  onClick={() => handleDelete(t.id)}
                  disabled={busy}
                >
                  <CloseIcon size={14} /> Удалить
                </button>
              </div>
            </div>
            );
          })}
        </div>
      )}

      <TemplateEditorModal
        isOpen={editorOpen}
        onClose={() => { if (!busy) { setEditorOpen(false); setEditing(null); } }}
        initialTemplate={editing}
        exerciseLibrary={exerciseLibrary}
        busy={busy}
        onSave={handleSave}
      />
    </div>
  );
}

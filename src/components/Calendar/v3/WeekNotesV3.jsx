/* WeekNotesV3 — сворачиваемые заметки к неделе (порт из WeekCalendar, glass-стиль).
   CRUD через api.getWeekNotes/saveWeekNote/deleteWeekNote; права: автор/тренер/админ. */
import React, { useCallback, useEffect, useState } from 'react';
import useAuthStore from '../../../stores/useAuthStore';
import { getDisplayName } from '../../../utils/displayName';

export default function WeekNotesV3({ api, weekStartDate, viewContext, canEdit = false }) {
  const { user } = useAuthStore();
  const [notes, setNotes] = useState([]);
  const [addOpen, setAddOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState(null);
  const [editText, setEditText] = useState('');

  const canManage = useCallback((note) => {
    if (!user || !note) return false;
    return note.author_id == user.id || user.role === 'coach' || user.role === 'admin';
  }, [user]);

  const load = useCallback(async () => {
    if (!weekStartDate || !api?.getWeekNotes) return;
    try {
      const res = await api.getWeekNotes(weekStartDate, viewContext || undefined);
      setNotes(res?.data?.notes ?? res?.notes ?? []);
    } catch { /* silent */ }
  }, [weekStartDate, api, viewContext]);

  useEffect(() => { load(); }, [load]);

  const save = async () => {
    if (!draft.trim() || saving || !weekStartDate) return;
    setSaving(true);
    try {
      await api.saveWeekNote(weekStartDate, draft.trim(), null, viewContext || undefined);
      setDraft('');
      setAddOpen(false);
      await load();
    } catch (e) { alert(e.message || 'Ошибка сохранения заметки'); }
    setSaving(false);
  };

  const update = async (id) => {
    if (!editText.trim() || saving) return;
    setSaving(true);
    try {
      await api.saveWeekNote(weekStartDate, editText.trim(), id, viewContext || undefined);
      setEditId(null);
      setEditText('');
      await load();
    } catch (e) { alert(e.message || 'Ошибка обновления заметки'); }
    setSaving(false);
  };

  const remove = async (id) => {
    if (!window.confirm('Удалить заметку?')) return;
    try {
      await api.deleteWeekNote(id, viewContext || undefined);
      await load();
    } catch (e) { alert(e.message || 'Ошибка удаления'); }
  };

  // если заметок нет и нельзя редактировать — не показываем секцию вовсе
  if (notes.length === 0 && !canEdit) return null;

  return (
    <div className="calv3-card calv3-wknotes">
      <div className="calv3-card-label">ЗАМЕТКИ К НЕДЕЛЕ</div>

      {notes.map((note) => {
        const author = getDisplayName({ first_name: note.author_first_name, last_name: note.author_last_name, username: note.author_username });
        if (editId === note.id) {
          return (
            <div key={note.id} className="calv3-wknote">
              <textarea className="calv3-notes__textarea" value={editText} onChange={(e) => setEditText(e.target.value)} rows={2} maxLength={2000} />
              <div className="calv3-note__edit-btns">
                <button type="button" className="calv3-cta" onClick={() => update(note.id)} disabled={saving}>Сохранить</button>
                <button type="button" className="calv3-cta-ghost" onClick={() => { setEditId(null); setEditText(''); }}>Отмена</button>
              </div>
            </div>
          );
        }
        return (
          <div key={note.id} className="calv3-wknote">
            <div className="calv3-wknote__text">{note.content}</div>
            <div className="calv3-wknote__foot">
              <span className="calv3-wknote__author">— {author}</span>
              {canManage(note) && (
                <span className="calv3-wknote__actions">
                  <button type="button" className="calv3-wknote__btn" onClick={() => { setEditId(note.id); setEditText(note.content); }} aria-label="Редактировать">✎</button>
                  <button type="button" className="calv3-wknote__btn" onClick={() => remove(note.id)} aria-label="Удалить">✕</button>
                </span>
              )}
            </div>
          </div>
        );
      })}

      {canEdit && (addOpen ? (
        <div className="calv3-note__add">
          <textarea className="calv3-notes__textarea" placeholder="Заметка к неделе…" value={draft} onChange={(e) => setDraft(e.target.value)} rows={2} maxLength={2000} autoFocus />
          <div className="calv3-note__edit-btns">
            <button type="button" className="calv3-cta" onClick={save} disabled={!draft.trim() || saving}>{saving ? 'Сохранение…' : 'Отправить'}</button>
            <button type="button" className="calv3-cta-ghost" onClick={() => { setAddOpen(false); setDraft(''); }}>Отмена</button>
          </div>
        </div>
      ) : (
        <button type="button" className="calv3-wknote__add-btn" onClick={() => setAddOpen(true)}>
          {notes.length > 0 ? '+ Ещё заметка' : '+ Добавить заметку'}
        </button>
      ))}
    </div>
  );
}

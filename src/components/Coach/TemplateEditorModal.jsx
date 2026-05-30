/**
 * TemplateEditorModal — редактор шаблона тренировки (create + edit).
 *
 * Поля шаблона: name, type, distance, emoji, description, is_key_workout.
 * Список упражнений с возможностью добавить из exercise_library или ручной ввод,
 * редактировать поля (sets/reps/distance_m/duration_sec/weight_kg/pace/notes),
 * перетаскивать порядок (вверх/вниз кнопками), удалять.
 *
 * Если initialTemplate указан — режим edit (PUT через save_workout_template
 * с template_id; бэк-сервис делает upsert). Если null — create.
 */

import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon, ChevronUpIcon, ChevronDownIcon, PlusIcon } from '../common/Icons';
import { TEMPLATE_ICON_OPTIONS } from './templateIcons';
import './TemplateEditorModal.css';

const CATEGORY_LABELS = { run: 'Бег', ofp: 'ОФП', sbu: 'СБУ' };

const TYPE_OPTIONS = [
  { value: 'easy', label: 'Лёгкая' },
  { value: 'tempo', label: 'Темповая' },
  { value: 'interval', label: 'Интервалы' },
  { value: 'long', label: 'Длительная' },
  { value: 'fartlek', label: 'Фартлек' },
  { value: 'control', label: 'Контрольная' },
  { value: 'race', label: 'Гонка' },
  { value: 'sbu', label: 'СБУ' },
  { value: 'other', label: 'ОФП' },
  { value: 'rest', label: 'Отдых' },
  { value: 'walking', label: 'Ходьба' },
  { value: 'free', label: 'Свободно' },
];


const EMPTY_EXERCISE = () => ({
  exercise_id: null,
  category: 'ofp',
  name: '',
  sets: null,
  reps: null,
  distance_m: null,
  duration_sec: null,
  weight_kg: null,
  pace: null,
  notes: null,
});

export default function TemplateEditorModal({
  isOpen,
  onClose,
  initialTemplate, // null = create, object = edit
  exerciseLibrary = [],
  busy = false,
  onSave,
}) {
  const isEdit = !!initialTemplate;

  const [name, setName] = useState('');
  const [type, setType] = useState('easy');
  const [distance, setDistance] = useState('');
  const [emoji, setEmoji] = useState('');
  const [description, setDescription] = useState('');
  const [isKey, setIsKey] = useState(false);
  const [exercises, setExercises] = useState([]);

  useEffect(() => {
    if (!isOpen) return;
    if (initialTemplate) {
      setName(initialTemplate.name || '');
      setType(initialTemplate.type || 'easy');
      setDistance(initialTemplate.distance != null ? String(initialTemplate.distance) : '');
      setEmoji(initialTemplate.emoji || '');
      setDescription(initialTemplate.description || '');
      setIsKey(!!initialTemplate.is_key_workout);
      setExercises(Array.isArray(initialTemplate.exercises) ? initialTemplate.exercises.map(normalizeExercise) : []);
    } else {
      setName('');
      setType('easy');
      setDistance('');
      setEmoji('');
      setDescription('');
      setIsKey(false);
      setExercises([]);
    }
  }, [isOpen, initialTemplate]);

  useEffect(() => {
    if (!isOpen) return undefined;
    const onKey = (e) => { if (e.key === 'Escape' && !busy) onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [isOpen, onClose, busy]);

  const canSave = name.trim().length > 0 && !!type;

  const addExercise = (preset) => {
    setExercises((prev) => [...prev, preset ? preset : EMPTY_EXERCISE()]);
  };

  const updateExercise = (index, patch) => {
    setExercises((prev) => prev.map((ex, i) => i === index ? { ...ex, ...patch } : ex));
  };

  const removeExercise = (index) => {
    setExercises((prev) => prev.filter((_, i) => i !== index));
  };

  const moveExercise = (index, dir) => {
    setExercises((prev) => {
      const next = [...prev];
      const target = index + dir;
      if (target < 0 || target >= next.length) return prev;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  const handleSave = () => {
    if (!canSave) return;
    const payload = {
      template_id: initialTemplate?.id || undefined,
      name: name.trim(),
      type,
      distance: distance.trim() === '' ? null : Number(distance),
      emoji: emoji || null,
      description: description.trim() || null,
      is_key_workout: isKey ? 1 : 0,
      exercises: exercises.map((ex, i) => ({
        exercise_id: ex.exercise_id || null,
        category: ex.category || 'ofp',
        name: (ex.name || '').trim(),
        sets: toIntOrNull(ex.sets),
        reps: toIntOrNull(ex.reps),
        distance_m: toIntOrNull(ex.distance_m),
        duration_sec: toIntOrNull(ex.duration_sec),
        weight_kg: toFloatOrNull(ex.weight_kg),
        pace: ex.pace || null,
        notes: ex.notes || null,
        order_index: i,
      })).filter((ex) => ex.name.length > 0),
    };
    onSave?.(payload);
  };

  if (!isOpen) return null;

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const content = (
    <>
      <div className="tpl-editor__scrim" onClick={busy ? undefined : onClose} aria-hidden />
      <div className="tpl-editor" role="dialog" aria-modal="true" aria-label={isEdit ? 'Редактировать шаблон' : 'Создать шаблон'}>
        <header className="tpl-editor__head">
          <div>
            <div className="tpl-editor__eyebrow">{isEdit ? 'РЕДАКТИРОВАНИЕ' : 'НОВЫЙ ШАБЛОН'}</div>
            <h2 className="tpl-editor__title">
              {isEdit ? initialTemplate?.name || 'Шаблон тренировки' : 'Создать шаблон тренировки'}
            </h2>
          </div>
          {!busy && (
            <button type="button" className="tpl-editor__close" onClick={onClose} aria-label="Закрыть">
              <CloseIcon size={18} />
            </button>
          )}
        </header>

        <div className="tpl-editor__body">
          {/* Основные поля */}
          <section className="tpl-editor__section">
            <div className="tpl-editor__row">
              <div className="tpl-editor__field tpl-editor__field--name">
                <label className="tpl-editor__label" htmlFor="tpl-name">Название</label>
                <input
                  id="tpl-name"
                  type="text"
                  className="tpl-editor__input"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="Например: «Темповый 4×1 км»"
                />
              </div>
              <div className="tpl-editor__field tpl-editor__field--emoji">
                <label className="tpl-editor__label">Иконка</label>
                <div className="tpl-editor__emoji-row">
                  {TEMPLATE_ICON_OPTIONS.map(({ key, Icon, label }) => (
                    <button
                      key={key}
                      type="button"
                      className={`tpl-editor__emoji ${emoji === key ? 'tpl-editor__emoji--active' : ''}`}
                      onClick={() => setEmoji(emoji === key ? '' : key)}
                      aria-label={label}
                      title={label}
                    >
                      <Icon size={18} />
                    </button>
                  ))}
                </div>
              </div>
            </div>

            <div className="tpl-editor__row">
              <div className="tpl-editor__field">
                <label className="tpl-editor__label" htmlFor="tpl-type">Тип тренировки</label>
                <select
                  id="tpl-type"
                  className="tpl-editor__input"
                  value={type}
                  onChange={(e) => setType(e.target.value)}
                >
                  {TYPE_OPTIONS.map((t) => (
                    <option key={t.value} value={t.value}>{t.label}</option>
                  ))}
                </select>
              </div>
              <div className="tpl-editor__field">
                <label className="tpl-editor__label" htmlFor="tpl-distance">Дистанция (км)</label>
                <input
                  id="tpl-distance"
                  type="number"
                  min="0"
                  step="0.1"
                  className="tpl-editor__input"
                  value={distance}
                  onChange={(e) => setDistance(e.target.value)}
                  placeholder="—"
                />
              </div>
              <div className="tpl-editor__field tpl-editor__field--check">
                <label className="tpl-editor__check">
                  <input
                    type="checkbox"
                    checked={isKey}
                    onChange={(e) => setIsKey(e.target.checked)}
                  />
                  <span>Ключевая тренировка</span>
                </label>
              </div>
            </div>

            <div className="tpl-editor__field">
              <label className="tpl-editor__label" htmlFor="tpl-desc">Описание</label>
              <textarea
                id="tpl-desc"
                rows={3}
                className="tpl-editor__input tpl-editor__textarea"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Например: «Разминка 1.5 км + 4×(1 км @ 4:30 / 400 м трусца) + заминка 1.3 км»"
              />
            </div>
          </section>

          {/* Упражнения */}
          <section className="tpl-editor__section">
            <div className="tpl-editor__section-head">
              <h3 className="tpl-editor__section-title">Упражнения</h3>
              <span className="tpl-editor__section-hint">опционально — для ОФП и СБУ</span>
            </div>
            {exercises.length === 0 && (
              <div className="tpl-editor__empty">Нет упражнений</div>
            )}
            {exercises.map((ex, idx) => (
              <ExerciseRow
                key={idx}
                exercise={ex}
                index={idx}
                total={exercises.length}
                library={exerciseLibrary}
                onChange={(patch) => updateExercise(idx, patch)}
                onRemove={() => removeExercise(idx)}
                onMoveUp={() => moveExercise(idx, -1)}
                onMoveDown={() => moveExercise(idx, 1)}
              />
            ))}
            <ExerciseAdder library={exerciseLibrary} onAdd={addExercise} />
          </section>
        </div>

        <footer className="tpl-editor__foot">
          <button
            type="button"
            className="tpl-editor__btn tpl-editor__btn--ghost"
            onClick={onClose}
            disabled={busy}
          >
            Отмена
          </button>
          <span style={{ flex: 1 }} />
          <button
            type="button"
            className="tpl-editor__btn tpl-editor__btn--primary"
            onClick={handleSave}
            disabled={!canSave || busy}
          >
            {busy ? 'Сохраняю…' : isEdit ? 'Сохранить' : 'Создать шаблон'}
          </button>
        </footer>
      </div>
    </>
  );

  return createPortal(content, target);
}

function ExerciseRow({ exercise, index, total, library, onChange, onRemove, onMoveUp, onMoveDown }) {
  return (
    <div className="tpl-editor__exercise">
      <div className="tpl-editor__exercise-head">
        <span className="tpl-editor__exercise-num">{index + 1}</span>
        <input
          type="text"
          className="tpl-editor__input tpl-editor__exercise-name"
          value={exercise.name || ''}
          onChange={(e) => onChange({ name: e.target.value })}
          placeholder="Название упражнения"
          list={`tpl-ex-lib-${index}`}
        />
        <datalist id={`tpl-ex-lib-${index}`}>
          {library.map((lib) => (
            <option key={lib.id} value={lib.name}>{lib.category}</option>
          ))}
        </datalist>
        <select
          className="tpl-editor__input tpl-editor__exercise-category"
          value={exercise.category || 'ofp'}
          onChange={(e) => onChange({ category: e.target.value })}
          aria-label="Категория"
        >
          <option value="run">Бег</option>
          <option value="ofp">ОФП</option>
          <option value="sbu">СБУ</option>
        </select>
        <div className="tpl-editor__exercise-actions">
          <button type="button" className="tpl-editor__icon-btn" onClick={onMoveUp} disabled={index === 0} aria-label="Вверх"><ChevronUpIcon size={14} /></button>
          <button type="button" className="tpl-editor__icon-btn" onClick={onMoveDown} disabled={index === total - 1} aria-label="Вниз"><ChevronDownIcon size={14} /></button>
          <button type="button" className="tpl-editor__icon-btn tpl-editor__icon-btn--danger" onClick={onRemove} aria-label="Удалить"><CloseIcon size={14} /></button>
        </div>
      </div>
      <div className="tpl-editor__exercise-fields">
        <NumField label="Подходов" value={exercise.sets} onChange={(v) => onChange({ sets: v })} />
        <NumField label="Повторов" value={exercise.reps} onChange={(v) => onChange({ reps: v })} />
        <NumField label="Дистанция (м)" value={exercise.distance_m} onChange={(v) => onChange({ distance_m: v })} step={10} />
        <NumField label="Длит. (сек)" value={exercise.duration_sec} onChange={(v) => onChange({ duration_sec: v })} />
        <NumField label="Вес (кг)" value={exercise.weight_kg} onChange={(v) => onChange({ weight_kg: v })} step={0.5} float />
        <TextField label="Темп" value={exercise.pace} onChange={(v) => onChange({ pace: v })} placeholder="5:30" />
      </div>
    </div>
  );
}

function ExerciseAdder({ library, onAdd }) {
  const [libValue, setLibValue] = useState('');
  const options = useMemo(() => library.slice(0, 50), [library]);

  return (
    <div className="tpl-editor__adder">
      <button
        type="button"
        className="tpl-editor__add-btn"
        onClick={() => onAdd(null)}
      >
        <PlusIcon size={14} /> Добавить упражнение
      </button>
      {options.length > 0 && (
        <div className="tpl-editor__adder-lib">
          <select
            className="tpl-editor__input"
            value={libValue}
            onChange={(e) => {
              const id = e.target.value;
              if (!id) return;
              const lib = library.find((l) => String(l.id) === id);
              if (lib) {
                onAdd({
                  exercise_id: lib.id,
                  category: lib.category,
                  name: lib.name,
                  sets: lib.default_sets ?? null,
                  reps: lib.default_reps ?? null,
                  distance_m: lib.default_distance_m ?? null,
                  duration_sec: lib.default_duration_sec ?? null,
                  weight_kg: lib.default_weight_kg ? Number(lib.default_weight_kg) : null,
                  pace: lib.default_pace ?? null,
                  notes: lib.default_notes ?? null,
                });
              }
              setLibValue('');
            }}
          >
            <option value="">+ из библиотеки…</option>
            {options.map((lib) => (
              <option key={lib.id} value={lib.id}>
                [{CATEGORY_LABELS[lib.category] || lib.category}] {lib.name}
              </option>
            ))}
          </select>
        </div>
      )}
    </div>
  );
}

function NumField({ label, value, onChange, step = 1, float = false }) {
  return (
    <label className="tpl-editor__small-field">
      <span className="tpl-editor__small-label">{label}</span>
      <input
        type="number"
        step={step}
        className="tpl-editor__input tpl-editor__input--num"
        value={value == null ? '' : value}
        onChange={(e) => {
          const v = e.target.value;
          if (v === '') return onChange(null);
          return onChange(float ? parseFloat(v) : parseInt(v, 10));
        }}
      />
    </label>
  );
}

function TextField({ label, value, onChange, placeholder }) {
  return (
    <label className="tpl-editor__small-field">
      <span className="tpl-editor__small-label">{label}</span>
      <input
        type="text"
        className="tpl-editor__input tpl-editor__input--num"
        value={value || ''}
        onChange={(e) => onChange(e.target.value || null)}
        placeholder={placeholder}
      />
    </label>
  );
}

function normalizeExercise(ex) {
  return {
    exercise_id: ex.exercise_id ?? null,
    category: ex.category || 'ofp',
    name: ex.name || '',
    sets: ex.sets ?? null,
    reps: ex.reps ?? null,
    distance_m: ex.distance_m ?? null,
    duration_sec: ex.duration_sec ?? null,
    weight_kg: ex.weight_kg != null ? Number(ex.weight_kg) : null,
    pace: ex.pace ?? null,
    notes: ex.notes ?? null,
  };
}

function toIntOrNull(v) {
  if (v === null || v === undefined || v === '') return null;
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? n : null;
}

function toFloatOrNull(v) {
  if (v === null || v === undefined || v === '') return null;
  const n = parseFloat(v);
  return Number.isFinite(n) ? n : null;
}

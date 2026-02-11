/**
 * Модальное окно ввода результата тренировки.
 * Блоки: Бег (если в плане есть бег), ОФП (запланированные + свои), СБУ (запланированные + свои).
 * Запланированные ОФП/СБУ — вводим «сделано» подходы×повторы или м; крестик = не делал.
 */

import React, { useState, useEffect, useRef } from 'react';
import Modal from '../common/Modal';
import './AddTrainingModal.css';

const RUN_TYPES = ['easy', 'tempo', 'long', 'long-run', 'interval', 'fartlek', 'race'];

const TYPE_OPTIONS = [
  { id: 'run', label: 'Бег', icon: '🏃' },
  { id: 'ofp', label: 'ОФП', icon: '💪' },
  { id: 'sbu', label: 'СБУ', icon: '⚡' },
];

const ResultModal = ({ isOpen, onClose, date, weekNumber, dayKey, api, onSave }) => {
  const [inputMethod, setInputMethod] = useState(null);
  const [formData, setFormData] = useState({ distance: '', time: '', pace: '', heartRate: '', notes: '' });
  const [file, setFile] = useState(null);
  const [loading, setLoading] = useState(false);
  const [dayPlan, setDayPlan] = useState({ planDays: [], dayExercises: [] });
  const [plannedOfp, setPlannedOfp] = useState([]);
  const [plannedSbu, setPlannedSbu] = useState([]);
  const [additionalExercises, setAdditionalExercises] = useState([]);
  const [customNewName, setCustomNewName] = useState('');
  const [customNewCategory, setCustomNewCategory] = useState('ofp');
  const [customNewSets, setCustomNewSets] = useState('');
  const [customNewReps, setCustomNewReps] = useState('');
  const [customNewWeightKg, setCustomNewWeightKg] = useState('');
  const [customNewDistanceM, setCustomNewDistanceM] = useState('');
  const [showOfpCustomForm, setShowOfpCustomForm] = useState(false);
  const [showSbuCustomForm, setShowSbuCustomForm] = useState(false);
  const [extraTypes, setExtraTypes] = useState([]); // типы, добавленные пользователем: 'run' | 'ofp' | 'sbu'
  const [showAddTypeDropdown, setShowAddTypeDropdown] = useState(false);
  const nextCustomIdRef = useRef(0);

  const hasRun = dayPlan.planDays?.some(pd => RUN_TYPES.includes(pd.type));
  const ofpExercises = dayPlan.dayExercises?.filter(ex => (ex.category || '').toLowerCase() === 'ofp') ?? [];
  const sbuExercises = dayPlan.dayExercises?.filter(ex => (ex.category || '').toLowerCase() === 'sbu') ?? [];
  const hasOfpPlan = dayPlan.planDays?.some(pd => pd.type === 'other') || ofpExercises.length > 0;
  const hasSbuPlan = dayPlan.planDays?.some(pd => pd.type === 'sbu') || sbuExercises.length > 0;

  const hasRunBlock = hasRun || extraTypes.includes('run');
  const hasOfpBlock = hasOfpPlan || additionalExercises.some(e => e.category === 'ofp') || extraTypes.includes('ofp');
  const hasSbuBlock = hasSbuPlan || additionalExercises.some(e => e.category === 'sbu') || extraTypes.includes('sbu');
  const availableExtraTypes = TYPE_OPTIONS.filter(t => {
    if (t.id === 'run') return !hasRunBlock;
    if (t.id === 'ofp') return !hasOfpBlock;
    if (t.id === 'sbu') return !hasSbuBlock;
    return false;
  });

  useEffect(() => {
    if (isOpen && date) {
      loadDayPlan();
      loadExistingResult();
    } else {
      setInputMethod(null);
      setFormData({ distance: '', time: '', pace: '', heartRate: '', notes: '' });
      setFile(null);
      setDayPlan({ planDays: [], dayExercises: [] });
      setPlannedOfp([]);
      setPlannedSbu([]);
      setAdditionalExercises([]);
      setCustomNewName('');
      setCustomNewSets('');
      setCustomNewReps('');
      setCustomNewWeightKg('');
      setCustomNewDistanceM('');
      setShowOfpCustomForm(false);
      setShowSbuCustomForm(false);
      setExtraTypes([]);
      setShowAddTypeDropdown(false);
    }
  }, [isOpen, date, weekNumber, dayKey]);

  /** Разворачивает синтетические упражнения (одно с notes из нескольких строк) в список по одной строке — как в модалке добавления */
  const expandDayExercises = (exercises, category) => {
    const result = [];
    exercises.forEach((ex, exIndex) => {
      const baseId = ex.id ?? `${category}-${ex.plan_day_id}-${exIndex}`;
      const hasStructured = ex.sets != null || ex.reps != null || (ex.distance_m != null && category === 'sbu') || (ex.duration_sec != null && category === 'ofp');
      const notes = (ex.notes || '').trim();
      const lines = notes ? notes.split(/\n/).map(s => s.trim()).filter(Boolean) : [];

      if (!hasStructured && lines.length > 0) {
        lines.forEach((line, i) => {
          const dashMatch = line.match(/\s*[—–-]\s*(.*)/);
          const namePart = dashMatch ? line.slice(0, line.search(/\s*[—–-]\s*/)).trim() : line;
          const paramsPart = dashMatch ? dashMatch[1].trim() : '';
          result.push({
            id: `${baseId}-line-${i}`,
            name: namePart || line,
            plannedDescription: line,
            plannedSets: null,
            plannedReps: null,
            plannedWeight: null,
            plannedDistanceM: null,
            plannedDurationSec: null,
            doneSets: '',
            doneReps: '',
            doneWeight: '',
            doneDistanceM: '',
            removed: false,
          });
        });
      } else {
        const weight = ex.weight_kg != null ? Number(ex.weight_kg) : null;
        const durSec = ex.duration_sec != null ? Number(ex.duration_sec) : null;
        let plannedDescription = '';
        if (category === 'ofp') {
          if (ex.sets != null && ex.reps != null) plannedDescription += `${ex.sets}×${ex.reps}`;
          if (weight != null && weight > 0) plannedDescription += (plannedDescription ? ', ' : '') + `${weight} кг`;
          if (durSec != null && durSec > 0 && !plannedDescription) plannedDescription = `${Math.round(durSec / 60)} мин`;
        } else {
          if (ex.distance_m != null) plannedDescription = ex.distance_m >= 1000 ? (ex.distance_m / 1000).toFixed(1) + ' км' : ex.distance_m + ' м';
          if (durSec != null && durSec > 0 && !plannedDescription) plannedDescription = `${Math.round(durSec / 60)} мин`;
        }
        result.push({
          id: baseId,
          name: ex.name,
          plannedDescription: plannedDescription || null,
          plannedSets: ex.sets,
          plannedReps: ex.reps,
          plannedWeight: weight,
          plannedDistanceM: ex.distance_m != null ? Number(ex.distance_m) : null,
          plannedDurationSec: durSec,
          doneSets: '',
          doneReps: '',
          doneWeight: '',
          doneDistanceM: '',
          removed: false,
        });
      }
    });
    return result;
  };

  const loadDayPlan = async () => {
    if (!api?.getDay || !date) return;
    try {
      const res = await api.getDay(date);
      const data = res?.data ?? res;
      const planDays = data?.planDays ?? [];
      const dayExercises = data?.dayExercises ?? [];
      setDayPlan({ planDays, dayExercises });
      const ofp = dayExercises.filter(ex => (ex.category || '').toLowerCase() === 'ofp');
      const sbu = dayExercises.filter(ex => (ex.category || '').toLowerCase() === 'sbu');
      setPlannedOfp(expandDayExercises(ofp, 'ofp'));
      setPlannedSbu(expandDayExercises(sbu, 'sbu'));
    } catch {
      setDayPlan({ planDays: [], dayExercises: [] });
      setPlannedOfp([]);
      setPlannedSbu([]);
    }
  };

  const loadExistingResult = async () => {
    if (!api?.getResult) return;
    try {
      const res = await api.getResult(date);
      const result = res?.data?.result ?? res?.result ?? res;
      if (result && typeof result === 'object') {
        setFormData({
          distance: result.result_distance ?? result.distance_km ?? '',
          time: result.result_time ?? '',
          heartRate: result.avg_heart_rate ?? '',
          notes: result.notes ?? ''
        });
      }
    } catch { /* нет результата */ }
  };

  const updatePlannedOfp = (id, field, value) => {
    setPlannedOfp(prev => prev.map(p => p.id === id ? { ...p, [field]: value } : p));
  };
  const removePlannedOfp = (id) => {
    setPlannedOfp(prev => prev.map(p => p.id === id ? { ...p, removed: true } : p));
  };
  const updatePlannedSbu = (id, field, value) => {
    setPlannedSbu(prev => prev.map(p => p.id === id ? { ...p, [field]: value } : p));
  };
  const removePlannedSbu = (id) => {
    setPlannedSbu(prev => prev.map(p => p.id === id ? { ...p, removed: true } : p));
  };

  const addAdditionalExercise = (categoryOverride) => {
    const name = customNewName.trim();
    if (!name) return;
    const cat = categoryOverride ?? customNewCategory;
    const id = `extra-${++nextCustomIdRef.current}`;
    const item = { id, name, category: cat };
    if (cat === 'sbu') {
      const m = customNewDistanceM.trim() ? parseInt(customNewDistanceM, 10) : undefined;
      if (m != null && !Number.isNaN(m) && m > 0) item.distanceM = m;
    } else {
      const sets = customNewSets.trim() ? parseInt(customNewSets, 10) : undefined;
      const reps = customNewReps.trim() ? parseInt(customNewReps, 10) : undefined;
      const w = customNewWeightKg.trim() ? parseFloat(customNewWeightKg.replace(',', '.')) : undefined;
      if (sets != null && !Number.isNaN(sets)) item.sets = sets;
      if (reps != null && !Number.isNaN(reps)) item.reps = reps;
      if (w != null && !Number.isNaN(w) && w >= 0) item.weightKg = w;
    }
    setAdditionalExercises(prev => [...prev, item]);
    setCustomNewName('');
    setCustomNewSets('');
    setCustomNewReps('');
    setCustomNewWeightKg('');
    setCustomNewDistanceM('');
  };

  const removeAdditionalExercise = (id) => {
    setAdditionalExercises(prev => prev.filter(e => e.id !== id));
  };

  const buildNotes = () => {
    const parts = [];
    plannedOfp.filter(p => !p.removed).forEach(p => {
      const sets = p.doneSets !== '' && p.doneSets != null ? p.doneSets : p.plannedSets;
      const reps = p.doneReps !== '' && p.doneReps != null ? p.doneReps : p.plannedReps;
      const w = p.doneWeight !== '' && p.doneWeight != null ? Number(p.doneWeight) : p.plannedWeight;
      let line = p.name;
      if (sets != null && reps != null) line += ` ${sets}×${reps}`;
      if (w != null && w > 0) line += `, ${w} кг`;
      if (line === p.name && p.plannedDescription) line = p.plannedDescription;
      parts.push('ОФП: ' + line);
    });
    plannedSbu.filter(p => !p.removed).forEach(p => {
      const m = p.doneDistanceM !== '' && p.doneDistanceM != null ? Number(p.doneDistanceM) : p.plannedDistanceM;
      const str = m != null ? (m >= 1000 ? (m / 1000).toFixed(1) + ' км' : m + ' м') : (p.plannedDescription || '');
      if (str || p.name) parts.push(`СБУ: ${p.name}${str ? ' ' + str : ''}`);
    });
    additionalExercises.forEach(e => {
      let t = e.name;
      if (e.category === 'ofp' && (e.sets != null || e.reps != null)) t += ` ${e.sets ?? ''}×${e.reps ?? ''}`;
      if (e.weightKg != null && e.weightKg > 0) t += `, ${e.weightKg} кг`;
      if (e.category === 'sbu' && e.distanceM != null) t += ` ${e.distanceM >= 1000 ? (e.distanceM / 1000).toFixed(1) + ' км' : e.distanceM + ' м'}`;
      parts.push((e.category === 'ofp' ? 'ОФП: ' : 'СБУ: ') + t);
    });
    const notesText = (formData.notes || '').trim();
    return notesText ? notesText + (parts.length ? '\n' + parts.join('\n') : '') : (parts.length ? parts.join('\n') : null);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      if (inputMethod === 'file' && file) {
        await api.uploadWorkout(file, { date });
        alert('Тренировка успешно загружена!');
        onClose();
        if (onSave) onSave();
      } else {
        const week = weekNumber ?? 1;
        const day = dayKey ?? ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][new Date(date + 'T12:00:00').getDay()];
        await api.saveResult({
          date,
          week,
          day,
          activity_type_id: 1,
          result_distance: formData.distance ? parseFloat(formData.distance) : null,
          result_time: formData.time || null,
          avg_heart_rate: formData.heartRate ? parseInt(formData.heartRate, 10) : null,
          notes: buildNotes(),
          is_successful: true,
        });
        alert('Результат сохранен!');
        onClose();
        if (onSave) onSave();
      }
    } catch (err) {
      alert('Ошибка сохранения: ' + (err?.message || 'Неизвестная ошибка'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Отметить тренировку"
      size="medium"
    >
      <div className="result-modal-body">
      {!inputMethod ? (
        <div>
          <div className="form-group">
            <label>Способ ввода данных</label>
            <div style={{ display: 'flex', gap: '10px', marginTop: '10px' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setInputMethod('manual')}
                style={{ flex: 1 }}
              >
                ✏️ Вручную
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setInputMethod('file')}
                style={{ flex: 1 }}
              >
                📤 Загрузить файл
              </button>
            </div>
          </div>
        </div>
      ) : inputMethod === 'manual' ? (
        <form onSubmit={handleSubmit}>
          {/* Основные блоки из плана (без анимации) */}
          {hasOfpPlan && (
            <div className="result-modal-section add-training-library">
              <div className="result-modal-block-title add-training-block-title">💪 ОФП</div>
              {plannedOfp.filter(p => !p.removed).length > 0 && (
                <>
                  <div className="result-modal-planned-subtitle">Запланировано — отметьте сделанное или удалите</div>
                  <div className="add-training-library-list">
                    {plannedOfp.filter(p => !p.removed).map(p => (
                      <div key={p.id} className="add-training-library-item">
                        <span className="add-training-library-name">
                          {p.plannedDescription || (p.name + (p.plannedSets != null && p.plannedReps != null ? ` — ${p.plannedSets}×${p.plannedReps}` : '') + (p.plannedWeight != null && p.plannedWeight > 0 ? `, ${p.plannedWeight} кг` : '') + (p.plannedDurationSec != null && p.plannedDurationSec > 0 ? ` — ${Math.round(p.plannedDurationSec / 60)} мин` : ''))}
                        </span>
                        <div className="add-training-library-ofp-params">
                          <input type="number" min={0} max={20} placeholder="подх." value={p.doneSets} onChange={(e) => updatePlannedOfp(p.id, 'doneSets', e.target.value)} className="add-training-library-ofp-input" title="Сделано подходов" />
                          <span className="add-training-library-ofp-sep">×</span>
                          <input type="number" min={0} max={100} placeholder="повт." value={p.doneReps} onChange={(e) => updatePlannedOfp(p.id, 'doneReps', e.target.value)} className="add-training-library-ofp-input" title="Сделано повторов" />
                          <input type="number" min={0} step={0.5} placeholder="кг" value={p.doneWeight} onChange={(e) => updatePlannedOfp(p.id, 'doneWeight', e.target.value)} className="add-training-library-ofp-input add-training-library-ofp-weight" title="Вес (кг)" />
                        </div>
                        <button type="button" className="btn btn-secondary add-training-custom-remove" onClick={() => removePlannedOfp(p.id)} aria-label="Не делал">×</button>
                      </div>
                    ))}
                  </div>
                </>
              )}
              <div className="result-modal-add-own-wrap">
                {!showOfpCustomForm ? (
                  <button type="button" className="btn btn-secondary result-modal-add-own-btn" onClick={() => setShowOfpCustomForm(true)} aria-label="Добавить своё упражнение">+ Своё упражнение</button>
                ) : (
                  <div className="add-training-custom">
                    <p className="add-training-block-title">Своё упражнение</p>
                    <div className="add-training-custom-row">
                      <input type="text" placeholder="Название упражнения" value={customNewName} onChange={(e) => setCustomNewName(e.target.value)} className="add-training-input add-training-custom-name" />
                      <input type="number" min={1} max={20} placeholder="подх." value={customNewSets} onChange={(e) => setCustomNewSets(e.target.value)} className="add-training-input add-training-custom-ofp" />
                      <span className="add-training-library-ofp-sep">×</span>
                      <input type="number" min={1} max={100} placeholder="повт." value={customNewReps} onChange={(e) => setCustomNewReps(e.target.value)} className="add-training-input add-training-custom-ofp" />
                      <input type="number" min={0} step={0.5} placeholder="кг" value={customNewWeightKg} onChange={(e) => setCustomNewWeightKg(e.target.value)} className="add-training-input add-training-custom-weight" />
                      <button type="button" className="btn btn-secondary add-training-custom-add" onClick={() => addAdditionalExercise('ofp')}>Добавить</button>
                    </div>
                    {additionalExercises.filter(e => e.category === 'ofp').length > 0 && (
                      <ul className="add-training-custom-list">
                        {additionalExercises.filter(e => e.category === 'ofp').map(ex => (
                          <li key={ex.id} className="add-training-custom-list-item">
                            <span className="add-training-custom-list-name">{ex.name}</span>
                            <span className="add-training-custom-list-params">
                              {ex.sets != null && ex.reps != null && `${ex.sets}×${ex.reps}`}
                              {ex.weightKg != null && ex.weightKg > 0 && `, ${ex.weightKg} кг`}
                            </span>
                            <button type="button" className="btn btn-secondary add-training-custom-remove" onClick={() => removeAdditionalExercise(ex.id)} aria-label="Удалить">×</button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}
              </div>
            </div>
          )}

          {hasSbuPlan && (
            <div className="result-modal-section add-training-library">
              <div className="result-modal-block-title add-training-block-title">⚡ СБУ</div>
              {plannedSbu.filter(p => !p.removed).length > 0 && (
                <>
                  <div className="result-modal-planned-subtitle">Запланировано — отметьте дистанцию или удалите</div>
                  <div className="add-training-library-list">
                    {plannedSbu.filter(p => !p.removed).map(p => (
                      <div key={p.id} className="add-training-library-item">
                        <span className="add-training-library-name">
                          {p.plannedDescription || (p.name + (p.plannedDistanceM != null ? ` — ${p.plannedDistanceM >= 1000 ? (p.plannedDistanceM / 1000).toFixed(1) + ' км' : p.plannedDistanceM + ' м'}` : ''))}
                        </span>
                        <div className="add-training-library-sbu-dist">
                          <input type="number" min={0} max={2000} step={10} placeholder="м" value={p.doneDistanceM} onChange={(e) => updatePlannedSbu(p.id, 'doneDistanceM', e.target.value)} className="add-training-library-dist-input" title="Сделано (м)" />
                          <span className="add-training-library-dist-unit">м</span>
                        </div>
                        <button type="button" className="btn btn-secondary add-training-custom-remove" onClick={() => removePlannedSbu(p.id)} aria-label="Не делал">×</button>
                      </div>
                    ))}
                  </div>
                </>
              )}
              <div className="result-modal-add-own-wrap">
                {!showSbuCustomForm ? (
                  <button type="button" className="btn btn-secondary result-modal-add-own-btn" onClick={() => setShowSbuCustomForm(true)} aria-label="Добавить своё упражнение">+ Своё упражнение</button>
                ) : (
                  <div className="add-training-custom">
                    <p className="add-training-block-title">Своё упражнение</p>
                    <div className="add-training-custom-row">
                      <input type="text" placeholder="Название упражнения" value={customNewName} onChange={(e) => setCustomNewName(e.target.value)} className="add-training-input add-training-custom-name" />
                      <input type="number" min={10} max={2000} step={10} placeholder="м" value={customNewDistanceM} onChange={(e) => setCustomNewDistanceM(e.target.value)} className="add-training-input add-training-custom-dist" />
                      <button type="button" className="btn btn-secondary add-training-custom-add" onClick={() => addAdditionalExercise('sbu')}>Добавить</button>
                    </div>
                    {additionalExercises.filter(e => e.category === 'sbu').length > 0 && (
                      <ul className="add-training-custom-list">
                        {additionalExercises.filter(e => e.category === 'sbu').map(ex => (
                          <li key={ex.id} className="add-training-custom-list-item">
                            <span className="add-training-custom-list-name">{ex.name}</span>
                            {ex.distanceM != null && (
                              <span className="add-training-custom-list-params">{ex.distanceM >= 1000 ? (ex.distanceM / 1000).toFixed(1) + ' км' : ex.distanceM + ' м'}</span>
                            )}
                            <button type="button" className="btn btn-secondary add-training-custom-remove" onClick={() => removeAdditionalExercise(ex.id)} aria-label="Удалить">×</button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Добавленные типы — по порядку, с анимацией */}
          {extraTypes.map((typeId) => {
            if (typeId === 'run') {
              return (
                <div key="run" className="result-modal-type-block result-modal-type-block-enter">
                  <div className="result-modal-section add-training-run-calc">
                    <div className="result-modal-block-title add-training-block-title">🏃 Бег</div>
                    <div className="add-training-calc-grid">
                      <div className="form-group">
                        <label>Дистанция (км)</label>
                        <input type="number" step="0.1" min="0" placeholder="5" value={formData.distance} onChange={(e) => setFormData({ ...formData, distance: e.target.value })} className="add-training-input" />
                      </div>
                      <div className="form-group">
                        <label>Время (чч:мм:сс)</label>
                        <input type="text" placeholder="0:30:00" value={formData.time} onChange={(e) => setFormData({ ...formData, time: e.target.value })} className="add-training-input" />
                      </div>
                      <div className="form-group">
                        <label>Темп (мм:сс / км)</label>
                        <input type="text" placeholder="5:30" value={formData.pace} onChange={(e) => setFormData({ ...formData, pace: e.target.value })} className="add-training-input" />
                      </div>
                      <div className="form-group">
                        <label>Пульс</label>
                        <input type="text" placeholder="140-150" value={formData.heartRate} onChange={(e) => setFormData({ ...formData, heartRate: e.target.value })} className="add-training-input" />
                      </div>
                    </div>
                  </div>
                </div>
              );
            }
            if (typeId === 'ofp') {
              return (
                <div key="ofp" className="result-modal-type-block result-modal-type-block-enter">
                  <div className="result-modal-section add-training-library">
                    <div className="result-modal-block-title add-training-block-title">💪 ОФП</div>
                    <div className="result-modal-add-own-wrap">
                      {!showOfpCustomForm ? (
                        <button type="button" className="btn btn-secondary result-modal-add-own-btn" onClick={() => setShowOfpCustomForm(true)} aria-label="Добавить своё упражнение">+ Своё упражнение</button>
                      ) : (
                        <div className="add-training-custom">
                          <p className="add-training-block-title">Своё упражнение</p>
                          <div className="add-training-custom-row">
                            <input type="text" placeholder="Название упражнения" value={customNewName} onChange={(e) => setCustomNewName(e.target.value)} className="add-training-input add-training-custom-name" />
                            <input type="number" min={1} max={20} placeholder="подх." value={customNewSets} onChange={(e) => setCustomNewSets(e.target.value)} className="add-training-input add-training-custom-ofp" />
                            <span className="add-training-library-ofp-sep">×</span>
                            <input type="number" min={1} max={100} placeholder="повт." value={customNewReps} onChange={(e) => setCustomNewReps(e.target.value)} className="add-training-input add-training-custom-ofp" />
                            <input type="number" min={0} step={0.5} placeholder="кг" value={customNewWeightKg} onChange={(e) => setCustomNewWeightKg(e.target.value)} className="add-training-input add-training-custom-weight" />
                            <button type="button" className="btn btn-secondary add-training-custom-add" onClick={() => addAdditionalExercise('ofp')}>Добавить</button>
                          </div>
                          {additionalExercises.filter(e => e.category === 'ofp').length > 0 && (
                            <ul className="add-training-custom-list">
                              {additionalExercises.filter(e => e.category === 'ofp').map(ex => (
                                <li key={ex.id} className="add-training-custom-list-item">
                                  <span className="add-training-custom-list-name">{ex.name}</span>
                                  <span className="add-training-custom-list-params">
                                    {ex.sets != null && ex.reps != null && `${ex.sets}×${ex.reps}`}
                                    {ex.weightKg != null && ex.weightKg > 0 && `, ${ex.weightKg} кг`}
                                  </span>
                                  <button type="button" className="btn btn-secondary add-training-custom-remove" onClick={() => removeAdditionalExercise(ex.id)} aria-label="Удалить">×</button>
                                </li>
                              ))}
                            </ul>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            }
            if (typeId === 'sbu') {
              return (
                <div key="sbu" className="result-modal-type-block result-modal-type-block-enter">
                  <div className="result-modal-section add-training-library">
                    <div className="result-modal-block-title add-training-block-title">⚡ СБУ</div>
                    <div className="result-modal-add-own-wrap">
                      {!showSbuCustomForm ? (
                        <button type="button" className="btn btn-secondary result-modal-add-own-btn" onClick={() => setShowSbuCustomForm(true)} aria-label="Добавить своё упражнение">+ Своё упражнение</button>
                      ) : (
                        <div className="add-training-custom">
                          <p className="add-training-block-title">Своё упражнение</p>
                          <div className="add-training-custom-row">
                            <input type="text" placeholder="Название упражнения" value={customNewName} onChange={(e) => setCustomNewName(e.target.value)} className="add-training-input add-training-custom-name" />
                            <input type="number" min={10} max={2000} step={10} placeholder="м" value={customNewDistanceM} onChange={(e) => setCustomNewDistanceM(e.target.value)} className="add-training-input add-training-custom-dist" />
                            <button type="button" className="btn btn-secondary add-training-custom-add" onClick={() => addAdditionalExercise('sbu')}>Добавить</button>
                          </div>
                          {additionalExercises.filter(e => e.category === 'sbu').length > 0 && (
                            <ul className="add-training-custom-list">
                              {additionalExercises.filter(e => e.category === 'sbu').map(ex => (
                                <li key={ex.id} className="add-training-custom-list-item">
                                  <span className="add-training-custom-list-name">{ex.name}</span>
                                  {ex.distanceM != null && (
                                    <span className="add-training-custom-list-params">{ex.distanceM >= 1000 ? (ex.distanceM / 1000).toFixed(1) + ' км' : ex.distanceM + ' м'}</span>
                                  )}
                                  <button type="button" className="btn btn-secondary add-training-custom-remove" onClick={() => removeAdditionalExercise(ex.id)} aria-label="Удалить">×</button>
                                </li>
                              ))}
                            </ul>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            }
            return null;
          })}

          {!hasOfpPlan && !hasSbuPlan && extraTypes.length === 0 && (
            <p className="result-modal-hint">Добавьте тип тренировки ниже или введите результат через «Загрузить файл» выше.</p>
          )}

          <div className="result-modal-add-type-wrap">
            <button
              type="button"
              className="btn btn-secondary result-modal-add-type-btn"
              onClick={() => setShowAddTypeDropdown(!showAddTypeDropdown)}
              disabled={availableExtraTypes.length === 0}
            >
              + Добавить другой тип тренировки
            </button>
            {showAddTypeDropdown && availableExtraTypes.length > 0 && (
              <div className="result-modal-add-type-dropdown">
                {availableExtraTypes.map(t => (
                  <button
                    key={t.id}
                    type="button"
                    className="result-modal-add-type-option"
                    onClick={() => {
                      setExtraTypes(prev => [...prev, t.id]);
                      setShowAddTypeDropdown(false);
                      if (t.id === 'ofp') setShowOfpCustomForm(true);
                      if (t.id === 'sbu') setShowSbuCustomForm(true);
                    }}
                  >
                    <span className="result-modal-add-type-icon">{t.icon}</span>
                    {t.label}
                  </button>
                ))}
              </div>
            )}
          </div>

          <div className="form-group">
            <label htmlFor="resultNotes">📝 Заметки</label>
            <textarea id="resultNotes" rows="2" value={formData.notes} onChange={(e) => setFormData({ ...formData, notes: e.target.value })} placeholder="Дополнительные заметки..." />
          </div>

          <div className="form-actions">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => setInputMethod(null)}
            >
              ← Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={onClose}
            >
              Отмена
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading}
            >
              {loading ? 'Сохранение...' : '✅ Сохранить'}
            </button>
          </div>
        </form>
      ) : (
        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label htmlFor="workoutFile">📤 Выберите файл (TCX или GPX)</label>
            <input
              type="file"
              id="workoutFile"
              accept=".tcx,.gpx"
              onChange={(e) => setFile(e.target.files[0])}
              required
            />
          </div>

          <div className="form-actions">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => setInputMethod(null)}
            >
              ← Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={onClose}
            >
              Отмена
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !file}
            >
              {loading ? 'Загрузка...' : '📤 Загрузить'}
            </button>
          </div>
        </form>
      )}
      </div>
    </Modal>
  );
};

export default ResultModal;

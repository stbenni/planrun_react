/**
 * Модальное окно ввода результата тренировки.
 * Для простого бега — дистанция/время/темп/пульс.
 * Для интервалов — конструктор интервалов (как в AddTrainingModal).
 * Для фартлека — конструктор фартлека (как в AddTrainingModal).
 * ОФП/СБУ — запланированные + свои.
 */

import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import Modal from '../common/Modal';
import './AddTrainingModal.css';

const RUN_TYPES = ['easy', 'tempo', 'long', 'long-run', 'interval', 'fartlek', 'control', 'race'];
const SIMPLE_RUN_TYPES = ['easy', 'tempo', 'long', 'long-run', 'control', 'race'];

const TYPE_OPTIONS = [
  { id: 'run', label: 'Бег', icon: '🏃' },
  { id: 'ofp', label: 'ОФП', icon: '💪' },
  { id: 'sbu', label: 'СБУ', icon: '⚡' },
];

const TYPE_LABELS = {
  easy: 'Легкий бег', tempo: 'Темповый бег', long: 'Длительный бег',
  'long-run': 'Длительный бег', interval: 'Интервалы', fartlek: 'Фартлек',
  control: 'Контрольный забег', race: 'Соревнование',
};

function parseTime(timeStr) {
  if (!timeStr || !String(timeStr).trim()) return null;
  const parts = String(timeStr).trim().split(':').map(p => parseInt(p, 10));
  if (parts.some(n => Number.isNaN(n) || n < 0)) return null;
  if (parts.length === 3) { const [h, m, s] = parts; if (m >= 60 || s >= 60) return null; return h * 3600 + m * 60 + s; }
  if (parts.length === 2) { const [m, s] = parts; if (m >= 60 || s >= 60) return null; return m * 60 + s; }
  return null;
}
function formatTime(totalSeconds) {
  if (totalSeconds == null || totalSeconds < 0) return '';
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = Math.round(totalSeconds % 60);
  return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
function parsePace(paceStr) {
  if (!paceStr || !String(paceStr).trim()) return null;
  const s = String(paceStr).trim();
  const parts = s.split(':');
  if (parts.length === 1) { const m = parseFloat(parts[0]); return Number.isNaN(m) || m < 0 ? null : m; }
  if (parts.length !== 2) return null;
  const m = parseInt(parts[0], 10), sec = parseInt(parts[1], 10);
  if (Number.isNaN(m) || Number.isNaN(sec) || m < 0 || sec < 0 || sec >= 60) return null;
  return m + sec / 60;
}
function formatPace(minutesPerKm) {
  if (minutesPerKm == null || minutesPerKm <= 0) return '';
  const m = Math.floor(minutesPerKm);
  const s = Math.round((minutesPerKm - m) * 60);
  return `${m}:${String(s).padStart(2, '0')}`;
}

function maskPaceInput(value) {
  const digits = String(value).replace(/\D/g, '').slice(0, 4);
  if (digits.length === 0) return '';
  if (digits.length <= 2) return digits.length === 1 ? digits : `${digits[0]}:${digits[1]}`;
  if (digits.length === 3) return `${digits[0]}:${digits.slice(1)}`;
  return `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

const ResultModal = ({ isOpen, onClose, date, weekNumber, dayKey, api, onSave }) => {
  const [inputMethod, setInputMethod] = useState(null);
  const [runDistance, setRunDistance] = useState('');
  const [runDuration, setRunDuration] = useState('');
  const [runPace, setRunPace] = useState('');
  const [runHR, setRunHR] = useState('');
  const [formData, setFormData] = useState({ notes: '' });
  const [file, setFile] = useState(null);
  const [loading, setLoading] = useState(false);
  const [dayPlan, setDayPlan] = useState({ planDays: [], dayExercises: [] });
  const [plannedOfp, setPlannedOfp] = useState([]);
  const [plannedSbu, setPlannedSbu] = useState([]);
  const [additionalExercises, setAdditionalExercises] = useState([]);
  const [customNewName, setCustomNewName] = useState('');
  const [customNewSets, setCustomNewSets] = useState('');
  const [customNewReps, setCustomNewReps] = useState('');
  const [customNewWeightKg, setCustomNewWeightKg] = useState('');
  const [customNewDistanceM, setCustomNewDistanceM] = useState('');
  const [showOfpCustomForm, setShowOfpCustomForm] = useState(false);
  const [showSbuCustomForm, setShowSbuCustomForm] = useState(false);
  const [extraTypes, setExtraTypes] = useState([]);
  const [showAddTypeDropdown, setShowAddTypeDropdown] = useState(false);
  const nextCustomIdRef = useRef(0);

  // Interval fields
  const [warmupKm, setWarmupKm] = useState('');
  const [warmupPace, setWarmupPace] = useState('');
  const [intervalReps, setIntervalReps] = useState('');
  const [intervalDistM, setIntervalDistM] = useState('');
  const [intervalPace, setIntervalPace] = useState('');
  const [restDistM, setRestDistM] = useState('');
  const [restType, setRestType] = useState('jog');
  const [cooldownKm, setCooldownKm] = useState('');
  const [cooldownPace, setCooldownPace] = useState('');

  // Fartlek fields
  const [fartlekWarmupKm, setFartlekWarmupKm] = useState('');
  const [fartlekSegments, setFartlekSegments] = useState([{ id: 1, reps: '', accelDistM: '', accelPace: '', recoveryDistM: '', recoveryType: 'jog' }]);
  const [fartlekCooldownKm, setFartlekCooldownKm] = useState('');

  const runPlanDay = dayPlan.planDays?.find(pd => RUN_TYPES.includes(pd.type));
  const runType = runPlanDay?.type || null;
  const hasRun = !!runPlanDay;
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

  const dateLabel = date
    ? new Date(date + 'T12:00:00').toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })
    : '';

  const recalcSimpleRun = useCallback((changed, newValue) => {
    const dist = changed === 'runDistance' && newValue !== undefined ? (parseFloat(newValue) || null) : (parseFloat(runDistance) || null);
    const timeSec = changed === 'runDuration' && newValue !== undefined ? parseTime(newValue) : parseTime(runDuration);
    const paceVal = changed === 'runPace' && newValue !== undefined ? (parsePace(newValue) || null) : (parsePace(runPace) || null);
    const paceOk = paceVal != null && paceVal > 0;
    if (changed === 'runPace') {
      if (dist != null && paceOk) setRunDuration(formatTime(Math.round(dist * paceVal * 60)));
    } else if (changed === 'runDistance') {
      if (dist != null && paceOk) setRunDuration(formatTime(Math.round(dist * paceVal * 60)));
    } else if (changed === 'runDuration') {
      if (dist != null && dist > 0 && timeSec != null) setRunPace(formatPace(timeSec / 60 / dist));
    }
  }, [runDistance, runDuration, runPace]);

  // Interval total km
  const intervalTotalKm = useMemo(() => {
    const w = parseFloat(warmupKm) || 0;
    const c = parseFloat(cooldownKm) || 0;
    const reps = parseInt(intervalReps, 10) || 0;
    const distM = parseInt(intervalDistM, 10) || 0;
    const rstM = parseInt(restDistM, 10) || 0;
    if (reps <= 0 && w === 0 && c === 0) return null;
    const total = w + (reps * (distM + rstM)) / 1000 + c;
    return total > 0 ? total : null;
  }, [warmupKm, cooldownKm, intervalReps, intervalDistM, restDistM]);

  // Fartlek total km
  const fartlekTotalKm = useMemo(() => {
    const w = parseFloat(fartlekWarmupKm) || 0;
    const c = parseFloat(fartlekCooldownKm) || 0;
    let segKm = 0;
    fartlekSegments.forEach(seg => {
      const r = parseInt(seg.reps, 10) || 0;
      const a = parseInt(seg.accelDistM, 10) || 0;
      const rec = parseInt(seg.recoveryDistM, 10) || 0;
      segKm += (r * (a + rec)) / 1000;
    });
    const total = w + segKm + c;
    return total > 0 ? total : null;
  }, [fartlekWarmupKm, fartlekCooldownKm, fartlekSegments]);

  useEffect(() => {
    if (isOpen && date) {
      loadDayPlan();
      loadExistingResult();
    } else {
      resetAll();
    }
  }, [isOpen, date, weekNumber, dayKey]);

  const resetAll = () => {
    setInputMethod(null);
    setRunDistance(''); setRunDuration(''); setRunPace(''); setRunHR('');
    setFormData({ notes: '' });
    setFile(null);
    setDayPlan({ planDays: [], dayExercises: [] });
    setPlannedOfp([]); setPlannedSbu([]);
    setAdditionalExercises([]);
    setCustomNewName(''); setCustomNewSets(''); setCustomNewReps(''); setCustomNewWeightKg(''); setCustomNewDistanceM('');
    setShowOfpCustomForm(false); setShowSbuCustomForm(false);
    setExtraTypes([]); setShowAddTypeDropdown(false);
    setWarmupKm(''); setWarmupPace(''); setIntervalReps(''); setIntervalDistM('');
    setIntervalPace(''); setRestDistM(''); setRestType('jog'); setCooldownKm(''); setCooldownPace('');
    setFartlekWarmupKm('');
    setFartlekSegments([{ id: 1, reps: '', accelDistM: '', accelPace: '', recoveryDistM: '', recoveryType: 'jog' }]);
    setFartlekCooldownKm('');
  };

  // Parse description to pre-fill interval/fartlek fields
  useEffect(() => {
    if (!runPlanDay) return;
    const raw = (runPlanDay.description || '').trim();
    if (!raw) return;

    if (runType === 'interval') {
      const warmupMatch = raw.match(/Разминка[:\s]*([\d.,]+)\s*км/i);
      if (warmupMatch) setWarmupKm(warmupMatch[1].replace(',', '.'));
      const warmupPaceMatch = raw.match(/Разминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
      if (warmupPaceMatch) setWarmupPace(warmupPaceMatch[1]);
      const seriesMatch = raw.match(/(\d+)\s*[×x]\s*(\d+)\s*м/i);
      if (seriesMatch) { setIntervalReps(seriesMatch[1]); setIntervalDistM(seriesMatch[2]); }
      const intPaceMatch = raw.match(/в темпе\s+(\d{1,2}:\d{2})/i);
      if (intPaceMatch) setIntervalPace(intPaceMatch[1]);
      const restMatch = raw.match(/пауза\s+(\d+)\s*м\s+(трусцой|ходьбой|отдых)/i);
      if (restMatch) {
        setRestDistM(restMatch[1]);
        setRestType(restMatch[2] === 'ходьбой' ? 'walk' : restMatch[2] === 'отдых' ? 'rest' : 'jog');
      }
      const cooldownMatch = raw.match(/Заминка[:\s]*([\d.,]+)\s*км/i);
      if (cooldownMatch) setCooldownKm(cooldownMatch[1].replace(',', '.'));
      const cooldownPaceMatch = raw.match(/Заминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
      if (cooldownPaceMatch) setCooldownPace(cooldownPaceMatch[1]);
    } else if (runType === 'fartlek') {
      const warmupMatch = raw.match(/Разминка[:\s]*([\d.,]+)\s*км/i);
      if (warmupMatch) setFartlekWarmupKm(warmupMatch[1].replace(',', '.'));
      const cooldownMatch = raw.match(/Заминка[:\s]*([\d.,]+)\s*км/i);
      if (cooldownMatch) setFartlekCooldownKm(cooldownMatch[1].replace(',', '.'));
      const segmentRegex = /(\d+)\s*[×x]\s*(\d+)\s*м\s*(?:в темпе\s+(\d{1,2}:\d{2}))?\s*,?\s*(?:восстановление\s+(\d+)\s*м\s+(трусцой|ходьбой|легким бегом))?/gi;
      const segments = [];
      let m;
      while ((m = segmentRegex.exec(raw)) !== null) {
        segments.push({
          id: segments.length + 1,
          reps: m[1], accelDistM: m[2], accelPace: m[3] || '',
          recoveryDistM: m[4] || '', recoveryType: m[5] === 'ходьбой' ? 'walk' : 'jog',
        });
      }
      if (segments.length > 0) setFartlekSegments(segments);
    } else if (SIMPLE_RUN_TYPES.includes(runType)) {
      const distMatch = raw.match(/([\d.,]+)\s*км/);
      if (distMatch) setRunDistance(distMatch[1].replace(',', '.'));
      const paceMatch = raw.match(/темп[:\s~]*(?:~?\s*)?(\d{1,2}:\d{2})(?:\s*\/?\s*км)?/i)
        || raw.match(/(?:^|[(\s])(\d{1,2}:\d{2})\s*\/\s*км/i);
      if (paceMatch) setRunPace(paceMatch[1]);
    }
  }, [runPlanDay, runType]);

  const expandDayExercises = (exercises, category) => {
    const result = [];
    exercises.forEach((ex, exIndex) => {
      const baseId = ex.id ?? `${category}-${ex.plan_day_id}-${exIndex}`;
      const hasStructured = ex.sets != null || ex.reps != null || (ex.distance_m != null && category === 'sbu') || (ex.duration_sec != null && category === 'ofp');
      const notes = (ex.notes || '').trim();
      const lines = notes ? notes.split(/\n/).map(s => s.trim()).filter(Boolean) : [];

      if (!hasStructured && lines.length > 0) {
        lines.forEach((line, i) => {
          result.push({
            id: `${baseId}-line-${i}`, name: line, plannedDescription: line,
            plannedSets: null, plannedReps: null, plannedWeight: null,
            plannedDistanceM: null, plannedDurationSec: null,
            doneSets: '', doneReps: '', doneWeight: '', doneDistanceM: '', removed: false,
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
          id: baseId, name: ex.name, plannedDescription: plannedDescription || null,
          plannedSets: ex.sets, plannedReps: ex.reps, plannedWeight: weight,
          plannedDistanceM: ex.distance_m != null ? Number(ex.distance_m) : null,
          plannedDurationSec: durSec,
          doneSets: '', doneReps: '', doneWeight: '', doneDistanceM: '', removed: false,
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
      setPlannedOfp(expandDayExercises(dayExercises.filter(ex => (ex.category || '').toLowerCase() === 'ofp'), 'ofp'));
      setPlannedSbu(expandDayExercises(dayExercises.filter(ex => (ex.category || '').toLowerCase() === 'sbu'), 'sbu'));
    } catch {
      setDayPlan({ planDays: [], dayExercises: [] }); setPlannedOfp([]); setPlannedSbu([]);
    }
  };

  const loadExistingResult = async () => {
    if (!api?.getResult) return;
    try {
      const res = await api.getResult(date);
      const result = res?.data?.result ?? res?.result ?? res;
      if (result && typeof result === 'object') {
        const dist = result.result_distance ?? result.distance_km;
        if (dist != null && dist !== '') setRunDistance(String(dist));
        const timeRaw = result.result_time;
        if (timeRaw) { const sec = parseTime(timeRaw); setRunDuration(sec != null ? formatTime(sec) : String(timeRaw)); }
        const paceVal = result.pace ?? result.result_pace ?? result.avg_pace;
        if (paceVal) setRunPace(String(paceVal));
        if (result.avg_heart_rate) setRunHR(String(result.avg_heart_rate));
        setFormData({ notes: result.notes ?? '' });
      }
    } catch { /* нет результата */ }
  };

  const updatePlannedOfp = (id, field, value) => setPlannedOfp(prev => prev.map(p => p.id === id ? { ...p, [field]: value } : p));
  const removePlannedOfp = (id) => setPlannedOfp(prev => prev.map(p => p.id === id ? { ...p, removed: true } : p));
  const updatePlannedSbu = (id, field, value) => setPlannedSbu(prev => prev.map(p => p.id === id ? { ...p, [field]: value } : p));
  const removePlannedSbu = (id) => setPlannedSbu(prev => prev.map(p => p.id === id ? { ...p, removed: true } : p));

  const addAdditionalExercise = (categoryOverride) => {
    const name = customNewName.trim();
    if (!name) return;
    const cat = categoryOverride;
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
    setCustomNewName(''); setCustomNewSets(''); setCustomNewReps(''); setCustomNewWeightKg(''); setCustomNewDistanceM('');
  };

  const removeAdditionalExercise = (id) => setAdditionalExercises(prev => prev.filter(e => e.id !== id));
  const updateAdditionalExercise = (id, field, value) => setAdditionalExercises(prev => prev.map(e => e.id === id ? { ...e, [field]: value } : e));

  const updateFartlekSegment = (id, field, value) => setFartlekSegments(prev => prev.map(s => s.id === id ? { ...s, [field]: value } : s));
  const addFartlekSegment = () => setFartlekSegments(prev => [...prev, { id: Math.max(...prev.map(s => s.id)) + 1, reps: '', accelDistM: '', accelPace: '', recoveryDistM: '', recoveryType: 'jog' }]);
  const removeFartlekSegment = (id) => setFartlekSegments(prev => prev.filter(s => s.id !== id));

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

  const getResultDistance = () => {
    if (runDistance) return parseFloat(runDistance);
    if (runType === 'interval' && intervalTotalKm) return parseFloat(intervalTotalKm.toFixed(2));
    if (runType === 'fartlek' && fartlekTotalKm) return parseFloat(fartlekTotalKm.toFixed(2));
    return null;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      if (inputMethod === 'file' && file) {
        await api.uploadWorkout(file, { date });
        alert('Тренировка успешно загружена!');
        onClose(); if (onSave) onSave();
      } else {
        const week = weekNumber ?? 1;
        const day = dayKey ?? ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][new Date(date + 'T12:00:00').getDay()];
        await api.saveResult({
          date, week, day, activity_type_id: 1,
          result_distance: getResultDistance(),
          result_time: runDuration || null,
          result_pace: runPace || null,
          avg_heart_rate: runHR ? parseInt(runHR, 10) : null,
          notes: buildNotes(),
          is_successful: true,
        });
        alert('Результат сохранен!');
        onClose(); if (onSave) onSave();
      }
    } catch (err) {
      alert('Ошибка сохранения: ' + (err?.message || 'Неизвестная ошибка'));
    } finally { setLoading(false); }
  };

  const renderAdditionalList = (cat) => {
    const items = additionalExercises.filter(e => e.category === cat);
    if (items.length === 0) return null;
    return (
      <div className="add-training-custom-list">
        {items.map(ex => (
          <div key={ex.id} className="add-training-library-item add-training-custom-item">
            <span className="add-training-library-name">{ex.name}</span>
            {cat === 'ofp' && (
              <div className="add-training-library-ofp-params">
                <input type="number" min={1} max={20} placeholder="подх." value={ex.sets ?? ''} onChange={(e) => { const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10); updateAdditionalExercise(ex.id, 'sets', v != null && !Number.isNaN(v) ? v : undefined); }} className="add-training-library-ofp-input" />
                <span className="add-training-library-ofp-sep">×</span>
                <input type="number" min={1} max={100} placeholder="повт." value={ex.reps ?? ''} onChange={(e) => { const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10); updateAdditionalExercise(ex.id, 'reps', v != null && !Number.isNaN(v) ? v : undefined); }} className="add-training-library-ofp-input" />
                <input type="number" min={0} max={500} step={0.5} placeholder="кг" value={ex.weightKg ?? ''} onChange={(e) => { const v = e.target.value === '' ? undefined : parseFloat(e.target.value.replace(',', '.')); updateAdditionalExercise(ex.id, 'weightKg', v != null && !Number.isNaN(v) ? v : undefined); }} className="add-training-library-ofp-input add-training-library-ofp-weight" />
              </div>
            )}
            {cat === 'sbu' && (
              <div className="add-training-library-sbu-dist">
                <input type="number" min={10} max={2000} step={10} value={ex.distanceM ?? ''} onChange={(e) => { const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10); updateAdditionalExercise(ex.id, 'distanceM', v != null && !Number.isNaN(v) ? v : undefined); }} placeholder="м" className="add-training-library-dist-input" />
                <span className="add-training-library-dist-unit">м</span>
              </div>
            )}
            <button type="button" className="add-training-custom-remove-btn" onClick={() => removeAdditionalExercise(ex.id)} aria-label="Удалить" title="Удалить">×</button>
          </div>
        ))}
      </div>
    );
  };

  const renderCustomForm = (cat) => (
    <div className="add-training-custom-row">
      <input type="text" placeholder="Название упражнения" value={customNewName} onChange={(e) => setCustomNewName(e.target.value)} className="add-training-input add-training-custom-name" />
      {cat === 'ofp' && (
        <>
          <input type="number" min={1} max={20} placeholder="подх." value={customNewSets} onChange={(e) => setCustomNewSets(e.target.value)} className="add-training-input add-training-custom-ofp" />
          <span className="add-training-library-ofp-sep">×</span>
          <input type="number" min={1} max={100} placeholder="повт." value={customNewReps} onChange={(e) => setCustomNewReps(e.target.value)} className="add-training-input add-training-custom-ofp" />
          <input type="number" min={0} step={0.5} placeholder="кг" value={customNewWeightKg} onChange={(e) => setCustomNewWeightKg(e.target.value)} className="add-training-input add-training-custom-weight" />
        </>
      )}
      {cat === 'sbu' && (
        <input type="number" min={10} max={2000} step={10} placeholder="м" value={customNewDistanceM} onChange={(e) => setCustomNewDistanceM(e.target.value)} className="add-training-input add-training-custom-dist" />
      )}
      <button type="button" className="btn btn-secondary add-training-custom-add" onClick={() => addAdditionalExercise(cat)}>Добавить</button>
    </div>
  );

  const renderSimpleRunForm = () => (
    <div className="add-training-run-calc">
      <p className="add-training-block-title">{TYPE_LABELS[runType] || 'Бег'}</p>
      {runPlanDay?.description && (
        <p className="result-modal-planned-subtitle">План: {runPlanDay.description}</p>
      )}
      <div className="add-training-calc-grid">
        <div className="form-group"><label>Дистанция (км)</label><input type="number" step="0.1" min="0" placeholder="5" value={runDistance} onChange={(e) => { setRunDistance(e.target.value); recalcSimpleRun('runDistance', e.target.value); }} className="add-training-input" /></div>
        <div className="form-group"><label>Время (чч:мм:сс)</label><input type="text" placeholder="0:30:00" value={runDuration} onChange={(e) => { setRunDuration(e.target.value); recalcSimpleRun('runDuration', e.target.value); }} className="add-training-input" /></div>
        <div className="form-group"><label>Темп (мм:сс / км)</label><input type="text" inputMode="numeric" placeholder="5:30" value={runPace} onChange={(e) => { const v = maskPaceInput(e.target.value); setRunPace(v); recalcSimpleRun('runPace', v); }} className="add-training-input" /></div>
        <div className="form-group"><label>Пульс</label><input type="text" placeholder="140-150" value={runHR} onChange={(e) => setRunHR(e.target.value)} className="add-training-input" /></div>
      </div>
    </div>
  );

  const renderIntervalForm = () => (
    <div className="add-training-interval">
      <p className="add-training-block-title">Интервалы</p>
      {runPlanDay?.description && (
        <p className="result-modal-planned-subtitle">План: {runPlanDay.description}</p>
      )}
      <div className="add-training-calc-grid">
        <div className="form-group"><label>Разминка (км)</label><input type="text" placeholder="2" value={warmupKm} onChange={(e) => setWarmupKm(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Темп разминки (мм:сс)</label><input type="text" inputMode="numeric" placeholder="6:00" value={warmupPace} onChange={(e) => setWarmupPace(maskPaceInput(e.target.value))} className="add-training-input" /></div>
        <div className="form-group"><label>Повторов</label><input type="number" min="1" placeholder="5" value={intervalReps} onChange={(e) => setIntervalReps(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Дистанция интервала (м)</label><input type="number" min="0" placeholder="1000" value={intervalDistM} onChange={(e) => setIntervalDistM(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Темп интервала (мм:сс)</label><input type="text" inputMode="numeric" placeholder="4:00" value={intervalPace} onChange={(e) => setIntervalPace(maskPaceInput(e.target.value))} className="add-training-input" /></div>
        <div className="form-group"><label>Пауза (м)</label><input type="number" min="0" placeholder="400" value={restDistM} onChange={(e) => setRestDistM(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Тип паузы</label>
          <select value={restType} onChange={(e) => setRestType(e.target.value)} className="add-training-select">
            <option value="jog">трусцой</option><option value="walk">ходьбой</option><option value="rest">отдых</option>
          </select>
        </div>
        <div className="form-group"><label>Заминка (км)</label><input type="text" placeholder="2" value={cooldownKm} onChange={(e) => setCooldownKm(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Темп заминки (мм:сс)</label><input type="text" inputMode="numeric" placeholder="6:00" value={cooldownPace} onChange={(e) => setCooldownPace(maskPaceInput(e.target.value))} className="add-training-input" /></div>
      </div>
      {intervalTotalKm != null && <p className="add-training-calc-total">Всего: ~{intervalTotalKm.toFixed(2)} км</p>}
      <div className="add-training-calc-grid" style={{ marginTop: 'var(--space-3)' }}>
        <div className="form-group"><label>Общее время (чч:мм:сс)</label><input type="text" placeholder="0:45:00" value={runDuration} onChange={(e) => setRunDuration(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Средний пульс</label><input type="text" placeholder="150" value={runHR} onChange={(e) => setRunHR(e.target.value)} className="add-training-input" /></div>
      </div>
    </div>
  );

  const renderFartlekForm = () => (
    <div className="add-training-fartlek">
      <p className="add-training-block-title">Фартлек</p>
      {runPlanDay?.description && (
        <p className="result-modal-planned-subtitle">План: {runPlanDay.description}</p>
      )}
      <div className="form-group"><label>Разминка (км)</label><input type="text" placeholder="2" value={fartlekWarmupKm} onChange={(e) => setFartlekWarmupKm(e.target.value)} className="add-training-input" /></div>
      {fartlekSegments.map(seg => (
        <div key={seg.id} className="add-training-fartlek-segment">
          <div className="add-training-calc-grid">
            <div className="form-group"><label>Повторов</label><input type="number" min="1" placeholder="4" value={seg.reps} onChange={(e) => updateFartlekSegment(seg.id, 'reps', e.target.value)} className="add-training-input" /></div>
            <div className="form-group"><label>Ускорение (м)</label><input type="number" min="0" placeholder="200" value={seg.accelDistM} onChange={(e) => updateFartlekSegment(seg.id, 'accelDistM', e.target.value)} className="add-training-input" /></div>
            <div className="form-group"><label>Темп (мм:сс)</label><input type="text" inputMode="numeric" placeholder="4:00" value={seg.accelPace} onChange={(e) => updateFartlekSegment(seg.id, 'accelPace', maskPaceInput(e.target.value))} className="add-training-input" /></div>
            <div className="form-group"><label>Восстановление (м)</label><input type="number" min="0" placeholder="200" value={seg.recoveryDistM} onChange={(e) => updateFartlekSegment(seg.id, 'recoveryDistM', e.target.value)} className="add-training-input" /></div>
            <div className="form-group"><label>Тип восстановления</label>
              <select value={seg.recoveryType} onChange={(e) => updateFartlekSegment(seg.id, 'recoveryType', e.target.value)} className="add-training-select">
                <option value="jog">трусцой</option><option value="walk">ходьбой</option><option value="easy">лёгкий бег</option>
              </select>
            </div>
            {fartlekSegments.length > 1 && (
              <div className="form-group add-training-segment-remove"><button type="button" className="btn btn-secondary" onClick={() => removeFartlekSegment(seg.id)}>Удалить</button></div>
            )}
          </div>
        </div>
      ))}
      <button type="button" className="btn btn-secondary" onClick={addFartlekSegment} style={{ marginBottom: 'var(--space-3)' }}>+ Добавить сегмент</button>
      <div className="form-group"><label>Заминка (км)</label><input type="text" placeholder="2" value={fartlekCooldownKm} onChange={(e) => setFartlekCooldownKm(e.target.value)} className="add-training-input" /></div>
      {fartlekTotalKm != null && <p className="add-training-calc-total">Всего: ~{fartlekTotalKm.toFixed(2)} км</p>}
      <div className="add-training-calc-grid" style={{ marginTop: 'var(--space-3)' }}>
        <div className="form-group"><label>Общее время (чч:мм:сс)</label><input type="text" placeholder="0:40:00" value={runDuration} onChange={(e) => setRunDuration(e.target.value)} className="add-training-input" /></div>
        <div className="form-group"><label>Средний пульс</label><input type="text" placeholder="155" value={runHR} onChange={(e) => setRunHR(e.target.value)} className="add-training-input" /></div>
      </div>
    </div>
  );

  const renderRunBlock = () => {
    if (runType === 'interval') return renderIntervalForm();
    if (runType === 'fartlek') return renderFartlekForm();
    return renderSimpleRunForm();
  };

  const renderExtraRunBlock = () => (
    <div className="add-training-run-calc result-modal-type-block-enter">
      <p className="add-training-block-title">Бег</p>
      <div className="add-training-calc-grid">
        <div className="form-group"><label>Дистанция (км)</label><input type="number" step="0.1" min="0" placeholder="5" value={runDistance} onChange={(e) => { setRunDistance(e.target.value); recalcSimpleRun('runDistance', e.target.value); }} className="add-training-input" /></div>
        <div className="form-group"><label>Время (чч:мм:сс)</label><input type="text" placeholder="0:30:00" value={runDuration} onChange={(e) => { setRunDuration(e.target.value); recalcSimpleRun('runDuration', e.target.value); }} className="add-training-input" /></div>
        <div className="form-group"><label>Темп (мм:сс / км)</label><input type="text" inputMode="numeric" placeholder="5:30" value={runPace} onChange={(e) => { const v = maskPaceInput(e.target.value); setRunPace(v); recalcSimpleRun('runPace', v); }} className="add-training-input" /></div>
        <div className="form-group"><label>Пульс</label><input type="text" placeholder="140-150" value={runHR} onChange={(e) => setRunHR(e.target.value)} className="add-training-input" /></div>
      </div>
    </div>
  );

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Отметить тренировку" size="medium" variant="modern">
      <p className="add-training-date">{dateLabel}</p>

      {!inputMethod ? (
        <div className="add-training-categories">
          <p className="add-training-step-title">Способ ввода данных</p>
          <div className="add-training-cards">
            <button type="button" className="card card--compact card--interactive add-training-category-card" onClick={() => setInputMethod('manual')}>
              <span className="add-training-category-icon">✏️</span>
              <span className="add-training-category-label">Вручную</span>
              <span className="add-training-category-desc">Введите результат</span>
            </button>
            <button type="button" className="card card--compact card--interactive add-training-category-card" onClick={() => setInputMethod('file')}>
              <span className="add-training-category-icon">📤</span>
              <span className="add-training-category-label">Загрузить файл</span>
              <span className="add-training-category-desc">TCX или GPX</span>
            </button>
          </div>
        </div>
      ) : inputMethod === 'manual' ? (
        <form onSubmit={handleSubmit} className="add-training-form">
          <div className="form-group">
            <button type="button" className="btn btn-secondary add-training-back" onClick={() => setInputMethod(null)}>← Назад к выбору</button>
          </div>

          {hasRun && renderRunBlock()}

          {hasOfpPlan && (
            <div className="add-training-library">
              <p className="add-training-block-title">ОФП</p>
              {plannedOfp.filter(p => !p.removed).length > 0 && (
                <>
                  <p className="result-modal-planned-subtitle">Запланировано — отметьте сделанное или удалите</p>
                  <div className="add-training-library-list">
                    {plannedOfp.filter(p => !p.removed).map(p => (
                      <div key={p.id} className="add-training-library-item">
                        <span className="add-training-library-name">{p.name}{p.plannedDescription ? ` (${p.plannedDescription})` : ''}</span>
                        <div className="add-training-library-ofp-params">
                          <input type="number" min={0} max={20} placeholder="подх." value={p.doneSets} onChange={(e) => updatePlannedOfp(p.id, 'doneSets', e.target.value)} className="add-training-library-ofp-input" />
                          <span className="add-training-library-ofp-sep">×</span>
                          <input type="number" min={0} max={100} placeholder="повт." value={p.doneReps} onChange={(e) => updatePlannedOfp(p.id, 'doneReps', e.target.value)} className="add-training-library-ofp-input" />
                          <input type="number" min={0} step={0.5} placeholder="кг" value={p.doneWeight} onChange={(e) => updatePlannedOfp(p.id, 'doneWeight', e.target.value)} className="add-training-library-ofp-input add-training-library-ofp-weight" />
                        </div>
                        <button type="button" className="add-training-custom-remove-btn" onClick={() => removePlannedOfp(p.id)} aria-label="Не делал" title="Не делал">×</button>
                      </div>
                    ))}
                  </div>
                </>
              )}
              <div className="result-modal-add-own-wrap">
                {!showOfpCustomForm ? (
                  <button type="button" className="btn btn-secondary result-modal-add-own-btn" onClick={() => setShowOfpCustomForm(true)}>+ Своё упражнение</button>
                ) : (
                  <div className="add-training-custom">
                    <p className="add-training-block-title">Своё упражнение</p>
                    {renderCustomForm('ofp')}
                    {renderAdditionalList('ofp')}
                  </div>
                )}
              </div>
            </div>
          )}

          {hasSbuPlan && (
            <div className="add-training-library">
              <p className="add-training-block-title">СБУ</p>
              {plannedSbu.filter(p => !p.removed).length > 0 && (
                <>
                  <p className="result-modal-planned-subtitle">Запланировано — отметьте дистанцию или удалите</p>
                  <div className="add-training-library-list">
                    {plannedSbu.filter(p => !p.removed).map(p => (
                      <div key={p.id} className="add-training-library-item">
                        <span className="add-training-library-name">{p.name}{p.plannedDescription ? ` (${p.plannedDescription})` : ''}</span>
                        <div className="add-training-library-sbu-dist">
                          <input type="number" min={0} max={2000} step={10} placeholder="м" value={p.doneDistanceM} onChange={(e) => updatePlannedSbu(p.id, 'doneDistanceM', e.target.value)} className="add-training-library-dist-input" />
                          <span className="add-training-library-dist-unit">м</span>
                        </div>
                        <button type="button" className="add-training-custom-remove-btn" onClick={() => removePlannedSbu(p.id)} aria-label="Не делал" title="Не делал">×</button>
                      </div>
                    ))}
                  </div>
                </>
              )}
              <div className="result-modal-add-own-wrap">
                {!showSbuCustomForm ? (
                  <button type="button" className="btn btn-secondary result-modal-add-own-btn" onClick={() => setShowSbuCustomForm(true)}>+ Своё упражнение</button>
                ) : (
                  <div className="add-training-custom">
                    <p className="add-training-block-title">Своё упражнение</p>
                    {renderCustomForm('sbu')}
                    {renderAdditionalList('sbu')}
                  </div>
                )}
              </div>
            </div>
          )}

          {extraTypes.map(typeId => {
            if (typeId === 'run') return <React.Fragment key="run">{renderExtraRunBlock()}</React.Fragment>;
            if (typeId === 'ofp') return (
              <div key="ofp" className="add-training-library result-modal-type-block-enter">
                <p className="add-training-block-title">ОФП</p>
                <div className="add-training-custom">
                  <p className="add-training-block-title">Своё упражнение</p>
                  {renderCustomForm('ofp')}
                  {renderAdditionalList('ofp')}
                </div>
              </div>
            );
            if (typeId === 'sbu') return (
              <div key="sbu" className="add-training-library result-modal-type-block-enter">
                <p className="add-training-block-title">СБУ</p>
                <div className="add-training-custom">
                  <p className="add-training-block-title">Своё упражнение</p>
                  {renderCustomForm('sbu')}
                  {renderAdditionalList('sbu')}
                </div>
              </div>
            );
            return null;
          })}

          {!hasOfpPlan && !hasSbuPlan && !hasRun && extraTypes.length === 0 && (
            <p className="result-modal-hint">Добавьте тип тренировки ниже или загрузите файл.</p>
          )}

          {availableExtraTypes.length > 0 && (
            <div className="result-modal-add-type-wrap">
              <button type="button" className="btn btn-secondary result-modal-add-type-btn" onClick={() => setShowAddTypeDropdown(!showAddTypeDropdown)}>+ Добавить тип тренировки</button>
              {showAddTypeDropdown && (
                <div className="result-modal-add-type-dropdown">
                  {availableExtraTypes.map(t => (
                    <button key={t.id} type="button" className="result-modal-add-type-option" onClick={() => {
                      setExtraTypes(prev => [...prev, t.id]);
                      setShowAddTypeDropdown(false);
                      if (t.id === 'ofp') setShowOfpCustomForm(true);
                      if (t.id === 'sbu') setShowSbuCustomForm(true);
                    }}>
                      <span className="result-modal-add-type-icon">{t.icon}</span>{t.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}

          <div className="form-group">
            <label htmlFor="resultNotes">Заметки</label>
            <textarea id="resultNotes" rows="2" value={formData.notes} onChange={(e) => setFormData({ ...formData, notes: e.target.value })} placeholder="Дополнительные заметки..." className="add-training-textarea" />
          </div>

          <div className="form-actions">
            <button type="button" className="btn btn-secondary" onClick={onClose}>Отмена</button>
            <button type="submit" className="btn btn-primary" disabled={loading}>{loading ? 'Сохранение...' : 'Сохранить'}</button>
          </div>
        </form>
      ) : (
        <form onSubmit={handleSubmit} className="add-training-form">
          <div className="form-group">
            <button type="button" className="btn btn-secondary add-training-back" onClick={() => setInputMethod(null)}>← Назад к выбору</button>
          </div>
          <div className="form-group">
            <label htmlFor="workoutFile">Выберите файл (TCX или GPX)</label>
            <input type="file" id="workoutFile" accept=".tcx,.gpx" onChange={(e) => setFile(e.target.files[0])} required className="add-training-input" />
          </div>
          <div className="form-actions">
            <button type="button" className="btn btn-secondary" onClick={onClose}>Отмена</button>
            <button type="submit" className="btn btn-primary" disabled={loading || !file}>{loading ? 'Загрузка...' : 'Загрузить'}</button>
          </div>
        </form>
      )}
    </Modal>
  );
};

export default ResultModal;

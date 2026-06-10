/**
 * Модальное окно: добавить тренировку на выбранную дату.
 * Два шага: выбор категории (Бег/ОФП/СБУ) → форма с типом и конструктором/калькулятором.
 */

import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { createPortal } from 'react-dom';
import LogoLoading from '../common/LogoLoading';
import './AddTrainingModal.css';
import { typeColorVar } from './v3/calV3';
import { WORKOUT_TYPE_LABEL as TYPE_NAMES } from '../../utils/workoutTypes';
import useSheetFocus from './v3/useSheetFocus';
import {
  parseTime, formatTime, parsePace, formatPace,
  maskTimeInput, maskPaceInput,
  RUN_TYPES, SIMPLE_RUN_TYPES,
} from '../../utils/workoutFormUtils';

/* Поля калькулятора/конструктора — визуал из прототипа v3 EditWorkoutSheet */
function CalcFieldV3({ label, unit, value, onChange, accent, placeholder, inputMode = 'decimal' }) {
  return (
    <div className="atv3-calc">
      <div className="atv3-calc-lbl">{label}</div>
      <div className="atv3-calc-box">
        <input
          className={`atv3-calc-input${accent ? ' is-accent' : ''}`}
          value={value}
          placeholder={placeholder}
          inputMode={inputMode}
          onChange={(e) => onChange?.(e.target.value)}
        />
        {unit && <span className="atv3-calc-unit">{unit}</span>}
      </div>
    </div>
  );
}

function NumFieldV3({ value, onChange, unit, placeholder }) {
  return (
    <div className="atv3-numfield">
      <input
        className="atv3-numfield-input"
        value={value}
        placeholder={placeholder}
        inputMode="decimal"
        onChange={(e) => onChange?.(e.target.value)}
      />
      <span className="atv3-numfield-unit">{unit}</span>
    </div>
  );
}

const TYPES_BY_CATEGORY = {
  running: [
    { value: 'easy', label: 'Лёгкий бег' },
    { value: 'tempo', label: 'Темповый бег' },
    { value: 'long', label: 'Длительный бег' },
    { value: 'interval', label: 'Интервалы' },
    { value: 'fartlek', label: 'Фартлек' },
    { value: 'control', label: 'Контрольный забег' },
    { value: 'race', label: 'Соревнование' },
  ],
  ofp: [{ value: 'other', label: 'ОФП' }],
  sbu: [{ value: 'sbu', label: 'СБУ' }],
};

/* Единый ряд чипов всех типов (как в прототипе v3 EditWorkoutSheet) — без шага категории. */
const ALL_TYPE_CHIPS = [
  { value: 'easy', label: 'Лёгкий', cat: 'running' },
  { value: 'tempo', label: 'Темповый', cat: 'running' },
  { value: 'interval', label: 'Интервалы', cat: 'running' },
  { value: 'long', label: 'Длительный', cat: 'running' },
  { value: 'fartlek', label: 'Фартлек', cat: 'running' },
  { value: 'control', label: 'Контрольный', cat: 'running' },
  { value: 'race', label: 'Соревнование', cat: 'running' },
  { value: 'sbu', label: 'СБУ', cat: 'sbu' },
  { value: 'other', label: 'ОФП', cat: 'ofp' },
];


const TYPE_TO_CATEGORY = {
  easy: 'running', tempo: 'running', long: 'running', 'long-run': 'running',
  interval: 'running', fartlek: 'running', race: 'running', marathon: 'running', control: 'running',
  other: 'ofp', sbu: 'sbu', rest: 'running', free: 'running',
};

const AddTrainingModal = ({ isOpen, onClose, date, api, onSuccess, initialData, editResultData, viewContext = null }) => {
  const isEdit = !!(initialData && initialData.id);
  const isEditResult = !!(editResultData && editResultData.date);
  const effectiveDate = isEditResult
    ? editResultData.date
    : (isEdit ? (initialData.date || date) : date);

  // step сохранён для гейтов эффектов (всегда 2 — шага категории больше нет, единый ряд чипов)
  const [step, setStep] = useState(2);
  const [category, setCategory] = useState('running');
  const [type, setType] = useState('easy');
  const [description, setDescription] = useState('');
  const [isKeyWorkout, setIsKeyWorkout] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Калькулятор простого бега: дистанция (км), время (ЧЧ:ММ:СС), темп (ММ:СС мин/км)
  const [runDistance, setRunDistance] = useState('');
  const [runDuration, setRunDuration] = useState(''); // формат ЧЧ:ММ:СС, внутри считаем в секундах
  const [runPace, setRunPace] = useState('');
  const [runHR, setRunHR] = useState('');
  const prevRunDurationRef = useRef('');
  const sheetRef = useRef(null);

  // Интервалы
  const [warmupKm, setWarmupKm] = useState('');
  const [warmupPace, setWarmupPace] = useState('');
  const [intervalReps, setIntervalReps] = useState('');
  const [intervalDistM, setIntervalDistM] = useState('');
  const [intervalPace, setIntervalPace] = useState('');
  const [restDistM, setRestDistM] = useState('');
  const [restType, setRestType] = useState('jog');
  const [cooldownKm, setCooldownKm] = useState('');
  const [cooldownPace, setCooldownPace] = useState('');

  // Фартлек: сегменты { reps, accelDistM, accelPace, recoveryDistM, recoveryType }
  const [fartlekWarmupKm, setFartlekWarmupKm] = useState('');
  const [fartlekSegments, setFartlekSegments] = useState([{ id: 1, reps: '', accelDistM: '', accelPace: '', recoveryDistM: '', recoveryType: 'jog' }]);
  const [fartlekCooldownKm, setFartlekCooldownKm] = useState('');

  // Библиотека упражнений (ОФП/СБУ)
  const [libraryExercises, setLibraryExercises] = useState([]);
  const [libraryLoading, setLibraryLoading] = useState(false);
  const [selectedExerciseIds, setSelectedExerciseIds] = useState(new Set());
  /** Для СБУ: дистанция в метрах по exercise_id (переопределение default_distance_m) */
  const [exerciseDistanceOverrides, setExerciseDistanceOverrides] = useState({});
  /** Для ОФП: подходы, повторы, вес (кг) по exercise_id */
  const [exerciseOfpOverrides, setExerciseOfpOverrides] = useState({});
  /** Кастомные упражнения (своё название): { id, name, distanceM?, sets?, reps?, weightKg? } */
  const [customExercises, setCustomExercises] = useState([]);
  /** Поля для добавления одного кастомного упражнения */
  const [customNewName, setCustomNewName] = useState('');
  const [customNewDistanceM, setCustomNewDistanceM] = useState('');
  const [customNewSets, setCustomNewSets] = useState('');
  const [customNewReps, setCustomNewReps] = useState('');
  const [customNewWeightKg, setCustomNewWeightKg] = useState('');
  const nextCustomIdRef = useRef(0);
  const initializedEditExercisesRef = useRef(null);
  const initializedEditRunRef = useRef(null);

  const resetForm = useCallback(() => {
    setStep(2);
    setCategory('running');
    setType('easy');
    setDescription('');
    setRunDistance('');
    setRunDuration('');
    setRunPace('');
    setRunHR('');
    setWarmupKm('');
    setWarmupPace('');
    setIntervalReps('');
    setIntervalDistM('');
    setIntervalPace('');
    setRestDistM('');
    setRestType('jog');
    setCooldownKm('');
    setCooldownPace('');
    setFartlekWarmupKm('');
    setFartlekSegments([{ id: 1, reps: '', accelDistM: '', accelPace: '', recoveryDistM: '', recoveryType: 'jog' }]);
    setFartlekCooldownKm('');
    setSelectedExerciseIds(new Set());
    setExerciseDistanceOverrides({});
    setExerciseOfpOverrides({});
    setCustomExercises([]);
    setCustomNewName('');
    setCustomNewDistanceM('');
    setCustomNewSets('');
    setCustomNewReps('');
    setCustomNewWeightKg('');
  }, []);

  useEffect(() => {
    if (!isOpen) {
      resetForm();
      initializedEditExercisesRef.current = null;
      initializedEditRunRef.current = null;
      return;
    }
    if (editResultData?.date && editResultData?.result != null) {
      const { result, dayData } = editResultData;
      const planDay = dayData?.planDays?.[0];
      const cat = planDay ? (TYPE_TO_CATEGORY[planDay.type] || 'running') : 'running';
      const types = TYPES_BY_CATEGORY[cat] || [];
      const typeVal = planDay && types.some((t) => t.value === planDay.type) ? planDay.type : (types[0]?.value || 'easy');
      setStep(2);
      setCategory(cat);
      setType(typeVal);
      const dist = result.result_distance ?? result.distance_km;
      if (dist != null && dist !== '') setRunDistance(String(dist));
      const timeRaw = result.result_time;
      if (timeRaw) {
        const sec = parseTime(timeRaw);
        setRunDuration(sec != null ? formatTime(sec) : String(timeRaw));
      }
      const paceVal = result.pace ?? result.result_pace ?? result.avg_pace;
      if (paceVal) setRunPace(String(paceVal));
      const notes = result.notes;
      if (notes) setDescription(notes);
      return;
    }
    if (initialData && initialData.id) {
      const cat = TYPE_TO_CATEGORY[initialData.type] || 'running';
      const types = TYPES_BY_CATEGORY[cat] || [];
      const typeVal = types.some((t) => t.value === initialData.type) ? initialData.type : (types[0]?.value || 'easy');
      setStep(2);
      setCategory(cat);
      setType(typeVal);
      setDescription(typeof initialData.description === 'string' ? initialData.description.replace(/<[^>]*>/g, '').trim() : '');
      setIsKeyWorkout(!!initialData.is_key_workout);
    }
  }, [isOpen, initialData, editResultData, resetForm]);

  // В режиме редактирования ОФП/СБУ: восстанавливаем упражнения из структурированных данных или из описания (fallback)
  useEffect(() => {
    if (!isOpen || !isEdit || !initialData?.id || (category !== 'ofp' && category !== 'sbu')) return;
    if (initializedEditExercisesRef.current === initialData.id) return;

    const ids = new Set();
    const distOverrides = {};
    const ofpOverrides = {};
    const customList = [];

    // Структурированные данные из базы (exercises массив с name, sets, reps, weight_kg, exercise_id и т.д.)
    if (initialData.exercises && initialData.exercises.length > 0) {
      for (const ex of initialData.exercises) {
        const libMatch = ex.exercise_id
          ? libraryExercises.find((e) => e.id === ex.exercise_id)
          : libraryExercises.find((e) => (e.name || '').trim().toLowerCase() === (ex.name || '').trim().toLowerCase());

        if (libMatch) {
          ids.add(libMatch.id);
          if (category === 'sbu' && ex.distance_m) {
            distOverrides[libMatch.id] = Number(ex.distance_m);
          }
          if (category === 'ofp') {
            const o = {};
            if (ex.sets) o.sets = Number(ex.sets);
            if (ex.reps) o.reps = Number(ex.reps);
            if (ex.weight_kg != null) o.weightKg = Number(ex.weight_kg);
            if (Object.keys(o).length > 0) ofpOverrides[libMatch.id] = o;
          }
        } else {
          const item = { id: `custom-edit-${ex.order_index ?? customList.length}`, name: ex.name || '' };
          if (category === 'sbu' && ex.distance_m) item.distanceM = Number(ex.distance_m);
          if (category === 'ofp') {
            if (ex.sets) item.sets = Number(ex.sets);
            if (ex.reps) item.reps = Number(ex.reps);
            if (ex.weight_kg != null) item.weightKg = Number(ex.weight_kg);
          }
          customList.push(item);
        }
      }
    } else {
      // Fallback: парсинг из description (для старых данных без exercises)
      const raw = typeof initialData.description === 'string' ? initialData.description.replace(/<[^>]*>/g, ' ') : '';
      const lines = raw.split(/\n/).map((s) => s.trim()).filter(Boolean);
      for (let lineIndex = 0; lineIndex < lines.length; lineIndex++) {
        const line = lines[lineIndex];
        const namePart = (line.split('—')[0] || line.split(' – ')[0] || line).trim();
        const rest = (line.split('—')[1] || line.split(' – ')[1] || '').trim();
        const found = libraryExercises.length > 0 && libraryExercises.find((e) => {
          const n = (e.name || '').trim();
          if (!n) return false;
          return namePart === n || namePart.startsWith(n) || n.startsWith(namePart) || line.startsWith(n);
        });
        if (found) {
          ids.add(found.id);
          if (category === 'sbu') {
            const distMatch = rest.match(/([\d.,]+)\s*(км|м)/);
            if (distMatch) {
              const num = parseFloat(distMatch[1].replace(',', '.'));
              const meters = distMatch[2] === 'км' ? Math.round(num * 1000) : Math.round(num);
              if (!Number.isNaN(meters) && meters > 0) distOverrides[found.id] = meters;
            }
          }
          if (category === 'ofp') {
            const setsRepsMatch = rest.match(/(\d+)\s*[×x]\s*(\d+)/i);
            const weightMatch = rest.match(/([\d.,]+)\s*кг/);
            const o = {};
            if (setsRepsMatch) { o.sets = parseInt(setsRepsMatch[1], 10); o.reps = parseInt(setsRepsMatch[2], 10); }
            if (weightMatch) { const w = parseFloat(weightMatch[1].replace(',', '.')); if (!Number.isNaN(w) && w >= 0) o.weightKg = w; }
            if (Object.keys(o).length > 0) ofpOverrides[found.id] = o;
          }
        } else if (namePart) {
          const item = { id: `custom-edit-${lineIndex}`, name: namePart };
          if (category === 'sbu') {
            const distMatch = rest.match(/([\d.,]+)\s*(км|м)/);
            if (distMatch) {
              const num = parseFloat(distMatch[1].replace(',', '.'));
              const meters = distMatch[2] === 'км' ? Math.round(num * 1000) : Math.round(num);
              if (!Number.isNaN(meters) && meters > 0) item.distanceM = meters;
            }
          }
          if (category === 'ofp') {
            const setsRepsMatch = rest.match(/(\d+)\s*[×x]\s*(\d+)/i);
            const weightMatch = rest.match(/([\d.,]+)\s*кг/);
            if (setsRepsMatch) { item.sets = parseInt(setsRepsMatch[1], 10); item.reps = parseInt(setsRepsMatch[2], 10); }
            if (weightMatch) { const w = parseFloat(weightMatch[1].replace(',', '.')); if (!Number.isNaN(w) && w >= 0) item.weightKg = w; }
          }
          customList.push(item);
        }
      }
    }

    setSelectedExerciseIds(ids);
    if (Object.keys(distOverrides).length > 0) setExerciseDistanceOverrides((prev) => ({ ...prev, ...distOverrides }));
    if (Object.keys(ofpOverrides).length > 0) setExerciseOfpOverrides((prev) => ({ ...prev, ...ofpOverrides }));
    setCustomExercises(customList);
    initializedEditExercisesRef.current = initialData.id;
  }, [isEdit, initialData, category, libraryExercises, isOpen]);

  // В режиме редактирования Бег: восстанавливаем поля калькулятора из description (модалка и AI пишут один формат)
  // Работает и для isEdit (планирование), и для isEditResult (выполненная тренировка — берём описание из плана)
  useEffect(() => {
    if (!isOpen || category !== 'running') return;
    let raw = '';
    let trackingId = null;
    if (isEditResult && editResultData?.dayData) {
      const planDay = editResultData.dayData.planDays?.find(pd => RUN_TYPES.includes(pd.type));
      raw = planDay?.description || '';
      trackingId = `editResult-${editResultData.date}`;
    } else if (isEdit && initialData?.id) {
      raw = typeof initialData.description === 'string' ? initialData.description : '';
      trackingId = initialData.id;
    }
    if (!trackingId || initializedEditRunRef.current === trackingId) return;
    raw = raw.replace(/<[^>]*>/g, ' ').trim();
    if (!raw) { initializedEditRunRef.current = trackingId; return; }

    if (SIMPLE_RUN_TYPES.includes(type)) {
      const distMatch = raw.match(/([\d.,]+)\s*км/);
      const distVal = distMatch ? parseFloat(distMatch[1].replace(',', '.')) : null;
      if (distMatch && !runDistance) setRunDistance(String(distVal));
      const durMatch = raw.match(/или\s+(\d{1,2}:\d{2}(?::\d{2})?)/);
      if (durMatch && !runDuration) setRunDuration(durMatch[1]);
      const paceMatch = raw.match(/темп[:\s~]*(?:~?\s*)?(\d{1,2}:\d{2})(?:\s*\/?\s*км)?/i)
        || raw.match(/(?:^|[(\s])(\d{1,2}:\d{2})\s*\/\s*км/i);
      if (paceMatch && !runPace) setRunPace(paceMatch[1]);
      if (distVal && distVal > 0 && paceMatch && !durMatch && !runDuration) {
        const paceMinutes = parsePace(paceMatch[1]);
        if (paceMinutes != null && paceMinutes > 0) setRunDuration(formatTime(Math.round(distVal * paceMinutes * 60)));
      }
      const hrMatch = raw.match(/пульс[:\s]+(\d+)/i);
      if (hrMatch && !runHR) setRunHR(hrMatch[1]);
      const warmupMatch = raw.match(/Разминка[:\s]*([\d.,]+)\s*км/i);
      if (warmupMatch) setWarmupKm(warmupMatch[1].replace(',', '.'));
      const warmupPaceMatch = raw.match(/Разминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
      if (warmupPaceMatch) setWarmupPace(warmupPaceMatch[1]);
      const cdMatch = raw.match(/Заминка[:\s]*([\d.,]+)\s*км/i);
      if (cdMatch) setCooldownKm(cdMatch[1].replace(',', '.'));
      const cdPaceMatch = raw.match(/Заминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
      if (cdPaceMatch) setCooldownPace(cdPaceMatch[1]);
    } else if (type === 'interval') {
      const warmupMatch = raw.match(/Разминка[:\s]*([\d.,]+)\s*км/i);
      if (warmupMatch) setWarmupKm(warmupMatch[1].replace(',', '.'));
      const warmupPaceMatch = raw.match(/Разминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i) || raw.match(/Разминка[^.]*\((\d{1,2}:\d{2})\)/);
      if (warmupPaceMatch) setWarmupPace(warmupPaceMatch[1]);
      const seriesMatch = raw.match(/(\d+)\s*[×x]\s*(\d+)\s*м/i) || raw.match(/(\d+)\s*[×x]\s*(\d+)м/i);
      if (seriesMatch) { setIntervalReps(seriesMatch[1]); setIntervalDistM(seriesMatch[2]); }
      const intervalPaceMatch = raw.match(/в темпе\s+(\d{1,2}:\d{2})/i) || raw.match(/\((\d{1,2}:\d{2})\)/);
      if (intervalPaceMatch) setIntervalPace(intervalPaceMatch[1]);
      const restMatch = raw.match(/пауза\s+(\d+)\s*м\s+(трусцой|ходьбой|отдых)/i) || raw.match(/отдых\s+(\d+)\s*м\s+(трусцой|ходьбой)/i);
      if (restMatch) { setRestDistM(restMatch[1]); setRestType(restMatch[2] === 'ходьбой' ? 'walk' : restMatch[2] === 'отдых' ? 'rest' : 'jog'); }
      const cooldownMatch = raw.match(/Заминка[:\s]*([\d.,]+)\s*км/i);
      if (cooldownMatch) setCooldownKm(cooldownMatch[1].replace(',', '.'));
      const cooldownPaceMatch = raw.match(/Заминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
      if (cooldownPaceMatch) setCooldownPace(cooldownPaceMatch[1]);
    } else if (type === 'fartlek') {
      const warmupMatch = raw.match(/Разминка[:\s]*([\d.,]+)\s*км/i);
      if (warmupMatch) setFartlekWarmupKm(warmupMatch[1].replace(',', '.'));
      const cooldownMatch = raw.match(/Заминка[:\s]*([\d.,]+)\s*км/i);
      if (cooldownMatch) setFartlekCooldownKm(cooldownMatch[1].replace(',', '.'));
      const segmentRegex = /(\d+)\s*[×x]\s*(\d+)\s*м\s*(?:в темпе\s+(\d{1,2}:\d{2}))?\s*,?\s*(?:восстановление\s+(\d+)\s*м\s+(трусцой|ходьбой|легким бегом))?/gi;
      const segments = [];
      let m;
      while ((m = segmentRegex.exec(raw)) !== null) {
        segments.push({ id: segments.length + 1, reps: m[1], accelDistM: m[2], accelPace: m[3] || '', recoveryDistM: m[4] || '', recoveryType: m[5] === 'ходьбой' ? 'walk' : 'jog' });
      }
      if (segments.length > 0) setFartlekSegments(segments);
    }
    initializedEditRunRef.current = trackingId;
  }, [isEdit, isEditResult, initialData, editResultData, category, type, isOpen, runDistance, runDuration, runPace, runHR]);

  useEffect(() => {
    prevRunDurationRef.current = runDuration;
  }, [runDuration]);

  // Загрузка библиотеки при категории ОФП/СБУ и шаге 2
  useEffect(() => {
    if (!isOpen || step !== 2 || !api || (category !== 'ofp' && category !== 'sbu')) return;
    let cancelled = false;
    setLibraryLoading(true);
    api.request('list_exercise_library', {}, 'GET')
      .then((res) => {
        if (cancelled) return;
        const list = res?.exercises ?? res?.data?.exercises ?? [];
        const cat = (e) => (e.category || '').toLowerCase();
        const filtered = category === 'ofp'
          ? list.filter((e) => ['ofp', 'other', 'strength'].includes(cat(e)))
          : list.filter((e) => ['sbu', 'other'].includes(cat(e)));
        setLibraryExercises(filtered);
      })
      .catch(() => { if (!cancelled) setLibraryExercises([]); })
      .finally(() => { if (!cancelled) setLibraryLoading(false); });
    return () => { cancelled = true; };
  }, [isOpen, step, category, api]);

  // Выбор типа из единого ряда чипов: тип + производная категория (без шага категории)
  const selectType = (t) => {
    const meta = ALL_TYPE_CHIPS.find((x) => x.value === t);
    const cat = meta?.cat || TYPE_TO_CATEGORY[t] || 'running';
    if (cat !== category) {
      setCategory(cat);
      // при переходе на/с силовой сбрасываем выбранные упражнения
      setSelectedExerciseIds(new Set());
      setExerciseDistanceOverrides({});
      setExerciseOfpOverrides({});
      setCustomExercises([]);
    }
    setType(t);
    setStep(2);
  };

  // Калькулятор: при изменении одного поля пересчитывается ровно одно другое.
  // Темп изменили → пересчитывается только время (дистанция не трогаем).
  // Дистанция изменили → пересчитывается только время (темп не трогаем).
  // Время изменили → пересчитывается только темп (дистанция не трогаем).
  const recalcSimpleRun = useCallback((changed, newValue) => {
    const dist = changed === 'runDistance' && newValue !== undefined
      ? (parseFloat(newValue) || null)
      : (parseFloat(runDistance) || null);
    const timeSec = changed === 'runDuration' && newValue !== undefined
      ? parseTime(newValue)
      : parseTime(runDuration);
    const paceVal = changed === 'runPace' && newValue !== undefined
      ? (parsePace(newValue) || null)
      : (parsePace(runPace) || null);

    const paceOk = paceVal != null && paceVal > 0;
    if (changed === 'runPace') {
      // Меняем темп → пересчитываем только время, дистанция сохраняется
      if (dist != null && paceOk) setRunDuration(formatTime(Math.round(dist * paceVal * 60)));
    } else if (changed === 'runDistance') {
      // Меняем дистанцию → пересчитываем только время, темп сохраняется
      if (dist != null && paceOk) setRunDuration(formatTime(Math.round(dist * paceVal * 60)));
    } else if (changed === 'runDuration') {
      // Меняем время → пересчитываем только темп, дистанция сохраняется
      if (dist != null && dist > 0 && timeSec != null) setRunPace(formatPace(timeSec / 60 / dist));
    }
  }, [runDistance, runDuration, runPace]);

  const generateSimpleRunDescription = useCallback(() => {
    const name = TYPE_NAMES[type] || 'Бег';
    let text = name;
    if (runDistance || runDuration) {
      text += ': ';
      if (runDistance) text += runDistance + ' км';
      if (runDuration) text += (runDistance ? ' или ' : '') + runDuration;
    }
    if (runPace) text += ', темп ' + runPace;
    if (runHR) text += ', пульс ' + runHR;
    const extra = [];
    if (warmupKm || warmupPace) extra.push('Разминка: ' + (warmupKm ? warmupKm + ' км' : '') + (warmupPace ? ' в темпе ' + warmupPace : ''));
    if (cooldownKm || cooldownPace) extra.push('Заминка: ' + (cooldownKm ? cooldownKm + ' км' : '') + (cooldownPace ? ' в темпе ' + cooldownPace : ''));
    if (extra.length) text += '. ' + extra.join('. ');
    return text;
  }, [type, runDistance, runDuration, runPace, runHR, warmupKm, warmupPace, cooldownKm, cooldownPace]);

  const generateIntervalDescription = useCallback(() => {
    const parts = [];
    if (warmupKm || warmupPace) parts.push('Разминка: ' + (warmupKm ? warmupKm + ' км' : '') + (warmupPace ? ' в темпе ' + warmupPace : ''));
    if (intervalReps) {
      let s = intervalReps + '×';
      if (intervalDistM) s += intervalDistM + 'м';
      if (intervalPace) s += ' в темпе ' + intervalPace;
      if (restDistM) s += ', пауза ' + restDistM + 'м ' + (restType === 'jog' ? 'трусцой' : restType === 'walk' ? 'ходьбой' : 'отдых');
      parts.push(s);
    }
    if (cooldownKm || cooldownPace) parts.push('Заминка: ' + (cooldownKm ? cooldownKm + ' км' : '') + (cooldownPace ? ' в темпе ' + cooldownPace : ''));
    return parts.join('. ');
  }, [warmupKm, warmupPace, intervalReps, intervalDistM, intervalPace, restDistM, restType, cooldownKm, cooldownPace]);

  const generateFartlekDescription = useCallback(() => {
    const parts = [];
    if (fartlekWarmupKm) parts.push('Разминка: ' + fartlekWarmupKm + ' км');
    fartlekSegments.forEach((seg) => {
      if (!seg.reps) return;
      let s = seg.reps + '×' + (seg.accelDistM ? seg.accelDistM + 'м' : '');
      if (seg.accelPace) s += ' в темпе ' + seg.accelPace;
      if (seg.recoveryDistM) s += ', восстановление ' + seg.recoveryDistM + 'м ' + (seg.recoveryType === 'jog' ? 'трусцой' : seg.recoveryType === 'walk' ? 'ходьбой' : 'легким бегом');
      parts.push(s);
    });
    if (fartlekCooldownKm) parts.push('Заминка: ' + fartlekCooldownKm + ' км');
    return parts.join('. ');
  }, [fartlekWarmupKm, fartlekSegments, fartlekCooldownKm]);

  // Калькулятор: суммарная дистанция интервалов (км)
  const intervalTotalKm = useMemo(() => {
    const w = parseFloat(warmupKm) || 0;
    const c = parseFloat(cooldownKm) || 0;
    const reps = parseInt(intervalReps, 10) || 0;
    const distM = parseInt(intervalDistM, 10) || 0;
    const restM = parseInt(restDistM, 10) || 0;
    if (reps <= 0 && w === 0 && c === 0) return null;
    const intervalKm = (reps * (distM + restM)) / 1000;
    const total = w + intervalKm + c;
    return total > 0 ? total : null;
  }, [warmupKm, cooldownKm, intervalReps, intervalDistM, restDistM]);

  // Калькулятор: суммарная дистанция фартлека (км)
  const fartlekTotalKm = useMemo(() => {
    const w = parseFloat(fartlekWarmupKm) || 0;
    const c = parseFloat(fartlekCooldownKm) || 0;
    let segmentsKm = 0;
    fartlekSegments.forEach((seg) => {
      const reps = parseInt(seg.reps, 10) || 0;
      const accel = parseInt(seg.accelDistM, 10) || 0;
      const rec = parseInt(seg.recoveryDistM, 10) || 0;
      segmentsKm += (reps * (accel + rec)) / 1000;
    });
    const total = w + segmentsKm + c;
    return total > 0 ? total : null;
  }, [fartlekWarmupKm, fartlekSegments, fartlekCooldownKm]);

  // Синхронизация описания при изменении полей конструктора
  useEffect(() => {
    if (category !== 'running') return;
    // При редактировании: не перезаписывать до парсинга (пока калькулятор пустой)
    if (isEdit && initialData?.description) {
      if (SIMPLE_RUN_TYPES.includes(type) && !runDistance && !runDuration && !runPace) return;
      if (type === 'interval' && !intervalReps && !warmupKm && !cooldownKm) return;
      if (type === 'fartlek' && !fartlekWarmupKm && !fartlekSegments[0]?.reps && !fartlekCooldownKm) return;
    }
    if (SIMPLE_RUN_TYPES.includes(type)) setDescription(generateSimpleRunDescription());
    else if (type === 'interval') setDescription(generateIntervalDescription());
    else if (type === 'fartlek') setDescription(generateFartlekDescription());
  }, [category, type, runDistance, runDuration, runPace, runHR, warmupKm, warmupPace, intervalReps, intervalDistM, intervalPace, restDistM, restType, cooldownKm, cooldownPace, fartlekWarmupKm, fartlekSegments, fartlekCooldownKm, generateSimpleRunDescription, generateIntervalDescription, generateFartlekDescription, isEdit, initialData?.description]);

  // Описание из выбранных упражнений библиотеки + кастомных: ОФП — подходы×повторы и вес (кг); СБУ — дистанция (м)
  useEffect(() => {
    if (category !== 'ofp' && category !== 'sbu') return;
    const libraryLines = libraryExercises.filter((e) => selectedExerciseIds.has(e.id)).map((e) => {
      let t = e.name || '';
      const p = [];
      if (category === 'ofp') {
        const ofp = exerciseOfpOverrides[e.id] || {};
        const sets = ofp.sets ?? e.default_sets;
        const reps = ofp.reps ?? e.default_reps;
        const weightKg = ofp.weightKg;
        if (sets != null && sets !== '' && reps != null && reps !== '') p.push(`${sets}×${reps}`);
        if (weightKg != null && weightKg !== '' && !Number.isNaN(Number(weightKg)) && Number(weightKg) > 0) p.push(Number(weightKg) + ' кг');
        if (e.default_duration_sec && !p.length) {
          const s = e.default_duration_sec;
          const m = Math.floor(s / 60);
          const sec = s % 60;
          p.push(m > 0 ? `${m} мин ${sec} сек` : `${sec} сек`);
        }
      } else {
        const distM = exerciseDistanceOverrides[e.id] ?? e.default_distance_m;
        if (distM != null && distM !== '') {
          const num = typeof distM === 'number' ? distM : parseInt(distM, 10);
          if (!Number.isNaN(num) && num > 0) p.push(num >= 1000 ? (num / 1000).toFixed(1) + ' км' : num + ' м');
        }
        if (e.default_duration_sec) {
          const s = e.default_duration_sec;
          const m = Math.floor(s / 60);
          const sec = s % 60;
          p.push(m > 0 ? `${m} мин ${sec} сек` : `${sec} сек`);
        }
      }
      if (p.length) t += ' — ' + p.join(', ');
      return t;
    });
    const customLines = customExercises.map((e) => {
      let t = e.name || '';
      const p = [];
      if (category === 'ofp') {
        if (e.sets != null && e.reps != null) p.push(`${e.sets}×${e.reps}`);
        if (e.weightKg != null && !Number.isNaN(Number(e.weightKg)) && Number(e.weightKg) > 0) p.push(Number(e.weightKg) + ' кг');
      } else {
        if (e.distanceM != null && e.distanceM > 0) p.push(e.distanceM >= 1000 ? (e.distanceM / 1000).toFixed(1) + ' км' : e.distanceM + ' м');
      }
      if (p.length) t += ' — ' + p.join(', ');
      return t;
    });
    setDescription([...libraryLines, ...customLines].join('\n'));
  }, [category, selectedExerciseIds, libraryExercises, exerciseDistanceOverrides, exerciseOfpOverrides, customExercises]);

  const toggleExercise = (id) => {
    setSelectedExerciseIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const addCustomExercise = () => {
    const name = customNewName.trim();
    if (!name) return;
    const id = `custom-${++nextCustomIdRef.current}`;
    const item = { id, name };
    if (category === 'sbu') {
      const m = customNewDistanceM.trim() ? parseInt(customNewDistanceM, 10) : undefined;
      if (m != null && !Number.isNaN(m) && m > 0) item.distanceM = m;
    }
    if (category === 'ofp') {
      const sets = customNewSets.trim() ? parseInt(customNewSets, 10) : undefined;
      const reps = customNewReps.trim() ? parseInt(customNewReps, 10) : undefined;
      const w = customNewWeightKg.trim() ? parseFloat(customNewWeightKg.replace(',', '.')) : undefined;
      if (sets != null && !Number.isNaN(sets)) item.sets = sets;
      if (reps != null && !Number.isNaN(reps)) item.reps = reps;
      if (w != null && !Number.isNaN(w) && w >= 0) item.weightKg = w;
    }
    setCustomExercises((prev) => [...prev, item]);
    setCustomNewName('');
    setCustomNewDistanceM('');
    setCustomNewSets('');
    setCustomNewReps('');
    setCustomNewWeightKg('');
  };

  const removeCustomExercise = (id) => {
    setCustomExercises((prev) => prev.filter((e) => e.id !== id));
  };

  const updateCustomExercise = (id, field, value) => {
    setCustomExercises((prev) => prev.map((e) => e.id === id ? { ...e, [field]: value } : e));
  };

  const addFartlekSegment = () => {
    setFartlekSegments((prev) => [...prev, { id: Math.max(0, ...prev.map((s) => s.id)) + 1, reps: '', accelDistM: '', accelPace: '', recoveryDistM: '', recoveryType: 'jog' }]);
  };
  const removeFartlekSegment = (id) => {
    setFartlekSegments((prev) => prev.filter((s) => s.id !== id));
  };
  const updateFartlekSegment = (id, field, value) => {
    setFartlekSegments((prev) => prev.map((s) => (s.id === id ? { ...s, [field]: value } : s)));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!api) return;
    if (!isEdit && !isEditResult && !effectiveDate) return;
    setLoading(true);
    setError('');
    try {
      if (isEditResult) {
        const payload = {
          date: editResultData.date,
          week: editResultData.weekNumber ?? 1,
          day: editResultData.dayKey ?? null,
          result_distance: runDistance ? parseFloat(runDistance) : null,
          result_time: runDuration || null,
          result_pace: runPace || null,
          notes: description.trim() || null,
        };
        await api.saveResult(payload, viewContext || undefined);
        onSuccess?.();
        onClose();
        setLoading(false);
        return;
      }
      const csrfRes = await api.request('get_csrf_token', {}, 'GET');
      const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
      if (!csrfToken) {
        setError('Не удалось получить токен безопасности. Обновите страницу.');
        setLoading(false);
        return;
      }
      if (isEdit) {
        await api.updateTrainingDay(initialData.id, {
          type,
          description: description.trim() || undefined,
          is_key_workout: isKeyWorkout,
        }, viewContext || undefined);
      } else {
        await api.addTrainingDayByDate({
          date: effectiveDate,
          type,
          description: description.trim() || undefined,
          is_key_workout: isKeyWorkout,
          csrf_token: csrfToken,
        }, viewContext || undefined);
      }
      onSuccess?.();
      onClose();
    } catch (err) {
      const message = err?.message || err?.error || (isEdit ? 'Не удалось сохранить изменения' : 'Не удалось добавить тренировку');
      setError(typeof message === 'string' ? message : JSON.stringify(message));
    } finally {
      setLoading(false);
    }
  };

  // ESC + блокировка прокрутки фона, пока шторка открыта (общий Modal не используется)
  useEffect(() => {
    if (!isOpen) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [isOpen, onClose]);

  useSheetFocus(sheetRef, isOpen);

  if (!isOpen) return null;

  const dateLabel = effectiveDate
    ? new Date(effectiveDate + 'T12:00:00').toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })
    : '';

  const showSimpleRun = category === 'running' && SIMPLE_RUN_TYPES.includes(type);
  const showInterval = category === 'running' && type === 'interval';
  const showFartlek = category === 'running' && type === 'fartlek';
  const showLibrary = (category === 'ofp' || category === 'sbu') && step === 2;

  return createPortal(
    <div className="atv3-modal-root" role="presentation">
      <div className="atv3-scrim" onClick={onClose} />
      <div className="atv3-sheet" role="dialog" aria-modal="true" aria-label="Редактор тренировки" ref={sheetRef}>
        <div className="atv3-grip" aria-hidden />
        <div className="atv3-sheet-top">
          <div className="atv3-sheet-titles">
            <div className="atv3-sheet-kicker">{dateLabel ? dateLabel.toUpperCase() : 'РЕДАКТИРОВАНИЕ'}</div>
            <div className="atv3-sheet-title">{isEditResult ? 'Результат тренировки' : (isEdit ? 'Изменить тренировку' : 'Новая тренировка')}</div>
          </div>
          <button type="button" className="atv3-sheet-x" onClick={onClose} aria-label="Закрыть">✕</button>
        </div>

        <form onSubmit={handleSubmit} className="atv3-sheet-form">
          <div className="atv3-sheet-scroll">
            {!isEditResult && (
              <>
                <div className="atv3-label">ТИП ТРЕНИРОВКИ</div>
                <div className="atv3-type-chips" role="radiogroup" aria-label="Тип тренировки">
                  {ALL_TYPE_CHIPS.map((t) => {
                    const active = type === t.value;
                    const col = typeColorVar(t.value);
                    return (
                      <button
                        key={t.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        className={`atv3-type-chip${active ? ' is-active' : ''}`}
                        style={active ? { borderColor: col, background: `color-mix(in srgb, ${col} 12%, transparent)` } : undefined}
                        onClick={() => selectType(t.value)}
                      >
                        <span className="atv3-type-dot" style={{ background: col }} />
                        {t.label}
                      </button>
                    );
                  })}
                </div>
              </>
            )}

          {/* Калькулятор простого бега — введи 2 из 3 (v3 EditWorkoutSheet) */}
          {showSimpleRun && (
            <div className="atv3-section">
              <div className="atv3-label">ПАРАМЕТРЫ · введи 2 из 3, третий посчитается</div>
              <div className="atv3-calc-row">
                <CalcFieldV3
                  label="Дистанция" unit="км" accent placeholder="5"
                  value={runDistance}
                  onChange={(v) => { setRunDistance(v); recalcSimpleRun('runDistance', v); }}
                />
                <CalcFieldV3
                  label="Темп" unit="/км" placeholder="5:30" inputMode="numeric"
                  value={runPace}
                  onChange={(v) => { const m = maskPaceInput(v); setRunPace(m); recalcSimpleRun('runPace', m); }}
                />
                <CalcFieldV3
                  label="Время" unit="" placeholder="0:30:00" inputMode="numeric"
                  value={runDuration}
                  onChange={(raw) => {
                    const prevDigits = prevRunDurationRef.current.replace(/\D/g, '');
                    const newDigits = raw.replace(/\D/g, '').slice(0, 6);
                    let masked = maskTimeInput(raw);
                    if (prevDigits.length === 5 && newDigits.length === 6 && newDigits.slice(0, 5) === prevDigits) {
                      const match = prevRunDurationRef.current.match(/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/);
                      if (match) masked = `${match[1]}:${match[2].padStart(2, '0')}:${(match[3].padStart(2, '0').slice(0, 1) + newDigits[5])}`;
                    }
                    prevRunDurationRef.current = masked;
                    setRunDuration(masked);
                    recalcSimpleRun('runDuration', masked);
                  }}
                />
              </div>
              {runDistance && runPace && runDuration && (
                <div className="atv3-calc-hint">
                  <span aria-hidden>🧮</span>
                  <span>≈ {runDuration} в темпе {runPace} /км</span>
                </div>
              )}
              <div className="atv3-label atv3-label--mt">ПУЛЬС (ОПЦИОНАЛЬНО)</div>
              <input
                className="atv3-box atv3-box--full"
                placeholder="140-150"
                value={runHR}
                onChange={(e) => setRunHR(e.target.value)}
              />

              <div className="atv3-label atv3-label--mt">РАЗМИНКА (ОПЦИОНАЛЬНО)</div>
              <div className="atv3-row">
                <NumFieldV3 value={warmupKm} unit="км" placeholder="2" onChange={setWarmupKm} />
                <NumFieldV3 value={warmupPace} unit="темп" placeholder="6:00" onChange={(v) => setWarmupPace(maskPaceInput(v))} />
              </div>

              <div className="atv3-label atv3-label--mt">ЗАМИНКА (ОПЦИОНАЛЬНО)</div>
              <div className="atv3-row">
                <NumFieldV3 value={cooldownKm} unit="км" placeholder="1" onChange={setCooldownKm} />
                <NumFieldV3 value={cooldownPace} unit="темп" placeholder="6:00" onChange={(v) => setCooldownPace(maskPaceInput(v))} />
              </div>
            </div>
          )}

          {/* Конструктор интервалов — компактный layout (v3 EditWorkoutSheet) */}
          {showInterval && (
            <div className="atv3-section">
              <div className="atv3-label">РАЗМИНКА</div>
              <div className="atv3-row">
                <NumFieldV3 value={warmupKm} unit="км" placeholder="2" onChange={setWarmupKm} />
                <NumFieldV3 value={warmupPace} unit="темп" placeholder="6:00" onChange={(v) => setWarmupPace(maskPaceInput(v))} />
              </div>

              <div className="atv3-label atv3-label--mt">ИНТЕРВАЛЫ</div>
              <div className="atv3-inline">
                <input className="atv3-box atv3-box--xs" value={intervalReps} placeholder="6" inputMode="numeric" onChange={(e) => setIntervalReps(e.target.value)} />
                <span className="atv3-inline-x">×</span>
                <input className="atv3-box atv3-box--sm" value={intervalDistM} placeholder="800" inputMode="numeric" onChange={(e) => setIntervalDistM(e.target.value)} />
                <span className="atv3-inline-sep">м @</span>
                <input className="atv3-box atv3-box--sm atv3-box--accent" value={intervalPace} placeholder="3:55" inputMode="numeric" onChange={(e) => setIntervalPace(maskPaceInput(e.target.value))} />
              </div>

              <div className="atv3-label atv3-label--mt">ВОССТАНОВЛЕНИЕ МЕЖДУ</div>
              <div className="atv3-inline">
                <input className="atv3-box atv3-box--sm" value={restDistM} placeholder="200" inputMode="numeric" onChange={(e) => setRestDistM(e.target.value)} />
                <span className="atv3-inline-sep">м</span>
                <select className="atv3-box atv3-box--grow" value={restType} onChange={(e) => setRestType(e.target.value)}>
                  <option value="jog">трусцой</option>
                  <option value="walk">ходьбой</option>
                  <option value="rest">отдых</option>
                </select>
              </div>

              <div className="atv3-label atv3-label--mt">ЗАМИНКА</div>
              <div className="atv3-row">
                <NumFieldV3 value={cooldownKm} unit="км" placeholder="2" onChange={setCooldownKm} />
                <NumFieldV3 value={cooldownPace} unit="темп" placeholder="6:00" onChange={(v) => setCooldownPace(maskPaceInput(v))} />
              </div>

              {intervalTotalKm != null && (
                <div className="atv3-total">Всего: ~{intervalTotalKm.toFixed(2)} км</div>
              )}
            </div>
          )}

          {/* Конструктор фартлека — карточки сегментов (v3 EditWorkoutSheet) */}
          {showFartlek && (
            <div className="atv3-section">
              <div className="atv3-label">РАЗМИНКА</div>
              <NumFieldV3 value={fartlekWarmupKm} unit="км" placeholder="2" onChange={setFartlekWarmupKm} />

              <div className="atv3-label atv3-label--mt">СЕГМЕНТЫ ФАРТЛЕКА</div>
              <div className="atv3-segs">
                {fartlekSegments.map((seg, i) => (
                  <div key={seg.id} className="atv3-seg">
                    <div className="atv3-seg-head">
                      <span>СЕГМЕНТ {i + 1}</span>
                      <span className="atv3-seg-spacer" />
                      {fartlekSegments.length > 1 && (
                        <button type="button" className="atv3-del" onClick={() => removeFartlekSegment(seg.id)} aria-label="Удалить сегмент">✕</button>
                      )}
                    </div>
                    <div className="atv3-inline">
                      <input className="atv3-box atv3-box--xs" value={seg.reps} placeholder="4" inputMode="numeric" onChange={(e) => updateFartlekSegment(seg.id, 'reps', e.target.value)} />
                      <span className="atv3-inline-sep">× ускор.</span>
                      <input className="atv3-box atv3-box--sm" value={seg.accelDistM} placeholder="200" inputMode="numeric" onChange={(e) => updateFartlekSegment(seg.id, 'accelDistM', e.target.value)} />
                      <span className="atv3-inline-sep">м @</span>
                      <input className="atv3-box atv3-box--sm atv3-box--accent" value={seg.accelPace} placeholder="4:00" inputMode="numeric" onChange={(e) => updateFartlekSegment(seg.id, 'accelPace', maskPaceInput(e.target.value))} />
                    </div>
                    <div className="atv3-inline atv3-inline--mt">
                      <span className="atv3-inline-sep">восст.</span>
                      <input className="atv3-box atv3-box--sm" value={seg.recoveryDistM} placeholder="200" inputMode="numeric" onChange={(e) => updateFartlekSegment(seg.id, 'recoveryDistM', e.target.value)} />
                      <span className="atv3-inline-sep">м</span>
                      <select className="atv3-box atv3-box--grow" value={seg.recoveryType} onChange={(e) => updateFartlekSegment(seg.id, 'recoveryType', e.target.value)}>
                        <option value="jog">трусцой</option>
                        <option value="walk">ходьбой</option>
                        <option value="easy">лёгкий бег</option>
                      </select>
                    </div>
                  </div>
                ))}
              </div>
              <button type="button" className="atv3-addrow" onClick={addFartlekSegment}>+ Добавить сегмент</button>

              <div className="atv3-label atv3-label--mt">ЗАМИНКА</div>
              <NumFieldV3 value={fartlekCooldownKm} unit="км" placeholder="2" onChange={setFartlekCooldownKm} />

              {fartlekTotalKm != null && (
                <div className="atv3-total">Всего: ~{fartlekTotalKm.toFixed(2)} км</div>
              )}
            </div>
          )}

          {/* Библиотека упражнений ОФП/СБУ */}
          {showLibrary && (
            <div className="add-training-library">
              <p className="add-training-block-title">Упражнения из библиотеки</p>
              {libraryLoading ? (
                <div className="add-training-loading"><LogoLoading size="sm" /></div>
              ) : libraryExercises.length === 0 ? (
                <p className="add-training-empty-lib">Нет упражнений в этой категории. Заполните описание вручную.</p>
              ) : (
                <div className="add-training-library-list">
                  {libraryExercises.map((ex) => {
                    const distM = exerciseDistanceOverrides[ex.id] ?? ex.default_distance_m ?? (category === 'sbu' ? 30 : null);
                    const ofp = exerciseOfpOverrides[ex.id] || {};
                    const sets = ofp.sets ?? ex.default_sets ?? '';
                    const reps = ofp.reps ?? ex.default_reps ?? '';
                    const weightKg = ofp.weightKg ?? '';
                    const showDistInput = category === 'sbu';
                    const showOfpInputs = category === 'ofp';
                    return (
                      <div key={ex.id} className="add-training-library-item">
                        <label className="add-training-library-item-label">
                          <input type="checkbox" checked={selectedExerciseIds.has(ex.id)} onChange={() => toggleExercise(ex.id)} />
                          <span className="add-training-library-name">{ex.name}</span>
                          {!showDistInput && !showOfpInputs && (ex.default_sets || ex.default_reps || ex.default_distance_m) && (
                            <span className="add-training-library-params">
                              {ex.default_sets && ex.default_reps && `${ex.default_sets}×${ex.default_reps}`}
                              {ex.default_distance_m && ` • ${ex.default_distance_m >= 1000 ? (ex.default_distance_m / 1000).toFixed(1) + ' км' : ex.default_distance_m + ' м'}`}
                            </span>
                          )}
                        </label>
                        {showDistInput && (
                          <div className="add-training-library-sbu-dist">
                            <input
                              type="number"
                              min={10}
                              max={2000}
                              step={10}
                              value={distM ?? ''}
                              onChange={(e) => {
                                const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10);
                                setExerciseDistanceOverrides((prev) => (v != null && !Number.isNaN(v) ? { ...prev, [ex.id]: v } : { ...prev, [ex.id]: undefined }));
                              }}
                              placeholder="м"
                              className="add-training-library-dist-input"
                              title="Дистанция в метрах"
                            />
                            <span className="add-training-library-dist-unit">м</span>
                          </div>
                        )}
                        {showOfpInputs && (
                          <div className="add-training-library-ofp-params">
                            <input
                              type="number"
                              min={1}
                              max={20}
                              placeholder="подх."
                              value={sets}
                              onChange={(e) => {
                                const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10);
                                setExerciseOfpOverrides((prev) => ({
                                  ...prev,
                                  [ex.id]: { ...(prev[ex.id] || {}), sets: v != null && !Number.isNaN(v) ? v : undefined },
                                }));
                              }}
                              className="add-training-library-ofp-input"
                              title="Подходы"
                            />
                            <span className="add-training-library-ofp-sep">×</span>
                            <input
                              type="number"
                              min={1}
                              max={100}
                              placeholder="повт."
                              value={reps}
                              onChange={(e) => {
                                const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10);
                                setExerciseOfpOverrides((prev) => ({
                                  ...prev,
                                  [ex.id]: { ...(prev[ex.id] || {}), reps: v != null && !Number.isNaN(v) ? v : undefined },
                                }));
                              }}
                              className="add-training-library-ofp-input"
                              title="Повторы"
                            />
                            <input
                              type="number"
                              min={0}
                              max={500}
                              step={0.5}
                              placeholder="кг"
                              value={weightKg}
                              onChange={(e) => {
                                const v = e.target.value === '' ? undefined : parseFloat(e.target.value.replace(',', '.'));
                                setExerciseOfpOverrides((prev) => ({
                                  ...prev,
                                  [ex.id]: { ...(prev[ex.id] || {}), weightKg: v != null && !Number.isNaN(v) ? v : undefined },
                                }));
                              }}
                              className="add-training-library-ofp-input add-training-library-ofp-weight"
                              title="Вес (кг)"
                            />
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          )}

          {/* Своё упражнение (название и параметры) для ОФП/СБУ */}
          {showLibrary && (
            <div className="add-training-custom">
              <p className="add-training-block-title">Своё упражнение</p>
              <p className="add-training-custom-hint">Введите название и при необходимости параметры</p>
              <div className="add-training-custom-row">
                <input
                  type="text"
                  placeholder="Название упражнения"
                  value={customNewName}
                  onChange={(e) => setCustomNewName(e.target.value)}
                  className="add-training-input add-training-custom-name"
                />
                {category === 'sbu' && (
                  <input
                    type="number"
                    min={10}
                    max={2000}
                    step={10}
                    placeholder="м"
                    value={customNewDistanceM}
                    onChange={(e) => setCustomNewDistanceM(e.target.value)}
                    className="add-training-input add-training-custom-dist"
                    title="Дистанция в метрах"
                  />
                )}
                {category === 'ofp' && (
                  <>
                    <input
                      type="number"
                      min={1}
                      max={20}
                      placeholder="подх."
                      value={customNewSets}
                      onChange={(e) => setCustomNewSets(e.target.value)}
                      className="add-training-input add-training-custom-ofp"
                      title="Подходы"
                    />
                    <span className="add-training-library-ofp-sep">×</span>
                    <input
                      type="number"
                      min={1}
                      max={100}
                      placeholder="повт."
                      value={customNewReps}
                      onChange={(e) => setCustomNewReps(e.target.value)}
                      className="add-training-input add-training-custom-ofp"
                      title="Повторы"
                    />
                    <input
                      type="number"
                      min={0}
                      max={500}
                      step={0.5}
                      placeholder="кг"
                      value={customNewWeightKg}
                      onChange={(e) => setCustomNewWeightKg(e.target.value)}
                      className="add-training-input add-training-custom-weight"
                      title="Вес (кг)"
                    />
                  </>
                )}
                <button type="button" className="btn btn-secondary add-training-custom-add" onClick={addCustomExercise}>
                  Добавить
                </button>
              </div>
              {customExercises.length > 0 && (
                <div className="add-training-custom-list">
                  {customExercises.map((ex) => (
                    <div key={ex.id} className="add-training-library-item add-training-custom-item">
                      <span className="add-training-library-name">{ex.name}</span>
                      {category === 'sbu' && (
                        <div className="add-training-library-sbu-dist">
                          <input
                            type="number"
                            min={10}
                            max={2000}
                            step={10}
                            value={ex.distanceM ?? ''}
                            onChange={(e) => {
                              const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10);
                              updateCustomExercise(ex.id, 'distanceM', v != null && !Number.isNaN(v) ? v : undefined);
                            }}
                            placeholder="м"
                            className="add-training-library-dist-input"
                          />
                          <span className="add-training-library-dist-unit">м</span>
                        </div>
                      )}
                      {category === 'ofp' && (
                        <div className="add-training-library-ofp-params">
                          <input
                            type="number"
                            min={1}
                            max={20}
                            placeholder="подх."
                            value={ex.sets ?? ''}
                            onChange={(e) => {
                              const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10);
                              updateCustomExercise(ex.id, 'sets', v != null && !Number.isNaN(v) ? v : undefined);
                            }}
                            className="add-training-library-ofp-input"
                          />
                          <span className="add-training-library-ofp-sep">×</span>
                          <input
                            type="number"
                            min={1}
                            max={100}
                            placeholder="повт."
                            value={ex.reps ?? ''}
                            onChange={(e) => {
                              const v = e.target.value === '' ? undefined : parseInt(e.target.value, 10);
                              updateCustomExercise(ex.id, 'reps', v != null && !Number.isNaN(v) ? v : undefined);
                            }}
                            className="add-training-library-ofp-input"
                          />
                          <input
                            type="number"
                            min={0}
                            max={500}
                            step={0.5}
                            placeholder="кг"
                            value={ex.weightKg ?? ''}
                            onChange={(e) => {
                              const v = e.target.value === '' ? undefined : parseFloat(e.target.value.replace(',', '.'));
                              updateCustomExercise(ex.id, 'weightKg', v != null && !Number.isNaN(v) ? v : undefined);
                            }}
                            className="add-training-library-ofp-input add-training-library-ofp-weight"
                          />
                        </div>
                      )}
                      <button type="button" className="add-training-custom-remove-btn" onClick={() => removeCustomExercise(ex.id)} aria-label="Удалить" title="Удалить упражнение">
                        ×
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

            <div className="atv3-label atv3-label--mt">{isEditResult ? 'ЗАМЕТКА' : 'ОПИСАНИЕ (ОПЦИОНАЛЬНО)'}</div>
            <textarea
              id="add-training-desc"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="atv3-area"
              rows={3}
              placeholder={isEditResult ? 'Комментарий…' : 'Например: 5 км в лёгком темпе или выберите упражнения выше'}
            />

            {!isEditResult && (
              <label className="atv3-key">
                <input type="checkbox" checked={isKeyWorkout} onChange={(e) => setIsKeyWorkout(e.target.checked)} />
                <span>Ключевая тренировка</span>
              </label>
            )}

            {error && <div className="atv3-error">{error}</div>}
          </div>

          <div className="atv3-footer">
            <button type="button" className="atv3-cancel" onClick={onClose}>Отмена</button>
            <button type="submit" className="atv3-save" disabled={loading}>
              {loading ? 'Сохранение…' : 'Сохранить'}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body
  );
};

export default AddTrainingModal;

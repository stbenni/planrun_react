/**
 * Вспомогательные функции для календаря тренировок
 * Адаптированы из components/calendar_helpers.php
 */

/**
 * Получить дату дня недели
 * @param {string} startDate - Дата начала недели (YYYY-MM-DD)
 * @param {string} dayOfWeek - День недели (mon, tue, wed, thu, fri, sat, sun)
 * @returns {string} Дата в формате YYYY-MM-DD
 */
export function getDateForDay(startDate, dayOfWeek) {
  const start = new Date(startDate);
  const dayNumber = { mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6, sun: 7 };
  const startDayOfWeek = start.getDay() === 0 ? 7 : start.getDay(); // Преобразуем воскресенье в 7
  const daysToAdd = dayNumber[dayOfWeek] - startDayOfWeek;
  const adjustedDays = daysToAdd < 0 ? daysToAdd + 7 : daysToAdd;
  
  const date = new Date(start);
  date.setDate(date.getDate() + adjustedDays);
  return date.toISOString().split('T')[0];
}

/**
 * Получить CSS класс типа тренировки
 * @param {string} type - Тип тренировки (rest, long, interval, tempo, easy, etc.)
 * @param {boolean} isKey - Является ли ключевой тренировкой
 * @returns {string} CSS класс
 */
export function getTrainingClass(type, isKey = false) {
  if (isKey) return 'key-session';
  
  const classes = {
    rest: 'rest-day',
    long: 'long-run',
    interval: 'interval',
    tempo: 'tempo',
    easy: 'tempo', // Легкий бег использует тот же класс что и темповый
    marathon: 'key-session',
    control: 'control',
    free: 'free-training',
    other: '', // ОФП
    sbu: 'interval', // СБУ использует класс интервалов (оранжевый цвет)
    race: 'key-session',
    fartlek: 'interval',
  };
  
  return classes[type] || '';
}

/**
 * Генерация краткого описания из полного текста
 * Адаптировано из getShortDescription в calendar_helpers.php
 * @param {string} fullText - Полное описание тренировки
 * @param {string} type - Тип тренировки
 * @returns {string} HTML краткого описания
 */
export function getShortDescription(fullText, type) {
  if (!fullText) {
    if (type === 'rest') {
      return '<div class="short-desc"><div class="short-desc-title"><strong>ОТДЫХ</strong></div></div>';
    }
    return '';
  }
  
  // Удаляем HTML теги для анализа
  const plainText = fullText.replace(/<[^>]*>/g, '');
  
  // Начинаем с контейнера
  let html = '<div class="short-desc">';
  
  switch (type) {
    case 'rest':
      // Для отдыха показываем просто "ПОЛНЫЙ ОТДЫХ" или "ОТДЫХ"
      const title = /ПОЛНЫЙ ОТДЫХ/i.test(fullText) ? 'ПОЛНЫЙ ОТДЫХ' : 'ОТДЫХ';
      html += `<div class="short-desc-title"><strong>${escapeHtml(title)}</strong></div>`;
      html += '</div>';
      return html;
      
    case 'tempo':
      // Для темповых извлекаем дистанцию, пульс и темп
      const tempoShort = 'ТЕМПОВЫЙ';
      let tempoDistance = '';
      let tempoPace = '';
      let tempoPulse = '';
      
      // Извлекаем дистанцию
      const distanceMatch = plainText.match(/(\d+)\s*КИЛОМЕТР/i);
      if (distanceMatch) tempoDistance = distanceMatch[1] + ' км';
      
      // Извлекаем пульс
      const pulseMatch = plainText.match(/Пульс[:\s]+(\d+-\d+)/i);
      if (pulseMatch) tempoPulse = pulseMatch[1];
      
      // Извлекаем темп
      const paceMatch = plainText.match(/Темп[:\s]+(\d+:\d+(?:-\d+:\d+)?)/i);
      if (paceMatch) tempoPace = paceMatch[1];
      
      html += `<div class="short-desc-title"><strong>${escapeHtml(tempoShort)}</strong></div>`;
      html += '<div class="short-desc-details">';
      
      const tempoDetails = [];
      if (tempoDistance) tempoDetails.push(tempoDistance);
      if (tempoPulse) tempoDetails.push('Пульс: ' + tempoPulse);
      if (tempoPace) tempoDetails.push('Темп: ' + tempoPace);
      
      if (tempoDetails.length > 0) {
        html += tempoDetails.map(escapeHtml).join('<br>');
      }
      
      html += '</div></div>';
      return html;
      
    case 'easy':
      // Для легкого бега
      let easyDistance = '';
      let easyPace = '';
      let easyPulse = '';
      let easyShort = '';
      
      // Извлекаем дистанцию
      const easyDistanceMatch = plainText.match(/(\d+)\s*КИЛОМЕТР/i);
      if (easyDistanceMatch) easyDistance = easyDistanceMatch[1] + ' км';
      
      // Извлекаем пульс
      const easyPulseMatch = plainText.match(/Пульс[:\s]+(\d+-\d+)/i);
      if (easyPulseMatch) easyPulse = easyPulseMatch[1];
      
      // Извлекаем темп
      const easyPaceMatch = plainText.match(/Темп[:\s]+(\d+:\d+(?:-\d+:\d+)?)/i);
      if (easyPaceMatch) easyPace = easyPaceMatch[1];
      
      // Определяем тип бега
      if (/ВОССТАНОВИТЕЛЬНЫЙ/i.test(fullText)) {
        easyShort = 'ВОССТАНОВИТЕЛЬНЫЙ';
      } else {
        easyShort = 'ЛЕГКИЙ';
      }
      
      html += `<div class="short-desc-title"><strong>${escapeHtml(easyShort)}</strong></div>`;
      html += '<div class="short-desc-details">';
      
      const easyDetails = [];
      if (easyDistance) easyDetails.push(easyDistance);
      if (easyPulse) easyDetails.push('Пульс: ' + easyPulse);
      if (easyPace) easyDetails.push('Темп: ' + easyPace);
      
      if (easyDetails.length > 0) {
        html += easyDetails.map(escapeHtml).join('<br>');
      }
      
      html += '</div></div>';
      return html;
      
    case 'long':
      // Для длительных: дистанция, темп, пульс
      let longDistance = '';
      let longPace = '';
      let longPulse = '';
      
      // Извлекаем дистанцию
      const longDistanceMatch = plainText.match(/(\d+)\s*КИЛОМЕТР/i);
      if (longDistanceMatch) longDistance = longDistanceMatch[1] + ' км';
      
      // Извлекаем темп
      const longPaceMatch = plainText.match(/Темп[:\s]+(\d+:\d+(?:-\d+:\d+)?)/i);
      if (longPaceMatch) longPace = 'Темп: ' + longPaceMatch[1];
      
      // Извлекаем пульс
      const longPulseMatch = plainText.match(/Пульс[:\s]+(\d+-\d+)/i);
      if (longPulseMatch) longPulse = 'Пульс: ' + longPulseMatch[1];
      
      html += '<div class="short-desc-title"><strong>ДЛИТЕЛЬНЫЙ БЕГ</strong></div>';
      html += '<div class="short-desc-details">';
      
      const longDetails = [];
      if (longDistance) longDetails.push(longDistance);
      if (longPace) longDetails.push(longPace);
      if (longPulse) longDetails.push(longPulse);
      
      if (longDetails.length > 0) {
        html += longDetails.map(escapeHtml).join('<br>');
      } else {
        html += 'Длительный бег';
      }
      
      html += '</div></div>';
      return html;
      
    case 'interval':
      // Для интервалов: количество и дистанция
      html += '<div class="short-desc-title"><strong>ИНТЕРВАЛЫ</strong></div>';
      html += '<div class="short-desc-details">';
      
      const intervalMatch = fullText.match(/(\d+)x(\d+)/);
      if (intervalMatch) {
        html += escapeHtml(intervalMatch[1] + 'x' + intervalMatch[2] + 'м');
      } else {
        html += 'Интервалы';
      }
      
      html += '</div></div>';
      return html;
      
    case 'other':
      // Для ОФП в календаре: только длительность
      html += '<div class="short-desc-title"><strong>ОФП</strong></div>';
      html += '<div class="short-desc-details">';
      
      // Извлекаем длительность
      const durationMatch = plainText.match(/(\d+(?:-\d+)?)\s*(минут|мин|час|часов)/i);
      if (durationMatch) {
        html += escapeHtml(durationMatch[1] + ' ' + durationMatch[2].toLowerCase());
      } else {
        html += 'ОФП';
      }
      
      html += '</div></div>';
      return html;
      
    case 'sbu':
      // Для СБУ: показываем название и краткое описание
      html += '<div class="short-desc-title"><strong>СБУ</strong></div>';
      html += '<div class="short-desc-details">';
      
      // Показываем первые 50 символов описания или "СБУ"
      const sbuShort = plainText.length > 50 ? plainText.substring(0, 50) + '...' : plainText;
      if (sbuShort.trim()) {
        html += escapeHtml(sbuShort);
      } else {
        html += 'СБУ';
      }
      
      html += '</div></div>';
      return html;
      
    case 'race':
      // Для контрольных: дистанция и цель
      html += '<div class="short-desc-title"><strong>КОНТРОЛЬНАЯ</strong></div>';
      html += '<div class="short-desc-details">';
      
      const raceDistanceMatch = plainText.match(/(\d+)\s*КИЛОМЕТР/i);
      if (raceDistanceMatch) {
        html += escapeHtml(raceDistanceMatch[1] + ' км');
      } else {
        html += 'Контрольная тренировка';
      }
      
      html += '</div></div>';
      return html;
      
    case 'free':
      // Пустой день (режим «самостоятельно») — без подписи
      html += '<div class="short-desc-details">—</div></div>';
      return html;
      
    default:
      // Для остальных берем первые 80 символов
      const short = plainText.length > 80 ? plainText.substring(0, 80) + '...' : plainText;
      html += `<div class="short-desc-details">${escapeHtml(short)}</div>`;
      html += '</div>';
      return html;
  }
}

/**
 * Экранирование HTML
 */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Форматирование даты для отображения
 */
export function formatDateShort(dateString) {
  const date = new Date(dateString);
  const day = date.getDate().toString().padStart(2, '0');
  const month = (date.getMonth() + 1).toString().padStart(2, '0');
  return `${day}.${month}`;
}

/**
 * Получить название дня недели
 */
export function getDayName(dayKey) {
  const names = {
    mon: 'Пн',
    tue: 'Вт',
    wed: 'Ср',
    thu: 'Чт',
    fri: 'Пт',
    sat: 'Сб',
    sun: 'Вс',
  };
  return names[dayKey] || dayKey;
}

/** Типы бега — одна категория для сопоставления */
const RUNNING_TYPES = ['easy', 'long', 'long-run', 'tempo', 'interval', 'fartlek', 'control', 'race', 'run', 'running'];

function planTypeToCategory(type) {
  if (!type) return null;
  const t = String(type).toLowerCase().trim();
  if (RUNNING_TYPES.includes(t)) return 'running';
  if (t === 'walking') return 'walking';
  if (t === 'hiking') return 'hiking';
  if (t === 'cycling') return 'cycling';
  if (t === 'swimming') return 'swimming';
  if (t === 'other') return 'other';
  if (t === 'sbu') return 'sbu';
  return t;
}

function workoutTypeToCategory(type) {
  if (!type) return 'running';
  const t = String(type).toLowerCase().trim();
  if (RUNNING_TYPES.includes(t)) return 'running';
  if (t === 'walking') return 'walking';
  if (t === 'hiking') return 'hiking';
  if (t === 'cycling') return 'cycling';
  if (t === 'swimming') return 'swimming';
  if (t === 'other') return 'other';
  if (t === 'sbu') return 'sbu';
  return t;
}

/**
 * Получить запланированный день из плана по дате
 * @param {string} dateStr YYYY-MM-DD
 * @param {Object} planData план с weeks_data
 * @returns {{ items: Array, type?: string, weekNumber?: number } | null}
 */
export function getPlanDayForDate(dateStr, planData) {
  const weeksData = planData?.weeks_data;
  if (!planData || !Array.isArray(weeksData)) return null;
  const date = new Date(dateStr + 'T00:00:00');
  date.setHours(0, 0, 0, 0);
  const dayOfWeek = date.getDay();
  const dayKey = dayOfWeek === 0 ? 'sun' : ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'][dayOfWeek - 1];
  for (const week of weeksData) {
    if (!week.start_date) continue;
    const weekStart = new Date(week.start_date + 'T00:00:00');
    weekStart.setHours(0, 0, 0, 0);
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekEnd.getDate() + 6);
    weekEnd.setHours(23, 59, 59, 999);
    if (date >= weekStart && date <= weekEnd) {
      const raw = week.days && week.days[dayKey];
      if (raw) {
        const items = Array.isArray(raw) ? raw : [raw];
        return {
          items,
          weekNumber: week.number,
          type: items[0]?.type,
          text: items.map((i) => i.text).filter(Boolean).join('\n'),
          is_key_workout: items.some((i) => i.is_key_workout || i.key),
        };
      }
    }
  }
  return null;
}

/**
 * Статус выполнения дня для календаря
 * @param {string} dateStr YYYY-MM-DD
 * @param {Object} planDayForDate результат getPlanDayForDate или null
 * @param {Object} workoutsData { [date]: { distance?, duration?, activity_type? } }
 * @param {Object} resultsData { [date]: [{ activity_type?, ... }] }
 * @param {Object} workoutsListByDate { [date]: [{ activity_type?, ... }] }
 * @returns {{ status: 'completed'|'rest_extra'|'planned'|'rest', extraWorkoutType?: string }}
 */
export function getDayCompletionStatus(dateStr, planDayForDate, workoutsData, resultsData, workoutsListByDate) {
  const plannedItems = planDayForDate?.items ?? (planDayForDate ? [{ type: planDayForDate.type }] : []);
  const plannedNonRest = plannedItems.filter((p) => p.type !== 'rest' && p.type !== 'free');
  const plannedCategories = [...new Set(plannedNonRest.map((p) => planTypeToCategory(p.type)).filter(Boolean))];

  const actualCategories = new Set();
  (workoutsListByDate?.[dateStr] || []).forEach((w) => {
    const t = w.activity_type ?? w.type ?? 'running';
    actualCategories.add(workoutTypeToCategory(t));
  });
  (resultsData?.[dateStr] || []).forEach((r) => {
    const t = r.activity_type ?? r.activity_type_name ?? 'running';
    actualCategories.add(workoutTypeToCategory(t));
  });
  if (workoutsData?.[dateStr] && (workoutsData[dateStr].distance || workoutsData[dateStr].duration)) {
    const t = workoutsData[dateStr].activity_type ?? 'running';
    actualCategories.add(workoutTypeToCategory(t));
  }
  const actualArr = [...actualCategories];

  if (plannedNonRest.length === 0) {
    if (actualArr.length > 0) {
      return { status: 'rest_extra', extraWorkoutType: actualArr[0] };
    }
    return { status: 'rest' };
  }

  const allPlannedCovered = plannedCategories.every((pc) => actualArr.includes(pc));
  if (allPlannedCovered) {
    return { status: 'completed' };
  }
  return { status: 'planned' };
}

/**
 * Утилиты для обработки данных статистики
 */

/**
 * Преобразуем период в дни
 */
export const getDaysFromRange = (range) => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  
  switch (range) {
    case 'last7days': {
      // Последние 7 дней (скользящее окно)
      const startDate = new Date(today);
      startDate.setDate(today.getDate() - 7);
      return { days: 7, startDate };
    }
    case 'week': {
      // Эта неделя (с понедельника)
      const dayOfWeek = today.getDay();
      const monday = new Date(today);
      monday.setDate(today.getDate() - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));
      return { days: 7, startDate: monday };
    }
    case 'month': {
      // Текущий месяц
      const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
      const days = Math.ceil((today - firstDay) / (1000 * 60 * 60 * 24)) + 1;
      return { days, startDate: firstDay };
    }
    case 'quarter': {
      // Последние 3 месяца
      const startDate = new Date(today);
      startDate.setMonth(today.getMonth() - 3);
      startDate.setDate(1);
      const days = Math.ceil((today - startDate) / (1000 * 60 * 60 * 24)) + 1;
      return { days, startDate };
    }
    case 'year': {
      // Последние 12 месяцев (не календарный год, а скользящий год)
      const startDate = new Date(today);
      startDate.setMonth(today.getMonth() - 12);
      startDate.setDate(1);
      const days = Math.ceil((today - startDate) / (1000 * 60 * 60 * 24)) + 1;
      return { days, startDate };
    }
    default:
      return { days: 30, startDate: new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000) };
  }
};

/**
 * Форматируем дату без проблем с часовыми поясами
 */
export const formatDateStr = (date) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};

/**
 * Форматируем темп из секунд в формат ММ:СС
 */
export const formatPace = (seconds) => {
  if (seconds === 0) return '—';
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
};

/**
 * Обработка данных для вкладки "Обзор"
 * @param {Object} workoutsData — { workouts: {...} } от getAllWorkoutsSummary
 * @param {Object} allResults — { results: [...] } от getAllResults
 * @param {Object} plan — план тренировок
 * @param {string} range — week|month|quarter|year
 * @param {Array} [workoutsList] — массив отдельных тренировок от getAllWorkoutsList (без группировки по дню)
 */
export const processStatsData = (workoutsData, allResults, plan, range, workoutsList = null) => {
  const { days, startDate } = getDaysFromRange(range);
  const cutoffDate = new Date(startDate);
  cutoffDate.setHours(0, 0, 0, 0);
  const rangeEnd = new Date(cutoffDate);
  rangeEnd.setDate(rangeEnd.getDate() + Math.max(0, days - 1));
  rangeEnd.setHours(23, 59, 59, 999);
  
  // getAllWorkoutsSummary возвращает объект вида: { "2026-01-20": { count, distance, duration, pace, hr, workout_url }, ... }
  // Нужно преобразовать в массив для удобной обработки
  const workoutsSummary = workoutsData?.workouts || {};
  const workoutsArray = Object.entries(workoutsSummary).map(([date, data]) => ({
    date,
    ...data,
    start_time: date + 'T00:00:00', // Для совместимости с существующим кодом
    distance_km: data.distance || 0,
    duration_minutes: data.duration || 0,
    duration_seconds: data.duration_seconds ?? null,
    avg_pace: data.pace || null
  }));
  
  // Фильтруем тренировки по дате
  const workouts = workoutsArray.filter(w => {
    if (!w || !w.date) return false;
    const workoutDate = new Date(w.date + 'T00:00:00');
    workoutDate.setHours(0, 0, 0, 0);
    return workoutDate >= cutoffDate;
  });
  
  // Вычисляем метрики
  const totalDistance = workouts.reduce((sum, w) => sum + (parseFloat(w.distance_km) || 0), 0);
  const totalTime = workouts.reduce((sum, w) => sum + (parseInt(w.duration_minutes) || 0), 0);
  const totalWorkouts = workouts.reduce((sum, w) => sum + (parseInt(w.count) || 0), 0);
  
  // Средний темп вычисляем из всех тренировок с темпом
  // ВАЖНО: темп из API приходит как строка "ММ:СС" или NULL
  // AVG() в MySQL не работает правильно со строками, поэтому нужно обрабатывать на фронтенде
  let totalPaceSeconds = 0;
  let paceCount = 0;
  
  workouts.forEach(w => {
    // Проверяем разные варианты формата темпа
    let paceValue = w.avg_pace || w.pace || null;
    
    if (paceValue !== null && paceValue !== undefined && paceValue !== '') {
      let paceSeconds = 0;
      let isValid = false;
      
      if (typeof paceValue === 'string') {
        // Формат строки "ММ:СС" или "М:СС"
        const paceParts = paceValue.split(':');
        if (paceParts.length === 2) {
          const mins = parseInt(paceParts[0], 10);
          const secs = parseInt(paceParts[1], 10);
          if (!isNaN(mins) && !isNaN(secs) && mins >= 0 && secs >= 0 && secs < 60) {
            paceSeconds = mins * 60 + secs;
            isValid = true;
          }
        }
      } else if (typeof paceValue === 'number' && !isNaN(paceValue)) {
        // Число - может быть результатом некорректного AVG() от строк
        // Пропускаем такие значения, так как они неверны
        isValid = false;
      }
      
      // Если темп валидный (от 2 минут/км до 20 минут/км = 120-1200 секунд)
      if (isValid && paceSeconds >= 120 && paceSeconds <= 1200) {
        const count = w.count || 1;
        totalPaceSeconds += paceSeconds * count;
        paceCount += count;
      }
    }
  });
  
  const avgPaceSeconds = paceCount > 0 ? Math.round(totalPaceSeconds / paceCount) : 0;
  
  // Данные для графика (по дням)
  const chartData = [];
  for (let i = 0; i < days; i++) {
    const date = new Date(cutoffDate);
    date.setDate(cutoffDate.getDate() + i);
    date.setHours(0, 0, 0, 0);
    const dateStr = formatDateStr(date);
    
    // Находим данные для этого дня
    const dayData = workoutsSummary[dateStr];
    
    chartData.push({
      date: dateStr,
      dateLabel: date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
      distance: dayData ? (dayData.distance || 0) : 0,
      time: dayData ? (dayData.duration || 0) : 0,
      workouts: dayData ? (dayData.count || 0) : 0
    });
  }
  
  // Прогресс по плану
  let planProgress = null;
  const weeksData = plan?.weeks_data;
  if (plan && Array.isArray(weeksData)) {
    const allTrainingDays = [];
    const trainingDaysMap = {}; // Карта для быстрой проверки типа дня
    
    weeksData.forEach(week => {
      if (week.days && week.start_date) {
        const startDate = new Date(week.start_date + 'T00:00:00');
        startDate.setHours(0, 0, 0, 0);
        
        const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        dayKeys.forEach((dayKey, index) => {
          const day = week.days[dayKey];
          if (day && day.type !== 'rest') {
            const dayDate = new Date(startDate);
            dayDate.setDate(startDate.getDate() + index);
            const dateStr = formatDateStr(dayDate);
            
            allTrainingDays.push(day);
            trainingDaysMap[dateStr] = true;
          }
        });
      }
    });
    
    // Фильтруем выполненные дни: учитываем только те, которые являются тренировочными днями
    const completedDays = (allResults?.results || []).filter(r => {
      if (!r.training_date) return false;
      // Проверяем, что это тренировочный день (не день отдыха)
      return trainingDaysMap[r.training_date] === true;
    }).length;
    
    const totalDays = allTrainingDays.length;
    
    planProgress = {
      completed: completedDays,
      total: totalDays,
      percentage: totalDays > 0 ? Math.round((completedDays / totalDays) * 100) : 0
    };
  }
  
  // Последние тренировки: если передан workoutsList — каждая тренировка отдельно; иначе — группировка по дню (legacy)
  let recentWorkouts;
  if (Array.isArray(workoutsList) && workoutsList.length > 0) {
    recentWorkouts = workoutsList
      .filter(w => {
        const dateStr = w.start_time ? w.start_time.split('T')[0] : w.date;
        if (!dateStr) return false;
        const d = new Date(dateStr + 'T00:00:00');
        return d >= cutoffDate && d <= rangeEnd;
      })
      .map(w => ({
        id: w.id,
        date: w.date || (w.start_time ? w.start_time.split('T')[0] : null),
        start_time: w.start_time || (w.date ? w.date + 'T00:00:00' : null),
        distance_km: w.distance_km ?? 0,
        duration_minutes: w.duration_minutes ?? null,
        duration_seconds: w.duration_seconds ?? null,
        avg_pace: w.avg_pace,
        avg_heart_rate: w.avg_heart_rate ?? null,
        max_heart_rate: w.max_heart_rate ?? null,
        elevation_gain: w.elevation_gain ?? null,
        activity_type: w.activity_type || 'running',
        is_manual: w.is_manual ?? false,
        source: w.source ?? null,
      }));
  } else {
    recentWorkouts = workouts
      .filter(w => {
        const distance = parseFloat(w.distance_km) || 0;
        return distance > 0;
      })
      .sort((a, b) => b.date.localeCompare(a.date))
      .map(w => ({
        date: w.date,
        start_time: w.start_time || w.date + 'T00:00:00',
        distance_km: w.distance_km,
        duration_minutes: w.duration_minutes,
        duration_seconds: w.duration_seconds ?? null,
        avg_pace: w.avg_pace,
        count: w.count,
        activity_type: w.activity_type || 'running'
      }));
  }
  
  return {
    totalDistance: Math.round(totalDistance * 10) / 10,
    totalTime,
    totalWorkouts,
    avgPace: formatPace(avgPaceSeconds),
    chartData,
    planProgress,
    workouts: recentWorkouts
  };
};

/**
 * Обработка данных для вкладки "Прогресс" - только данные из плана
 */
export const processProgressData = (workoutsData, allResults, plan) => {
  const formatDateStr = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  // Прогресс по плану
  let planProgress = null;
  const chartData = [];
  
  const weeksData = plan?.weeks_data;
  if (plan && Array.isArray(weeksData)) {
    const allTrainingDays = [];
    const trainingDaysMap = {};
    
    weeksData.forEach(week => {
      if (week.days && week.start_date) {
        const startDate = new Date(week.start_date + 'T00:00:00');
        startDate.setHours(0, 0, 0, 0);
        
        const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        dayKeys.forEach((dayKey, index) => {
          const day = week.days[dayKey];
          if (day && day.type !== 'rest') {
            const dayDate = new Date(startDate);
            dayDate.setDate(startDate.getDate() + index);
            const dateStr = formatDateStr(dayDate);
                
                allTrainingDays.push(day);
                trainingDaysMap[dateStr] = true;
                
                // Добавляем данные для графика
                const dayData = workoutsData?.workouts?.[dateStr];
                chartData.push({
                  date: dateStr,
                  dateLabel: dayDate.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
                  distance: dayData ? (dayData.distance || 0) : 0,
                  workouts: dayData ? (dayData.count || 0) : 0
                });
          }
        });
      }
    });
    
    const completedDays = (allResults?.results || []).filter(r => {
      if (!r.training_date) return false;
      return trainingDaysMap[r.training_date] === true;
    }).length;
    
    const totalDays = allTrainingDays.length;
    
    planProgress = {
      completed: completedDays,
      total: totalDays,
      percentage: totalDays > 0 ? Math.round((completedDays / totalDays) * 100) : 0
    };
  }
  
  // Сортируем данные графика по дате
  chartData.sort((a, b) => a.date.localeCompare(b.date));
  
  return {
    planProgress,
    chartData
  };
};

/**
 * Обработка данных для вкладки "Достижения" - общие данные (все время)
 */
export const processAchievementsData = (workoutsData, allResults) => {
  const workoutsSummary = workoutsData?.workouts || {};
  const workoutsArray = Object.entries(workoutsSummary).map(([date, data]) => ({
    date,
    ...data,
    distance_km: data.distance || 0,
    duration_minutes: data.duration || 0
  }));
  
  const totalDistance = workoutsArray.reduce((sum, w) => sum + (parseFloat(w.distance_km) || 0), 0);
  const totalWorkouts = workoutsArray.reduce((sum, w) => sum + (parseInt(w.count) || 0), 0);
  
  return {
    totalDistance: Math.round(totalDistance * 10) / 10,
    totalWorkouts
  };
};

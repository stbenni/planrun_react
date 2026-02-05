/**
 * Экран календаря тренировок (веб-версия)
 */

import React, { useState, useEffect } from 'react';
import useAuthStore from '../stores/useAuthStore';
import WeekCalendar from '../components/Calendar/WeekCalendar';
import MonthlyCalendar from '../components/Calendar/MonthlyCalendar';
import DayModal from '../components/Calendar/DayModal';
import ResultModal from '../components/Calendar/ResultModal';
import SkeletonScreen from '../components/common/SkeletonScreen';
import '../assets/css/calendar_v2.css';
import '../assets/css/short-desc.css';
import './CalendarScreen.css';
import BottomNav from '../components/common/BottomNav';

const CalendarScreen = ({ targetUserId = null, canEdit = true, isOwner = true, hideHeader = false, viewMode: externalViewMode = null }) => {
  const { api, user } = useAuthStore();
  // Используем targetUserId если передан, иначе текущего пользователя
  const calendarUserId = targetUserId || user?.id;
  const [plan, setPlan] = useState(null);
  const [progressData, setProgressData] = useState({});
  const [workoutsData, setWorkoutsData] = useState({}); // Данные о тренировках по датам
  const [resultsData, setResultsData] = useState({}); // Данные о результатах по датам
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [dayModal, setDayModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [resultModal, setResultModal] = useState({ isOpen: false, date: null, week: null, day: null });
  // Инициализируем viewMode: если передан externalViewMode, используем его, иначе 'week'
  // Если externalViewMode задан, он фиксирует режим (для публичных профилей)
  // Если не задан, пользователь может свободно переключаться
  const [viewMode, setViewMode] = useState(() => externalViewMode || 'week');
  
  // Синхронизируем viewMode с externalViewMode только если он задан (для публичных профилей)
  // Это позволяет фиксировать режим на публичных страницах
  useEffect(() => {
    if (externalViewMode !== null && externalViewMode !== undefined) {
      setViewMode(externalViewMode);
    }
  }, [externalViewMode]);

  const getCurrentWeekNumber = (plan) => {
    if (!plan || !plan.phases) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    for (const phase of plan.phases) {
      if (!phase.weeks_data) continue;
      for (const week of phase.weeks_data) {
        if (!week.start_date) continue;
        const startDate = new Date(week.start_date);
        startDate.setHours(0, 0, 0, 0);
        const endDate = new Date(startDate);
        endDate.setDate(endDate.getDate() + 7);
        endDate.setHours(23, 59, 59, 999);
        if (today >= startDate && today <= endDate) {
          return week.number;
        }
      }
    }
    return null;
  };

  useEffect(() => {
    loadPlan();
  }, [calendarUserId]); // Перезагружаем при смене пользователя

  const loadPlan = async () => {
    if (!api) {
      setLoading(false);
      return;
    }
    
    try {
      setLoading(true);
      
      // Загружаем план
      const planData = await api.getPlan(calendarUserId !== user?.id ? calendarUserId : null);
      
      // Проверяем структуру ответа (может быть data.phases или просто phases)
      // TrainingPlanService возвращает planData с phases
      // BaseController оборачивает в {success: true, data: planData}
      // ApiClient возвращает data.data || data
      // Итого: planData может быть {phases: [...]} или просто объект с phases
      const plan = planData?.data || planData;
      setPlan(plan);
      
      // Загружаем все тренировки (из GPX/TCX файлов) - сначала, чтобы потом обновить progressData
      let workouts = {};
      try {
        const workoutsSummary = await api.getAllWorkoutsSummary(calendarUserId && calendarUserId !== user?.id ? calendarUserId : null);
        
        // Проверяем структуру ответа
        // StatsService возвращает объект: {date: {count, distance, duration, pace, hr, workout_url}}
        // BaseController оборачивает в {success: true, data: {...}}
        // ApiClient возвращает data.data || data
        if (workoutsSummary?.data) {
          workouts = workoutsSummary.data;
        } else if (workoutsSummary && typeof workoutsSummary === 'object') {
          workouts = workoutsSummary;
        }
        setWorkoutsData(workouts);
      } catch (error) {
        console.error('Error loading workouts:', error);
        setWorkoutsData({});
      }
      
      // Загружаем прогресс из getAllResults (результаты из workout_log)
      // И объединяем с тренировками из workouts (GPX/TCX)
      // День считается выполненным если есть тренировка ИЛИ результат
      try {
        const allResults = await api.getAllResults(calendarUserId && calendarUserId !== user?.id ? calendarUserId : null);
        
        // Проверяем структуру ответа (может быть data.results или просто results)
        // WorkoutService возвращает ['results' => $results]
        // BaseController оборачивает в {success: true, data: {results: [...]}}
        // ApiClient возвращает data.data || data
        // Итого: allResults может быть {results: [...]} или просто массив
        let results = [];
        
        if (Array.isArray(allResults)) {
          // Если это массив напрямую
          results = allResults;
        } else if (allResults?.data?.results && Array.isArray(allResults.data.results)) {
          // Формат: {data: {results: [...]}}
          results = allResults.data.results;
        } else if (allResults?.results && Array.isArray(allResults.results)) {
          // Формат: {results: [...]}
          results = allResults.results;
        }
        
        // Создаем progressData из результатов workout_log
        const newProgressData = {};
        results.forEach(result => {
          if (result.training_date) {
            newProgressData[result.training_date] = true;
          }
        });
        
        // ДОБАВЛЯЕМ тренировки из workouts (GPX/TCX) - день считается выполненным если есть тренировка
        Object.keys(workouts).forEach(date => {
          if (workouts[date] && (workouts[date].distance || workouts[date].duration)) {
            newProgressData[date] = true;
          }
        });
        setProgressData(newProgressData);
      } catch (error) {
        console.error('Error loading progress:', error);
        // Если ошибка загрузки результатов, используем только workouts
        const fallbackProgress = {};
        Object.keys(workouts).forEach(date => {
          if (workouts[date] && (workouts[date].distance || workouts[date].duration)) {
            fallbackProgress[date] = true;
          }
        });
        setProgressData(fallbackProgress);
      }
      
      // Загружаем результаты тренировок для отображения
      try {
        const allResults = await api.getAllResults(calendarUserId && calendarUserId !== user?.id ? calendarUserId : null);
        
        let results = [];
        if (Array.isArray(allResults)) {
          results = allResults;
        } else if (allResults?.data?.results && Array.isArray(allResults.data.results)) {
          results = allResults.data.results;
        } else if (allResults?.results && Array.isArray(allResults.results)) {
          results = allResults.results;
        }
        
        // Группируем результаты по датам
        const resultsByDate = {};
        results.forEach(result => {
          if (result.training_date) {
            const key = `${result.training_date}_${result.week_number || 0}_${result.day_name || ''}`;
            if (!resultsByDate[result.training_date]) {
              resultsByDate[result.training_date] = [];
            }
            resultsByDate[result.training_date].push(result);
          }
        });
        setResultsData(resultsByDate);
      } catch (error) {
        console.error('Error loading results for display:', error);
        setResultsData({});
      }
    } catch (error) {
      console.error('Error loading plan:', error);
      setPlan(null);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const onRefresh = () => {
    setRefreshing(true);
    loadPlan();
  };

  if (loading && !plan) {
    return (
      <div className="calendar-container">
        <SkeletonScreen type="calendar" />
      </div>
    );
  }

  if (!plan || !plan.phases || plan.phases.length === 0) {
    return (
      <div className="calendar-container">
        <div className="empty-container">
          <p className="empty-text">План тренировок не найден</p>
          <p className="empty-subtext">
            Создайте план в настройках или через веб-версию
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="container">
      <div className="calendar-view-toggle">
        <button 
          className={`view-toggle-btn ${viewMode === 'week' ? 'active' : ''}`}
          onClick={() => setViewMode('week')}
          disabled={externalViewMode !== null && externalViewMode !== undefined}
        >
          📅 Неделя
        </button>
        <button 
          className={`view-toggle-btn ${viewMode === 'full' ? 'active' : ''}`}
          onClick={() => setViewMode('full')}
          disabled={externalViewMode !== null && externalViewMode !== undefined}
        >
          📋 Полный план
        </button>
      </div>

      <div className="content">
        {viewMode === 'week' ? (
          <WeekCalendar
            plan={plan}
            progressData={progressData}
            workoutsData={workoutsData}
            resultsData={resultsData}
            api={api}
            onDayPress={(date, weekNumber, dayKey) => {
              if (canEdit || isOwner) {
                setDayModal({ isOpen: true, date, week: weekNumber, day: dayKey });
              }
            }}
            currentWeekNumber={getCurrentWeekNumber(plan)}
          />
        ) : (
          <div className="week-calendar-container">
            <MonthlyCalendar
              workoutsData={workoutsData}
              resultsData={resultsData}
              planData={plan}
              api={api}
              onDateClick={(date) => {
                if (canEdit || isOwner) {
                  // Парсим дату для DayModal
                  const dateStr = typeof date === 'string' ? date : date.toISOString().split('T')[0];
                  setDayModal({ isOpen: true, date: dateStr, week: null, day: null });
                }
              }}
              canEdit={canEdit}
              targetUserId={calendarUserId}
            />
          </div>
        )}
      </div>

      <DayModal
        isOpen={dayModal.isOpen}
        onClose={() => setDayModal({ isOpen: false, date: null, week: null, day: null })}
        date={dayModal.date}
        weekNumber={dayModal.week}
        dayKey={dayModal.day}
        api={api}
        canEdit={canEdit}
        targetUserId={calendarUserId}
      />

      <ResultModal
        isOpen={resultModal.isOpen}
        onClose={() => setResultModal({ isOpen: false, date: null, week: null, day: null })}
        date={resultModal.date}
        weekNumber={resultModal.week}
        dayKey={resultModal.day}
        api={api}
        onSave={() => {
          loadPlan(); // Перезагрузить план после сохранения
        }}
      />
    </div>
  );
};

export default CalendarScreen;

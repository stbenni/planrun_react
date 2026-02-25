/**
 * Экран календаря тренировок (веб-версия)
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useLocation } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import usePlanStore from '../stores/usePlanStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import WeekCalendar from '../components/Calendar/WeekCalendar';
import MonthlyCalendar from '../components/Calendar/MonthlyCalendar';
import DayModal from '../components/Calendar/DayModal';
import ResultModal from '../components/Calendar/ResultModal';
import AddTrainingModal from '../components/Calendar/AddTrainingModal';
import { WorkoutDetailsModal } from '../components/Stats';
import SkeletonScreen from '../components/common/SkeletonScreen';
import '../assets/css/calendar_v2.css';
import '../assets/css/short-desc.css';
import './CalendarScreen.css';
import './StatsScreen.css';

const CalendarScreen = ({ targetUserId = null, canEdit = true, isOwner = true, hideHeader = false, viewMode: externalViewMode = null }) => {
  const isTabActive = useIsTabActive('/calendar');
  const location = useLocation();
  const { api, user } = useAuthStore();
  // Используем targetUserId если передан, иначе текущего пользователя
  const calendarUserId = targetUserId || user?.id;
  const [plan, setPlan] = useState(null);
  const openedFromStateRef = useRef(false);
  const [progressData, setProgressData] = useState({});
  const [workoutsData, setWorkoutsData] = useState({}); // Данные о тренировках по датам
  const [resultsData, setResultsData] = useState({}); // Данные о результатах по датам
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [dayModal, setDayModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [resultModal, setResultModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [addTrainingModal, setAddTrainingModal] = useState({ isOpen: false, date: null, planDay: null, editResultData: null });
  const [dayModalRefreshKey, setDayModalRefreshKey] = useState(0);
  const [workoutDetailsModal, setWorkoutDetailsModal] = useState({ isOpen: false, date: null, dayData: null, loading: false, weekNumber: null, dayKey: null });
  const { recalculating, recalculatePlan, generatingNext, generateNextPlan } = usePlanStore();
  const [showRecalcConfirm, setShowRecalcConfirm] = useState(false);
  const [recalcReason, setRecalcReason] = useState('');
  const [showNextPlanModal, setShowNextPlanModal] = useState(false);
  const [nextPlanGoals, setNextPlanGoals] = useState('');
  const hasPlan = plan && Array.isArray(plan.weeks_data) && plan.weeks_data.length > 0;

  const isPlanCompleted = hasPlan && (() => {
    const weeks = plan.weeks_data;
    const lastWeek = weeks[weeks.length - 1];
    if (!lastWeek?.start_date) return false;
    const lastWeekEnd = new Date(lastWeek.start_date);
    lastWeekEnd.setDate(lastWeekEnd.getDate() + 6);
    return lastWeekEnd < new Date(new Date().toDateString());
  })();

  const handleOpenRecalc = useCallback(() => {
    setRecalcReason('');
    setShowRecalcConfirm(true);
  }, []);

  const handleRecalculate = useCallback(async () => {
    const reason = recalcReason.trim();
    setShowRecalcConfirm(false);
    setRecalcReason('');
    const ok = await recalculatePlan(reason || null);
    if (ok) {
      loadPlan();
    }
  }, [recalculatePlan, recalcReason]);

  const handleOpenNextPlan = useCallback(() => {
    setNextPlanGoals('');
    setShowNextPlanModal(true);
  }, []);

  const handleGenerateNextPlan = useCallback(async () => {
    const goals = nextPlanGoals.trim();
    setShowNextPlanModal(false);
    setNextPlanGoals('');
    const ok = await generateNextPlan(goals || null);
    if (ok) {
      loadPlan();
    }
  }, [generateNextPlan, nextPlanGoals]);

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
    const weeksData = plan?.weeks_data;
    if (!plan || !Array.isArray(weeksData)) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    for (const week of weeksData) {
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
    return null;
  };

  const hasLoadedRef = useRef(false);
  useEffect(() => {
    if (!isTabActive && !hasLoadedRef.current) return;
    hasLoadedRef.current = true;
    loadPlan();
  }, [calendarUserId, isTabActive]);

  // Переход с дашборда с датой (карточка «Сегодня» / «Следующая») — открыть день в модалке
  useEffect(() => {
    const stateDate = location.state?.date;
    if (!stateDate || !plan || openedFromStateRef.current) return;
    openedFromStateRef.current = true;
    setDayModal({
      isOpen: true,
      date: stateDate,
      week: location.state?.week ?? null,
      day: location.state?.day ?? null,
    });
  }, [plan, location.state]);

  const loadPlan = async (options = {}) => {
    const silent = options.silent === true; // обновление без показа загрузки (после add/delete)
    if (!api) {
      setLoading(false);
      return;
    }
    
    try {
      if (!silent) setLoading(true);
      
      // Загружаем план
      const planData = await api.getPlan(calendarUserId !== user?.id ? calendarUserId : null);
      
      // Проверяем структуру ответа (может быть data.weeks_data)
      // TrainingPlanService возвращает planData с weeks_data
      // ApiClient возвращает data.data || data
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
      if (!silent) setPlan(null);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const onRefresh = () => {
    setRefreshing(true);
    loadPlan();
  };

  const handleOpenWorkoutDetails = async (date, weekNumber = null, dayKey = null) => {
    if (!api || !date) return;
    try {
      setWorkoutDetailsModal({ isOpen: true, date, dayData: null, loading: true, weekNumber, dayKey });
      const response = await api.getDay(date);
      const raw = response?.data != null ? response.data : response;
      const dayData = raw && typeof raw === 'object' ? {
        ...raw,
        planDays: raw.planDays ?? raw.plan_days ?? [],
        dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
        workouts: raw.workouts ?? [],
      } : null;
      setWorkoutDetailsModal((prev) => ({ ...prev, dayData, loading: false }));
    } catch (error) {
      console.error('Error loading workout details:', error);
      setWorkoutDetailsModal((prev) => ({ ...prev, dayData: null, loading: false }));
    }
  };

  const handleCloseWorkoutDetails = () => {
    setWorkoutDetailsModal({ isOpen: false, date: null, dayData: null, loading: false, weekNumber: null, dayKey: null });
  };

  const handleEditWorkoutResult = async () => {
    const { date, weekNumber, dayKey, dayData } = workoutDetailsModal;
    if (!date || !api) return;
    // Собираем результат: сначала getResult, при отсутствии — из уже загруженных workouts дня (get_day)
    let result = null;
    try {
      const res = await api.getResult(date);
      const raw = res?.result ?? res?.data?.result ?? res;
      if (raw && (raw.distance_km != null || raw.result_time != null || raw.result_distance != null || raw.notes != null)) {
        result = raw;
      }
    } catch {
      // игнорируем, подставим из dayData
    }
    if (result == null && dayData?.workouts?.length) {
      const w = dayData.workouts.find((wo) => wo.is_manual || wo.distance_km != null || wo.result_time != null || wo.notes != null) ?? dayData.workouts[0];
      result = {
        distance_km: w.distance_km ?? w.result_distance,
        result_time: w.result_time,
        pace: w.pace ?? w.avg_pace ?? w.result_pace,
        notes: w.notes,
      };
    }
    result = result || {};
    handleCloseWorkoutDetails();
    setAddTrainingModal({
      isOpen: true,
      date,
      planDay: null,
      editResultData: { date, weekNumber, dayKey, result, dayData },
    });
  };

  if (loading && !plan) {
    return (
      <div className="calendar-container">
        <SkeletonScreen type="calendar" />
      </div>
    );
  }

  // Календарь всегда доступен: при ошибке загрузки показываем сообщение, иначе — сетку (пустую или с планом)
  if (!loading && plan === null) {
    return (
      <div className="calendar-container">
        <div className="empty-container">
          <p className="empty-text">Не удалось загрузить календарь</p>
          <p className="empty-subtext">
            Проверьте подключение и обновите страницу
          </p>
        </div>
      </div>
    );
  }

  const isGenerating = recalculating || generatingNext;
  // plan может быть с пустыми weeks_data — календарь покажет пустую сетку, тренировки навешиваются на даты
  const planData = plan || { weeks_data: [] };

  return (
    <div className="container calendar-screen">
      <div className="content">
        {isPlanCompleted && canEdit && isOwner && !isGenerating && (
          <div className="plan-completed-banner">
            <div className="plan-completed-banner__icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm-1 14.59l-3.3-3.3 1.41-1.41L11 13.76l4.89-4.89 1.41 1.41L11 16.59z" fill="currentColor"/></svg>
            </div>
            <div className="plan-completed-banner__text">
              <strong>План завершён!</strong>
              <span>Создайте новый план — AI-тренер учтёт все ваши тренировки и прогресс.</span>
            </div>
            <button className="btn btn-primary btn--sm" onClick={handleOpenNextPlan}>
              Создать новый план
            </button>
          </div>
        )}
        {isGenerating && (
          <div className="plan-generating-banner">
            <span className="btn-spinner" />
            <span>{generatingNext ? 'Генерация нового плана...' : 'Пересчёт плана...'} Это займёт 3-5 минут.</span>
          </div>
        )}
        <div className="calendar-header-row">
          <div className="calendar-view-toggle">
            <button 
              className={`view-toggle-btn ${viewMode === 'week' ? 'active' : ''}`}
              onClick={() => setViewMode('week')}
              disabled={externalViewMode !== null && externalViewMode !== undefined}
            >
              Неделя
            </button>
            <button 
              className={`view-toggle-btn ${viewMode === 'full' ? 'active' : ''}`}
              onClick={() => setViewMode('full')}
              disabled={externalViewMode !== null && externalViewMode !== undefined}
            >
              Месяц
            </button>
          </div>
          {hasPlan && canEdit && isOwner && (
            <div className="calendar-plan-actions">
              {isPlanCompleted ? (
                <button
                  className="btn btn-primary btn-next-plan"
                  onClick={handleOpenNextPlan}
                  disabled={generatingNext}
                >
                  {generatingNext ? (
                    <>
                      <span className="btn-spinner" />
                      Генерация...
                    </>
                  ) : (
                    <>
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 0a8 8 0 100 16A8 8 0 008 0zm1 11H7V7h2v4zm0-6H7V3h2v2z" fill="currentColor"/>
                      </svg>
                      Новый план
                    </>
                  )}
                </button>
              ) : (
                <button
                  className="btn btn-recalculate"
                  onClick={handleOpenRecalc}
                  disabled={recalculating}
                >
                  {recalculating ? (
                    <>
                      <span className="btn-spinner" />
                      Пересчёт...
                    </>
                  ) : (
                    <>
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M13.65 2.35A7.96 7.96 0 008 0a8 8 0 100 16 7.97 7.97 0 005.65-2.35l-1.41-1.41A5.98 5.98 0 018 14 6 6 0 118 2c1.66 0 3.14.69 4.22 1.78L9 7h7V0l-2.35 2.35z" fill="currentColor"/>
                      </svg>
                      Пересчитать
                    </>
                  )}
                </button>
              )}
            </div>
          )}
        </div>

        {showRecalcConfirm && (
          <div className="modal" style={{ display: 'block' }} onClick={() => setShowRecalcConfirm(false)}>
            <div className="modal-content recalc-confirm-modal" onClick={e => e.stopPropagation()}>
              <h3>Пересчитать план</h3>
              <p>Расскажите, почему вы хотите пересчитать план. Чем подробнее — тем точнее ИИ-тренер подберёт новую программу.</p>
              <div className="recalc-reason-hints">
                {['Был перерыв в тренировках', 'Чувствую, что план слишком тяжёлый', 'Получил травму / болел', 'Хочу увеличить нагрузку', 'Изменились цели'].map(hint => (
                  <button
                    key={hint}
                    type="button"
                    className={`recalc-hint-chip${recalcReason.includes(hint) ? ' active' : ''}`}
                    onClick={() => setRecalcReason(prev => {
                      if (prev.includes(hint)) return prev;
                      return prev ? `${prev}. ${hint}` : hint;
                    })}
                  >
                    {hint}
                  </button>
                ))}
              </div>
              <textarea
                className="recalc-reason-input"
                placeholder="Например: не занимался 2 недели из-за простуды, сейчас чувствую себя нормально, хотел бы плавно вернуться..."
                value={recalcReason}
                onChange={e => setRecalcReason(e.target.value)}
                rows={4}
                maxLength={1000}
              />
              <p className="recalc-confirm-note">Прошлые результаты тренировок сохранятся. Пересчёт займёт 3-5 минут.</p>
              <div className="recalc-confirm-actions">
                <button className="btn btn-secondary" onClick={() => setShowRecalcConfirm(false)}>Отмена</button>
                <button className="btn btn-primary" onClick={handleRecalculate}>Пересчитать план</button>
              </div>
            </div>
          </div>
        )}
        {showNextPlanModal && (
          <div className="modal" style={{ display: 'block' }} onClick={() => setShowNextPlanModal(false)}>
            <div className="modal-content recalc-confirm-modal" onClick={e => e.stopPropagation()}>
              <h3>Новый тренировочный план</h3>
              <p>Предыдущий план завершён. AI-тренер создаст новый план, основываясь на всех ваших прошлых тренировках, прогрессе и текущей форме.</p>
              <p style={{ fontSize: 'var(--text-sm)', color: 'var(--text-secondary)', marginTop: 'var(--space-2)' }}>
                Расскажите, какие у вас цели на новый план (необязательно):
              </p>
              <div className="recalc-reason-hints">
                {['Продолжить прогрессию', 'Подготовка к забегу', 'Увеличить дистанцию', 'Улучшить скорость', 'Восстановление после соревнования'].map(hint => (
                  <button
                    key={hint}
                    type="button"
                    className={`recalc-hint-chip${nextPlanGoals.includes(hint) ? ' active' : ''}`}
                    onClick={() => setNextPlanGoals(prev => {
                      if (prev.includes(hint)) return prev;
                      return prev ? `${prev}. ${hint}` : hint;
                    })}
                  >
                    {hint}
                  </button>
                ))}
              </div>
              <textarea
                className="recalc-reason-input"
                placeholder="Например: хочу подготовиться к полумарафону через 3 месяца, в прошлом плане чувствовал себя хорошо..."
                value={nextPlanGoals}
                onChange={e => setNextPlanGoals(e.target.value)}
                rows={4}
                maxLength={2000}
              />
              <p className="recalc-confirm-note">Все ваши прошлые тренировки будут учтены. Генерация займёт 3-5 минут.</p>
              <div className="recalc-confirm-actions">
                <button className="btn btn-secondary" onClick={() => setShowNextPlanModal(false)}>Отмена</button>
                <button className="btn btn-primary" onClick={handleGenerateNextPlan}>Создать новый план</button>
              </div>
            </div>
          </div>
        )}
        {viewMode === 'week' ? (
          <WeekCalendar
            plan={planData}
            progressData={progressData}
            workoutsData={workoutsData}
            resultsData={resultsData}
            api={api}
            canEdit={canEdit}
            onDayPress={(date, weekNumber, dayKey) => {
              if (canEdit || isOwner) {
                setDayModal({ isOpen: true, date, week: weekNumber, day: dayKey });
              }
            }}
            onOpenWorkoutDetails={(date, weekNumber, dayKey) => handleOpenWorkoutDetails(date, weekNumber, dayKey)}
            onOpenResultModal={(date, week, day) => setResultModal({ isOpen: true, date, week, day })}
            onAddTraining={(date) => setAddTrainingModal({ isOpen: true, date, planDay: null })}
            onEditTraining={(planDay, date) => setAddTrainingModal({ isOpen: true, date, planDay })}
            onTrainingAdded={() => loadPlan({ silent: true })}
            currentWeekNumber={getCurrentWeekNumber(planData)}
            initialDate={location.state?.date}
          />
        ) : (
          <div className="week-calendar-container">
            <MonthlyCalendar
              workoutsData={workoutsData}
              resultsData={resultsData}
              planData={planData}
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
        onTrainingAdded={() => loadPlan({ silent: true })}
        onEditTraining={(planDay, date) => setAddTrainingModal({ isOpen: true, date, planDay })}
        onOpenResultModal={(date, week, day) => setResultModal({ isOpen: true, date, week, day })}
        refreshKey={dayModalRefreshKey}
      />

      <ResultModal
        isOpen={resultModal.isOpen}
        onClose={() => setResultModal({ isOpen: false, date: null, week: null, day: null })}
        date={resultModal.date}
        weekNumber={resultModal.week}
        dayKey={resultModal.day}
        api={api}
        onSave={() => {
          loadPlan({ silent: true });
        }}
      />

      <WorkoutDetailsModal
        isOpen={workoutDetailsModal.isOpen}
        onClose={handleCloseWorkoutDetails}
        date={workoutDetailsModal.date}
        dayData={workoutDetailsModal.dayData}
        loading={workoutDetailsModal.loading}
        weekNumber={workoutDetailsModal.weekNumber}
        dayKey={workoutDetailsModal.dayKey}
        onEdit={workoutDetailsModal.dayData?.workouts?.length ? handleEditWorkoutResult : undefined}
      />

      <AddTrainingModal
        isOpen={addTrainingModal.isOpen}
        onClose={() => setAddTrainingModal({ isOpen: false, date: null, planDay: null, editResultData: null })}
        date={addTrainingModal.date}
        api={api}
        initialData={addTrainingModal.planDay ? { ...addTrainingModal.planDay, date: addTrainingModal.date } : null}
        editResultData={addTrainingModal.editResultData}
        onSuccess={() => {
          loadPlan({ silent: true });
          setAddTrainingModal({ isOpen: false, date: null, planDay: null, editResultData: null });
          setDayModalRefreshKey((k) => k + 1);
        }}
      />
    </div>
  );
};

export default CalendarScreen;

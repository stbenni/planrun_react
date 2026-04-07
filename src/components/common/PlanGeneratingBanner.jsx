/**
 * Глобальный баннер генерации/пересчёта плана.
 * Показывается на всех страницах (кроме Calendar/Dashboard — у них свои баннеры).
 * Логика генерации и поллинга — в usePlanStore (единый источник правды).
 */

import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import usePlanStore from '../../stores/usePlanStore';
import useAuthStore from '../../stores/useAuthStore';
import './PlanGeneratingBanner.css';

const PlanGeneratingBanner = () => {
  const location = useLocation();
  const { api } = useAuthStore();
  const isGenerating = usePlanStore((s) => s.isGenerating);
  const generationLabel = usePlanStore((s) => s.generationLabel);
  const initPlanStatus = usePlanStore((s) => s.initPlanStatus);

  // При mount (после F5 / открытия) — проверить статус и запустить поллинг если нужно
  useEffect(() => {
    if (!api) return;
    initPlanStatus();
  }, [api]);

  // На CalendarScreen и Dashboard свои баннеры — не дублируем UI
  const isCalendarPage = location.pathname === '/calendar' || location.pathname.startsWith('/calendar');
  const isDashboardPage = location.pathname === '/' || location.pathname === '/dashboard';
  const isVisible = isGenerating && !isCalendarPage && !isDashboardPage;

  if (!isVisible) return null;

  return (
    <div className="plan-generating-global-banner">
      <span className="plan-generating-global-spinner" />
      <span>{generationLabel} Это займёт 3-5 минут.</span>
    </div>
  );
};

export default PlanGeneratingBanner;

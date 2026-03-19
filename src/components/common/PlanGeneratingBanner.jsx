/**
 * Глобальный баннер генерации/пересчёта плана.
 * Показывается на всех страницах, пока план генерируется.
 * Автоматически запускает поллинг при обнаружении активной генерации.
 */

import React, { useEffect, useRef } from 'react';
import usePlanStore from '../../stores/usePlanStore';
import useAuthStore from '../../stores/useAuthStore';
import './PlanGeneratingBanner.css';

const PlanGeneratingBanner = () => {
  const { planStatus, recalculating, generatingNext, checkPlanStatus, loadPlan } = usePlanStore();
  const { api } = useAuthStore();
  const pollingRef = useRef(false);
  const timerRef = useRef(null);

  const isGeneratingFromAction = recalculating || generatingNext;
  const isGeneratingFromStatus = planStatus?.generating === true;
  const isVisible = isGeneratingFromAction || isGeneratingFromStatus;

  // При mount — проверить статус плана (после F5 / открытия приложения)
  useEffect(() => {
    if (!api) return;
    checkPlanStatus();
  }, [api]);

  // Поллинг: если генерация обнаружена через planStatus (не через action),
  // значит страница была обновлена — нужно поллить до завершения
  useEffect(() => {
    if (!api) return;
    if (!isGeneratingFromStatus || isGeneratingFromAction) return;
    if (pollingRef.current) return;

    pollingRef.current = true;

    const poll = async () => {
      const status = await checkPlanStatus();
      if (status?.has_plan) {
        pollingRef.current = false;
        await loadPlan();
        return;
      }
      if (status?.error) {
        pollingRef.current = false;
        return;
      }
      timerRef.current = setTimeout(poll, 5000);
    };

    timerRef.current = setTimeout(poll, 5000);

    return () => {
      pollingRef.current = false;
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [api, isGeneratingFromStatus, isGeneratingFromAction]);

  if (!isVisible) return null;

  const label = generatingNext
    ? 'Генерация нового плана...'
    : recalculating
      ? 'Пересчёт плана...'
      : (planStatus?.job_type === 'next_plan'
        ? 'Генерация нового плана...'
        : planStatus?.job_type === 'recalculate'
          ? 'Пересчёт плана...'
          : 'Генерация плана...');

  return (
    <div className="plan-generating-global-banner">
      <span className="plan-generating-global-spinner" />
      <span>{label} Это займёт 3-5 минут.</span>
    </div>
  );
};

export default PlanGeneratingBanner;

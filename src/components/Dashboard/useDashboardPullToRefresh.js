import { useEffect, useRef, useState } from 'react';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';
import { isNativeCapacitor } from '../../services/TokenStorageService';

const PULL_THRESHOLD = 50;
const MAX_PULL = 100;

/** Лёгкий тактильный отклик на native при пересечении threshold и завершении refresh. */
async function fireHapticImpact() {
  if (!isNativeCapacitor()) return;
  try {
    const { Haptics, ImpactStyle } = await import('@capacitor/haptics');
    await Haptics.impact({ style: ImpactStyle.Medium });
  } catch {
    // Plugin missing or unavailable — fail silently
  }
}

async function fireHapticSuccess() {
  if (!isNativeCapacitor()) return;
  try {
    const { Haptics, NotificationType } = await import('@capacitor/haptics');
    await Haptics.notification({ type: NotificationType.Success });
  } catch {
    // Plugin missing or unavailable — fail silently
  }
}

export function useDashboardPullToRefresh(dashboardRef, loadDashboardData) {
  const [refreshing, setRefreshing] = useState(false);
  const [pullDistance, setPullDistance] = useState(0);
  const pullStartY = useRef(0);
  const isPulling = useRef(false);
  // Track whether threshold has been crossed in current gesture to fire haptic exactly once
  const crossedThreshold = useRef(false);

  useEffect(() => {
    const dashboard = dashboardRef.current;
    if (!dashboard) return;

    const handleTouchStart = (event) => {
      if (dashboard.scrollTop === 0) {
        pullStartY.current = event.touches[0].clientY;
        isPulling.current = true;
        crossedThreshold.current = false;
      }
    };

    const handleTouchMove = (event) => {
      if (!isPulling.current || !pullStartY.current) return;

      const currentY = event.touches[0].clientY;
      const deltaY = currentY - pullStartY.current;

      if (deltaY > 0 && dashboard.scrollTop === 0) {
        const distance = Math.min(deltaY, MAX_PULL);
        setPullDistance(distance);

        if (distance > 10) {
          event.preventDefault();
        }

        if (!crossedThreshold.current && distance >= PULL_THRESHOLD) {
          crossedThreshold.current = true;
          fireHapticImpact();
        }
      } else {
        setPullDistance(0);
        isPulling.current = false;
        crossedThreshold.current = false;
      }
    };

    const handleTouchEnd = async () => {
      if (pullDistance > PULL_THRESHOLD) {
        setRefreshing(true);
        try {
          await loadDashboardData();
          useWorkoutRefreshStore.getState().triggerRefresh();
          fireHapticSuccess();
        } finally {
          setRefreshing(false);
          setPullDistance(0);
        }
      } else {
        setPullDistance(0);
      }

      pullStartY.current = 0;
      isPulling.current = false;
      crossedThreshold.current = false;
    };

    dashboard.addEventListener('touchstart', handleTouchStart, { passive: true });
    dashboard.addEventListener('touchmove', handleTouchMove, { passive: false });
    dashboard.addEventListener('touchend', handleTouchEnd, { passive: true });

    return () => {
      dashboard.removeEventListener('touchstart', handleTouchStart);
      dashboard.removeEventListener('touchmove', handleTouchMove);
      dashboard.removeEventListener('touchend', handleTouchEnd);
    };
  }, [dashboardRef, loadDashboardData, pullDistance]);

  return {
    refreshing,
    pullDistance,
  };
}

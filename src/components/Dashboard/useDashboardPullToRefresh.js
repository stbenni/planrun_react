import { useEffect, useRef, useState } from 'react';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';

export function useDashboardPullToRefresh(dashboardRef, loadDashboardData) {
  const [refreshing, setRefreshing] = useState(false);
  const [pullDistance, setPullDistance] = useState(0);
  const pullStartY = useRef(0);
  const isPulling = useRef(false);

  useEffect(() => {
    const dashboard = dashboardRef.current;
    if (!dashboard) return;

    const handleTouchStart = (event) => {
      if (dashboard.scrollTop === 0) {
        pullStartY.current = event.touches[0].clientY;
        isPulling.current = true;
      }
    };

    const handleTouchMove = (event) => {
      if (!isPulling.current || !pullStartY.current) return;

      const currentY = event.touches[0].clientY;
      const deltaY = currentY - pullStartY.current;

      if (deltaY > 0 && dashboard.scrollTop === 0) {
        const maxPull = 100;
        const distance = Math.min(deltaY, maxPull);
        setPullDistance(distance);

        if (distance > 10) {
          event.preventDefault();
        }
      } else {
        setPullDistance(0);
        isPulling.current = false;
      }
    };

    const handleTouchEnd = async () => {
      if (pullDistance > 50) {
        setRefreshing(true);
        try {
          await loadDashboardData();
          useWorkoutRefreshStore.getState().triggerRefresh();
        } finally {
          setRefreshing(false);
          setPullDistance(0);
        }
      } else {
        setPullDistance(0);
      }

      pullStartY.current = 0;
      isPulling.current = false;
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

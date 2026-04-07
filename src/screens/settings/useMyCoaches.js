import { useCallback, useState } from 'react';

export function useMyCoaches(api, setMessage) {
  const [myCoaches, setMyCoaches] = useState([]);
  const [myCoachesLoading, setMyCoachesLoading] = useState(false);
  const [removingCoachId, setRemovingCoachId] = useState(null);

  const loadMyCoaches = useCallback(async () => {
    if (!api) return;
    setMyCoachesLoading(true);
    try {
      const res = await api.getMyCoaches();
      const data = res?.data ?? res;
      setMyCoaches(Array.isArray(data?.coaches) ? data.coaches : []);
    } catch (error) {
      void error;
    } finally {
      setMyCoachesLoading(false);
    }
  }, [api]);

  const handleRemoveCoach = useCallback(async (coachId) => {
    if (!api || !window.confirm('Отвязать тренера?')) return;
    setRemovingCoachId(coachId);
    try {
      await api.removeCoach({ coachId });
      setMyCoaches((prev) => prev.filter((coach) => coach.id !== coachId));
    } catch (error) {
      setMessage({ type: 'error', text: error.message || 'Ошибка' });
    } finally {
      setRemovingCoachId(null);
    }
  }, [api, setMessage]);

  return { myCoaches, myCoachesLoading, removingCoachId, loadMyCoaches, handleRemoveCoach };
}
